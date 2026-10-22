import test, { before, after, beforeEach, afterEach } from 'node:test'
import assert from 'node:assert/strict'
import { registerHooks } from 'node:module'
import { setImmediate } from 'node:timers/promises'
import { createPinia, setActivePinia } from 'pinia'
import { AxiosError } from 'axios'

let moduleHooks
let httpModule
let useAuthStore
let auth
let calls
let previousWindow

function response(config, data = {}, status = 200) {
  return { data, status, statusText: String(status), headers: {}, config, request: {} }
}

function failResponse(config, status) {
  const result = response(config, { message: `Test HTTP ${status}` }, status)
  throw new AxiosError(
    `Test HTTP ${status}`,
    AxiosError.ERR_BAD_REQUEST,
    config,
    result.request,
    result,
  )
}

function adapter(handler) {
  httpModule.http.defaults.adapter = async (config) => {
    calls.push({ method: config.method, url: config.url, data: config.data })
    return handler(config)
  }
}

function requestPaths() {
  return calls.map(({ method, url }) => `${method.toUpperCase()} ${url}`)
}

function user(overrides = {}) {
  return {
    id: 12,
    name: 'Test employee',
    email: 'employee@example.test',
    is_active: true,
    employee: { id: 22, employee_number: 'TEST-22', first_name: 'Test', last_name: 'Employee' },
    permissions: ['cto.view', 'cto.create'],
    roles: ['employee'],
    ...overrides,
  }
}

before(async () => {
  moduleHooks = registerHooks({
    resolve(specifier, context, nextResolve) {
      if (specifier === '@/api/http' || specifier === '@/api/http.js') {
        return nextResolve(new URL('../src/api/http.js', import.meta.url).href, context)
      }
      return nextResolve(specifier, context)
    },
  })
  httpModule = await import('../src/api/http.js')
  ;({ useAuthStore } = await import('../src/stores/auth.js'))
  moduleHooks.deregister()
})

after(() => {
  moduleHooks?.deregister()
})

beforeEach(() => {
  previousWindow = globalThis.window
  globalThis.window = new EventTarget()
  calls = []
  httpModule.resetCsrf()
  setActivePinia(createPinia())
  auth = useAuthStore()
  adapter((config) => {
    throw new Error(`Unexpected request: ${config.method} ${config.url}`)
  })
})

afterEach(() => {
  auth?.$dispose()
  setActivePinia(undefined)
  httpModule.resetCsrf()
  if (previousWindow === undefined) delete globalThis.window
  else globalThis.window = previousWindow
})

test('concurrent mutations share one CSRF bootstrap and wait for it before sending', async () => {
  let releaseCsrf
  adapter((config) => {
    if (config.url === '/auth/csrf-cookie') {
      return new Promise((resolve) => {
        releaseCsrf = () => resolve(response(config, '', 204))
      })
    }
    return response(config, { data: { id: calls.length } })
  })

  const first = httpModule.http.post('/cto-requests', { reason: 'First request' })
  const second = httpModule.http.post('/overtime-requests', { reason: 'Second request' })
  await setImmediate()

  assert.deepEqual(requestPaths(), ['GET /auth/csrf-cookie'])
  releaseCsrf()
  await Promise.all([first, second])
  assert.deepEqual(requestPaths(), [
    'GET /auth/csrf-cookie',
    'POST /cto-requests',
    'POST /overtime-requests',
  ])
})

test('login and password changes rotate CSRF readiness while updating authenticated state', async () => {
  const signedIn = user()
  const updated = user({ name: 'Updated employee' })
  adapter((config) => {
    if (config.url === '/auth/csrf-cookie') return response(config, '', 204)
    if (config.url === '/auth/login') return response(config, { data: signedIn })
    if (config.url === '/auth/password') return response(config, { data: updated })
    if (config.url === '/cto-requests') return response(config, { data: { id: 1 } })
    throw new Error(`Unexpected URL ${config.url}`)
  })

  await auth.signIn({
    employee_number: 'TEST-22',
    password: 'Test-only-password',
    remember: false,
  })

  assert.equal(auth.authenticated, true)
  assert.equal(auth.initialized, true)
  assert.equal(auth.employee.id, 22)
  assert.equal(auth.can('cto.create'), true)
  assert.deepEqual(JSON.parse(calls[1].data), {
    employee_number: 'TEST-22',
    password: 'Test-only-password',
    remember: false,
  })
  await auth.changePassword({
    current_password: 'Test-only-password',
    password: 'Updated-test-password',
    password_confirmation: 'Updated-test-password',
  })
  assert.equal(auth.user.name, 'Updated employee')
  await httpModule.http.post('/cto-requests', { reason: 'Next write' })
  assert.deepEqual(requestPaths(), [
    'GET /auth/csrf-cookie',
    'POST /auth/login',
    'GET /auth/csrf-cookie',
    'PUT /auth/password',
    'GET /auth/csrf-cookie',
    'POST /cto-requests',
  ])
  assert.equal(httpModule.http.defaults.withCredentials, true)
  assert.equal(httpModule.http.defaults.withXSRFToken, true)
})

test('successful logout clears identity, employee and permissions and refreshes CSRF for the next write', async () => {
  auth.user = user()
  adapter((config) =>
    config.url === '/auth/csrf-cookie'
      ? response(config, '', 204)
      : response(config, { data: user() }),
  )

  await auth.signOut()

  assert.equal(auth.authenticated, false)
  assert.equal(auth.user, null)
  assert.equal(auth.employee, null)
  assert.deepEqual(auth.permissions, [])
  assert.deepEqual(auth.roles, [])
  assert.equal(auth.can('cto.create'), false)
  assert.equal(auth.initialized, true)
  await auth.signIn({ employee_number: 'TEST-22', password: 'Test-only-password' })
  assert.deepEqual(requestPaths(), [
    'GET /auth/csrf-cookie',
    'POST /auth/logout',
    'GET /auth/csrf-cookie',
    'POST /auth/login',
  ])
})

test('logout after server-side expiration still clears the local identity', async () => {
  auth.user = user()
  adapter((config) => {
    if (config.url === '/auth/csrf-cookie') return response(config, '', 204)
    return failResponse(config, 401)
  })

  await auth.signOut()

  assert.equal(auth.user, null)
  assert.equal(auth.authenticated, false)
  assert.deepEqual(requestPaths(), ['GET /auth/csrf-cookie', 'POST /auth/logout'])
})

test('network failure during logout does not pretend the server session was closed', async () => {
  auth.user = user()
  adapter((config) => {
    if (config.url === '/auth/csrf-cookie') return response(config, '', 204)
    throw new AxiosError('Test network failure', AxiosError.ERR_NETWORK, config)
  })

  await assert.rejects(auth.signOut(), { code: AxiosError.ERR_NETWORK })

  assert.equal(auth.authenticated, true)
  assert.equal(auth.user.id, 12)
})

test('401 emits the session-expired event and invalidates CSRF without replaying a request', async () => {
  let expired = 0
  window.addEventListener('hris:session-expired', () => {
    expired += 1
  })
  adapter((config) => {
    if (config.url === '/auth/csrf-cookie') return response(config, '', 204)
    if (config.url === '/employees') return failResponse(config, 401)
    return response(config, { data: { id: 1 } })
  })
  await httpModule.ensureCsrf()

  await assert.rejects(httpModule.http.get('/employees'), (error) => error.response?.status === 401)

  assert.equal(expired, 1)
  assert.deepEqual(requestPaths(), ['GET /auth/csrf-cookie', 'GET /employees'])
  await httpModule.http.post('/cto-requests', { reason: 'Explicit next action' })
  assert.deepEqual(requestPaths(), [
    'GET /auth/csrf-cookie',
    'GET /employees',
    'GET /auth/csrf-cookie',
    'POST /cto-requests',
  ])
})

test('419 resets CSRF but does not automatically repeat a failed mutation', async () => {
  let writes = 0
  adapter((config) => {
    if (config.url === '/auth/csrf-cookie') return response(config, '', 204)
    writes += 1
    if (writes === 1) return failResponse(config, 419)
    return response(config, { data: { id: 1 } })
  })

  await assert.rejects(
    httpModule.http.post('/cto-requests', { reason: 'Original request' }),
    (error) => error.response?.status === 419,
  )

  assert.equal(writes, 1)
  assert.deepEqual(requestPaths(), ['GET /auth/csrf-cookie', 'POST /cto-requests'])
  await httpModule.http.post('/cto-requests', { reason: 'User retries once' })
  assert.equal(writes, 2)
  assert.deepEqual(requestPaths(), [
    'GET /auth/csrf-cookie',
    'POST /cto-requests',
    'GET /auth/csrf-cookie',
    'POST /cto-requests',
  ])
})

test('CSRF bootstrap failure blocks the mutation and allows a later explicit retry', async () => {
  let bootstraps = 0
  adapter((config) => {
    if (config.url === '/auth/csrf-cookie') {
      bootstraps += 1
      if (bootstraps === 1)
        throw new AxiosError('Test CSRF network failure', AxiosError.ERR_NETWORK, config)
      return response(config, '', 204)
    }
    return response(config, { data: { id: 1 } })
  })

  await assert.rejects(httpModule.http.post('/cto-requests', { reason: 'First try' }), {
    code: AxiosError.ERR_NETWORK,
  })

  assert.deepEqual(requestPaths(), ['GET /auth/csrf-cookie'])
  await httpModule.http.post('/cto-requests', { reason: 'Explicit retry' })
  assert.deepEqual(requestPaths(), [
    'GET /auth/csrf-cookie',
    'GET /auth/csrf-cookie',
    'POST /cto-requests',
  ])
})

test('concurrent auth initialization shares the session lookup and caches its result', async () => {
  const signedIn = user()
  let releaseSession
  adapter(
    (config) =>
      new Promise((resolve) => {
        releaseSession = () => resolve(response(config, { data: signedIn }))
      }),
  )

  const first = auth.initialize()
  const second = auth.initialize()
  await setImmediate()

  assert.deepEqual(requestPaths(), ['GET /auth/me'])
  releaseSession()
  const results = await Promise.all([first, second])
  assert.deepEqual(results, [signedIn, signedIn])
  assert.equal(auth.authenticated, true)
  await auth.initialize()
  assert.deepEqual(requestPaths(), ['GET /auth/me'])
})

test('anonymous initialization keeps network errors separate and suppresses expiry events', async () => {
  let expired = 0
  window.addEventListener('hris:session-expired', () => {
    expired += 1
  })
  adapter((config) => failResponse(config, 401))

  assert.equal(await auth.initialize(), null)

  assert.equal(auth.authenticated, false)
  assert.equal(auth.initialized, true)
  assert.equal(auth.connectionError, '')
  assert.equal(expired, 0)
  assert.deepEqual(requestPaths(), ['GET /auth/me'])
})

test('failed session connectivity shows a connection error without an authenticated identity', async () => {
  adapter((config) => {
    throw new AxiosError('Test network failure', AxiosError.ERR_NETWORK, config)
  })

  assert.equal(await auth.initialize(), null)

  assert.equal(auth.user, null)
  assert.equal(auth.initialized, true)
  assert.equal(auth.authenticated, false)
  assert.match(auth.connectionError, /Unable to connect to HRIS/)
})

test('anonymous recovery clears the stale connectivity error from a failed initialization', async () => {
  adapter((config) => {
    throw new AxiosError('Test network failure', AxiosError.ERR_NETWORK, config)
  })
  await auth.initialize()
  assert.match(auth.connectionError, /Unable to connect to HRIS/)
  adapter((config) => failResponse(config, 401))

  assert.equal(await auth.initialize(true), null)

  assert.equal(auth.connectionError, '')
  assert.equal(auth.authenticated, false)
  assert.deepEqual(requestPaths(), ['GET /auth/me', 'GET /auth/me'])
})

test('permission helpers use the authenticated user and deny inactive identities', () => {
  auth.user = user()

  assert.equal(auth.can('cto.view'), true)
  assert.equal(auth.can('payroll.process'), false)
  assert.equal(auth.canAny(['payroll.process', 'cto.create']), true)
  auth.user = user({ is_active: false })
  assert.equal(auth.authenticated, false)
  assert.equal(auth.can('cto.view'), false)
  assert.equal(auth.canAny(['cto.view', 'cto.create']), false)
})

test('a delayed session lookup cannot restore identity after clearSession', async () => {
  let release
  adapter(
    (config) =>
      new Promise((resolve) => {
        release = () => resolve(response(config, { data: user() }))
      }),
  )
  const pending = auth.initialize()
  await setImmediate()
  auth.clearSession()
  release()
  await pending

  assert.equal(auth.user, null)
  assert.equal(auth.authenticated, false)
  assert.equal(auth.can('cto.view'), false)
})

test('a previous session lookup cannot replace a newly signed-in account', async () => {
  let release
  const next = user({ id: 99, permissions: ['leave.view'] })
  adapter((config) => {
    if (config.url === '/auth/me')
      return new Promise((resolve) => {
        release = () => resolve(response(config, { data: user() }))
      })
    if (config.url === '/auth/csrf-cookie') return response(config, '', 204)
    return response(config, { data: next })
  })
  const pending = auth.initialize()
  await setImmediate()
  await auth.signIn({ employee_number: next.employee.employee_number, password: 'Test-password' })
  release()
  await pending

  assert.equal(auth.user.id, 99)
  assert.deepEqual(auth.permissions, ['leave.view'])
})

test('a stale failed lookup cannot clear the latest forced initialization', async () => {
  let rejectOld
  let lookups = 0
  adapter((config) => {
    lookups += 1
    if (lookups === 1)
      return new Promise((resolve, reject) => {
        rejectOld = () =>
          reject(new AxiosError('Old lookup failed', AxiosError.ERR_NETWORK, config))
      })
    return response(config, { data: user({ id: 99 }) })
  })
  const old = auth.initialize()
  await setImmediate()
  auth.clearSession()
  const latest = auth.initialize(true)
  await setImmediate()
  const beforeRelease = lookups
  rejectOld()
  await Promise.all([old, latest])

  assert.equal(beforeRelease, 2)
  assert.equal(auth.user.id, 99)
  assert.equal(auth.connectionError, '')
})

test('an old account 401 cannot expire the newly signed-in session or its CSRF readiness', async () => {
  let expireOld
  let expired = 0
  window.addEventListener('hris:session-expired', () => {
    expired += 1
  })
  adapter((config) => {
    if (config.url === '/employees')
      return new Promise((resolve, reject) => {
        expireOld = () => {
          try {
            failResponse(config, 401)
          } catch (error) {
            reject(error)
          }
        }
      })
    if (config.url === '/auth/csrf-cookie') return response(config, '', 204)
    return response(config, { data: user({ id: 99 }) })
  })
  const old = httpModule.http.get('/employees')
  const rejection = assert.rejects(old, (error) => error.response?.status === 401)
  await setImmediate()
  await auth.signIn({ employee_number: 'TEST-99', password: 'Test-password' })
  await httpModule.ensureCsrf()
  const before = requestPaths().filter((path) => path === 'GET /auth/csrf-cookie').length
  expireOld()
  await rejection
  await httpModule.ensureCsrf()

  assert.equal(expired, 0)
  assert.equal(auth.user.id, 99)
  assert.equal(requestPaths().filter((path) => path === 'GET /auth/csrf-cookie').length, before)
})

test('employee takeover revalidation deduplicates requests and expires the replaced session', async () => {
  auth.user = user()
  let release
  let expired = 0
  window.addEventListener('hris:session-expired', () => expired++)
  adapter(
    (config) =>
      new Promise((resolve, reject) => {
        release = () => {
          try {
            failResponse(config, 401)
          } catch (failure) {
            reject(failure)
          }
        }
      }),
  )
  const first = auth.revalidateSession()
  const second = auth.revalidateSession()
  await setImmediate()
  assert.deepEqual(requestPaths(), ['GET /auth/me'])
  release()
  assert.deepEqual(await Promise.all([first, second]), [false, false])
  assert.equal(auth.authenticated, false)
  assert.equal(auth.can('cto.view'), false)
  assert.equal(expired, 1)
})

test('employee session revalidation keeps the current login on success or a temporary network failure', async () => {
  auth.user = user()
  let expired = 0
  window.addEventListener('hris:session-expired', () => expired++)
  adapter((config) => response(config, { data: user() }))
  assert.equal(await auth.revalidateSession(), true)
  adapter((config) => {
    throw new AxiosError('Offline', AxiosError.ERR_NETWORK, config)
  })
  assert.equal(await auth.revalidateSession(), false)
  assert.equal(auth.user.id, 12)
  assert.equal(auth.authenticated, true)
  assert.equal(expired, 0)
})

test('a late employee takeover revalidation cannot expire a fresh login for the same account', async () => {
  auth.user = user()
  let rejectPrevious
  let expired = 0
  window.addEventListener('hris:session-expired', () => expired++)
  adapter((config) => {
    if (config.url === '/auth/me')
      return new Promise((resolve, reject) => {
        rejectPrevious = () => {
          try {
            failResponse(config, 401)
          } catch (failure) {
            reject(failure)
          }
        }
      })
    if (config.url === '/auth/csrf-cookie') return response(config, '', 204)
    return response(config, { data: user() })
  })
  const old = auth.revalidateSession()
  await setImmediate()
  auth.clearSession()
  await auth.signIn({ employee_number: 'TEST-22', password: 'Test-password' })
  rejectPrevious()
  await old
  assert.equal(auth.user.id, 12)
  assert.equal(auth.authenticated, true)
  assert.equal(expired, 0)
})

test('a delayed password response cannot restore a cleared account', async () => {
  let release
  auth.user = user()
  adapter((config) => {
    if (config.url === '/auth/csrf-cookie') return response(config, '', 204)
    return new Promise((resolve) => {
      release = () => resolve(response(config, { data: user() }))
    })
  })
  const pending = auth.changePassword({ current_password: 'Old', password: 'New' })
  await setImmediate()
  auth.clearSession()
  release()
  await pending

  assert.equal(auth.user, null)
})

test('a delayed logout response cannot clear a newer signed-in account', async () => {
  let release
  auth.user = user()
  adapter((config) => {
    if (config.url === '/auth/csrf-cookie') return response(config, '', 204)
    if (config.url === '/auth/logout')
      return new Promise((resolve) => {
        release = () => resolve(response(config, '', 204))
      })
    return response(config, { data: user({ id: 99 }) })
  })
  const pending = auth.signOut()
  await setImmediate()
  await auth.signIn({ employee_number: 'TEST-99', password: 'Test-password' })
  release()
  await pending

  assert.equal(auth.user.id, 99)
})

test('session reset starts a fresh CSRF bootstrap and cancels an old queued mutation', async () => {
  const releases = []
  adapter((config) => {
    if (config.url === '/auth/csrf-cookie')
      return new Promise((resolve) => {
        releases.push(() => resolve(response(config, '', 204)))
      })
    return response(config, { data: { id: 1 } })
  })
  const old = httpModule.http.post('/cto-requests', { reason: 'Previous account' })
  const rejection = assert.rejects(old, { code: 'ERR_CANCELED' })
  await setImmediate()
  auth.clearSession()
  const current = httpModule.http.post('/leave-requests', { reason: 'Current session' })
  await setImmediate()
  const bootstraps = releases.length
  for (const release of releases) release()
  await Promise.all([rejection, current])

  assert.equal(bootstraps, 2)
  assert.equal(requestPaths().includes('POST /cto-requests'), false)
  assert.equal(requestPaths().includes('POST /leave-requests'), true)
})
