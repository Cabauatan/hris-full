import test, { before, after, beforeEach, afterEach } from 'node:test'
import assert from 'node:assert/strict'
import { registerHooks } from 'node:module'
import { setImmediate } from 'node:timers/promises'
import { createPinia, setActivePinia } from 'pinia'
import { AxiosError } from 'axios'
import { deferred } from './helpers/component-harness.mjs'

let httpModule
let useAuthStore
let useApplicantStore
let useTelegramStore
let auth
let applicant
let telegram
let calls
let timers
let previous
let connections
const account = (id = 12) => ({ id, is_active: true, name: 'Test account' })
const status = (overrides = {}) => ({
  configured: true,
  bot_username: 'hris_test_bot',
  connected: false,
  linked_at: null,
  ...overrides,
})
const connectionLink = (overrides = {}) => ({
  url: `https://t.me/hris_test_bot?start=${'A'.repeat(43)}`,
  expires_at: '2030-01-01T00:10:00.000Z',
  ...overrides,
})
function response(config, value, code = 200) {
  return {
    config,
    data: { data: value },
    status: code,
    statusText: String(code),
    headers: {},
    request: {},
  }
}
function adapter(handler) {
  httpModule.http.defaults.adapter = async (config) => {
    if (config.url.endsWith('/csrf-cookie')) return response(config, null, 204)
    calls.push(config)
    return handler(config)
  }
}
async function settle() {
  await setImmediate()
  await setImmediate()
}
async function ready(scope = 'employee') {
  adapter((config) => response(config, status()))
  await telegram.load(scope)
  calls = []
}

before(async () => {
  globalThis.__telegramRealtimeBoundary = (options) => {
    const connection = {
      ...options,
      close() {
        this.closed = true
      },
    }
    connections.push(connection)
    return connection
  }
  const hook = registerHooks({
    resolve(specifier, context, nextResolve) {
      if (specifier === '@/realtime/telegram')
        return {
          url: `data:text/javascript,${encodeURIComponent('export const connectTelegramRealtime = (options) => globalThis.__telegramRealtimeBoundary(options)')}`,
          shortCircuit: true,
        }
      if (specifier.startsWith('@/'))
        return nextResolve(
          new URL(`../src/${specifier.slice(2)}.js`, import.meta.url).href,
          context,
        )
      return nextResolve(specifier, context)
    },
  })
  try {
    httpModule = await import('../src/api/http.js')
    ;({ useAuthStore } = await import('../src/stores/auth.js'))
    ;({ useApplicantStore } = await import('../src/stores/applicant.js'))
    ;({ useTelegramStore } = await import('../src/stores/telegram.js'))
  } finally {
    hook.deregister()
  }
})
after(() => delete globalThis.__telegramRealtimeBoundary)
beforeEach(() => {
  previous = {
    window: globalThis.window,
    now: Date.now,
    setTimeout: globalThis.setTimeout,
    clearTimeout: globalThis.clearTimeout,
  }
  globalThis.window = new EventTarget()
  Date.now = () => Date.parse('2030-01-01T00:00:00.000Z')
  timers = new Map()
  let timerId = 0
  globalThis.setTimeout = (callback, delay) => {
    const id = ++timerId
    timers.set(id, { callback, delay })
    return id
  }
  globalThis.clearTimeout = (id) => timers.delete(id)
  setActivePinia(createPinia())
  httpModule.resetAuthSession()
  httpModule.resetAuthSession('applicant')
  auth = useAuthStore()
  applicant = useApplicantStore()
  auth.user = account()
  applicant.user = account()
  telegram = useTelegramStore()
  calls = []
  connections = []
  adapter((config) => {
    throw new Error(`Unexpected ${config.method} ${config.url}`)
  })
})
afterEach(() => {
  telegram.$dispose()
  applicant.$dispose()
  auth.$dispose()
  setActivePinia(undefined)
  Date.now = previous.now
  globalThis.setTimeout = previous.setTimeout
  globalThis.clearTimeout = previous.clearTimeout
  if (previous.window === undefined) delete globalThis.window
  else globalThis.window = previous.window
})

test('same employee and applicant IDs load separate own-account Telegram endpoints and state', async () => {
  adapter((config) =>
    response(
      config,
      status({
        connected: config.url.startsWith('/applicant'),
        linked_at: config.url.startsWith('/applicant') ? '2029-12-31T12:00:00Z' : null,
      }),
    ),
  )

  await telegram.load('employee')
  await telegram.load('applicant')

  assert.deepEqual(
    calls.map(({ url, method }) => ({ url, method })),
    [
      { url: '/auth/telegram', method: 'get' },
      { url: '/applicant/auth/telegram', method: 'get' },
    ],
  )
  assert.equal(telegram.states.employee.status.connected, false)
  assert.equal(telegram.states.applicant.status.connected, true)
})

test('connect requests an own-account link with no account or chat IDs and waits for Start confirmation', async () => {
  await ready('applicant')
  adapter((config) => response(config, connectionLink(), 201))

  assert.equal(await telegram.connect('applicant'), true)

  assert.equal(calls[0].url, '/applicant/auth/telegram/link')
  assert.equal(calls[0].method, 'post')
  assert.equal(calls[0].data, undefined)
  assert.deepEqual(telegram.states.applicant.link, connectionLink())
  assert.equal(telegram.states.applicant.status.connected, false)
  assert.equal([...timers.values()][0].delay, 600000)
})

test('refresh after Start marks connected and removes the one-time link and its expiry timer', async () => {
  await ready()
  adapter((config) => response(config, connectionLink(), 201))
  await telegram.connect('employee')
  adapter((config) =>
    response(config, status({ connected: true, linked_at: '2030-01-01T00:01:00Z' })),
  )

  await telegram.load('employee')

  assert.equal(telegram.states.employee.status.connected, true)
  assert.equal(telegram.states.employee.link, null)
  assert.equal(timers.size, 0)
})

test('link expiry removes the URL and permits generating a fresh link', async () => {
  await ready()
  adapter((config) => response(config, connectionLink(), 201))
  await telegram.connect('employee')

  ;[...timers.values()][0].callback()

  assert.equal(telegram.states.employee.link, null)
  assert.equal(telegram.states.employee.linkExpired, true)
  assert.equal(await telegram.connect('employee'), true)
  assert.equal(telegram.states.employee.linkExpired, false)
})

test('logging out clears only that realm and discards an older response even after the same account signs in again', async () => {
  await ready('employee')
  await ready('applicant')
  adapter((config) => response(config, connectionLink(), 201))
  await telegram.connect('employee')
  await telegram.connect('applicant')
  const pending = deferred()
  adapter(() => pending.promise)
  const oldStatus = telegram.load('employee')
  await settle()
  const config = calls.at(-1)

  auth.clearSession()
  auth.user = account()
  pending.resolve(response(config, status({ connected: true })))
  await oldStatus

  assert.equal(telegram.states.employee.status, null)
  assert.equal(telegram.states.employee.link, null)
  assert.equal(telegram.states.employee.loading, false)
  assert.equal(telegram.states.applicant.link.url, connectionLink().url)
  assert.equal(timers.size, 1)
})

test('switching applicant accounts discards a pending link response without changing employee state', async () => {
  await ready('employee')
  await ready('applicant')
  const pending = deferred()
  adapter(() => pending.promise)
  const linking = telegram.connect('applicant')
  await settle()

  applicant.user = account(99)
  pending.resolve(response(calls[0], connectionLink(), 201))
  assert.equal(await linking, false)

  assert.equal(telegram.states.applicant.link, null)
  assert.equal(telegram.states.applicant.busy, false)
  assert.equal(telegram.states.applicant.status, null)
  assert.equal(telegram.states.employee.status.bot_username, 'hris_test_bot')
})

test('a newer refresh wins when an older connection check finishes later', async () => {
  const pending = deferred()
  let first
  adapter((config) => {
    if (!first) {
      first = config
      return pending.promise
    }
    return response(config, status({ connected: true }))
  })
  const oldStatus = telegram.load('employee')
  await settle()

  await telegram.load('employee')
  pending.resolve(response(first, status()))
  await oldStatus

  assert.equal(first.signal.aborted, true)
  assert.equal(telegram.states.employee.status.connected, true)
})

test('repeated connect clicks issue only one mutation while linking is in progress', async () => {
  await ready()
  const pending = deferred()
  adapter(() => pending.promise)
  const connecting = telegram.connect('employee')
  await settle()

  assert.equal(await telegram.connect('employee'), false)
  assert.equal(calls.length, 1)
  assert.equal(telegram.states.employee.busy, true)
  pending.resolve(response(calls[0], connectionLink(), 201))
  await connecting
  assert.equal(telegram.states.employee.busy, false)
})

for (const [description, url] of [
  ['script URL', 'javascript:alert(1)'],
  ['insecure URL', `http://t.me/hris_test_bot?start=${'A'.repeat(43)}`],
  ['different host', `https://t.me.evil.test/hris_test_bot?start=${'A'.repeat(43)}`],
  ['different bot', `https://t.me/different_bot?start=${'A'.repeat(43)}`],
  ['credentials', `https://private@t.me/hris_test_bot?start=${'A'.repeat(43)}`],
  ['extra query', `https://t.me/hris_test_bot?start=${'A'.repeat(43)}&redirect=evil`],
  ['duplicate nonce', `https://t.me/hris_test_bot?start=${'A'.repeat(43)}&start=${'B'.repeat(43)}`],
  ['fragment', `https://t.me/hris_test_bot?start=${'A'.repeat(43)}#private`],
  ['short nonce', 'https://t.me/hris_test_bot?start=short'],
]) {
  test(`connect refuses a ${description} returned as the Telegram link`, async () => {
    await ready()
    adapter((config) => response(config, connectionLink({ url }), 201))

    assert.equal(await telegram.connect('employee'), false)

    assert.equal(telegram.states.employee.link, null)
    assert.equal(timers.size, 0)
    assert.equal(
      telegram.states.employee.error,
      'Unable to open the Telegram connection link. Please try again.',
    )
  })
}

for (const expires_at of ['invalid', '2029-12-31T23:59:59Z']) {
  test(`connect refuses an expired or malformed link expiry (${expires_at})`, async () => {
    await ready()
    adapter((config) => response(config, connectionLink({ expires_at }), 201))

    assert.equal(await telegram.connect('employee'), false)

    assert.equal(telegram.states.employee.link, null)
    assert.equal(timers.size, 0)
  })
}

test('unlink revokes an own-account connection and removes pending link state', async () => {
  await ready()
  adapter((config) => response(config, connectionLink(), 201))
  await telegram.connect('employee')
  adapter((config) => response(config, null, 204))
  calls = []

  assert.equal(await telegram.disconnect('employee'), true)

  assert.equal(calls[0].url, '/auth/telegram')
  assert.equal(calls[0].method, 'delete')
  assert.equal(telegram.states.employee.status.connected, false)
  assert.equal(telegram.states.employee.status.linked_at, null)
  assert.equal(telegram.states.employee.link, null)
  assert.equal(timers.size, 0)
})

test('unlink failure retains the confirmed connection and exposes the retry message', async () => {
  adapter((config) => response(config, status({ connected: true })))
  await telegram.load('applicant')
  adapter((config) => {
    const result = response(config, null, 429)
    throw new AxiosError('Rejected', AxiosError.ERR_BAD_REQUEST, config, result.request, result)
  })

  assert.equal(await telegram.disconnect('applicant'), false)

  assert.equal(telegram.states.applicant.status.connected, true)
  assert.equal(telegram.states.applicant.busy, false)
  assert.equal(
    telegram.states.applicant.error,
    'Too many attempts. Please wait before trying again.',
  )
})

test('unconfigured, anonymous, and invalid scopes do not request connection links', async () => {
  adapter((config) => response(config, status({ configured: false, bot_username: null })))
  await telegram.load('employee')
  calls = []

  assert.equal(await telegram.connect('employee'), false)
  auth.clearSession()
  assert.equal(await telegram.load('employee'), false)
  assert.equal(await telegram.connect('applicant-id'), false)
  assert.equal(await telegram.disconnect('applicant-id'), false)
  assert.deepEqual(calls, [])
})

test('changing configured bots clears an older bot link and inactive accounts clear their state', async () => {
  await ready()
  adapter((config) => response(config, connectionLink(), 201))
  await telegram.connect('employee')
  adapter((config) => response(config, status({ bot_username: 'replacement_bot' })))

  await telegram.load('employee')

  assert.equal(telegram.states.employee.link, null)
  assert.equal(timers.size, 0)
  auth.user.is_active = false
  assert.equal(telegram.states.employee.status, null)
  assert.equal(telegram.identity('employee'), '')
})

test('disposing the store clears expiring links and aborts pending connection checks', async () => {
  await ready()
  adapter((config) => response(config, connectionLink(), 201))
  await telegram.connect('employee')
  const pending = deferred()
  adapter(() => pending.promise)
  const checking = telegram.load('employee')
  await settle()
  const config = calls.at(-1)

  telegram.$dispose()
  pending.resolve(response(config, status({ connected: true })))
  await checking

  assert.equal(timers.size, 0)
  assert.equal(config.signal.aborted, true)
  assert.equal(telegram.states.employee.link, null)
  assert.equal(telegram.states.employee.status, null)
})

for (const scope of ['employee', 'applicant']) {
  test(`${scope} realtime events fetch confirmed status and clear the private link after Telegram Start`, async () => {
    adapter((config) => response(config, status()))
    const stop = telegram.watchConnection(scope)
    await settle()
    adapter((config) => response(config, connectionLink(), 201))
    await telegram.connect(scope)
    const connection = connections[0]
    calls = []
    adapter((config) =>
      response(config, status({ connected: true, linked_at: '2030-01-01T00:01:00Z' })),
    )

    await connection.onChanged({ connected: false, bot_username: 'untrusted_bot' })

    assert.equal(connection.scope, scope)
    assert.equal(connection.account, '12')
    assert.equal(calls[0].url, `${scope === 'applicant' ? '/applicant' : ''}/auth/telegram`)
    assert.equal(telegram.states[scope].status.connected, true)
    assert.equal(telegram.states[scope].status.bot_username, 'hris_test_bot')
    assert.equal(telegram.states[scope].link, null)
    assert.equal(timers.size, 0)
    stop()
    assert.equal(connection.closed, true)
  })
}

test('subscription and reconnect refreshes recover a status change missed before the channel was ready', async () => {
  const pending = deferred()
  let first
  adapter((config) => {
    if (!first) {
      first = config
      return pending.promise
    }
    return response(config, status({ connected: true }))
  })
  telegram.watchConnection('employee')
  await settle()

  connections[0].onReady()
  pending.resolve(response(first, status()))
  await settle()

  assert.equal(calls.length, 2)
  assert.equal(telegram.states.employee.status.connected, true)
  adapter((config) => response(config, status()))
  await connections[0].onReady()
  assert.equal(calls.length, 3)
  assert.equal(telegram.states.employee.status.connected, false)
})

test('an event received while generating a link is deferred until the mutation completes', async () => {
  adapter((config) => response(config, status()))
  telegram.watchConnection('employee')
  await settle()
  const pending = deferred()
  adapter((config) =>
    config.method === 'post' ? pending.promise : response(config, status({ connected: true })),
  )
  calls = []
  const linking = telegram.connect('employee')
  await settle()

  await connections[0].onChanged()
  assert.equal(calls.length, 1)
  pending.resolve(response(calls[0], connectionLink(), 201))
  await linking
  await settle()

  assert.deepEqual(
    calls.map(({ method }) => method),
    ['post', 'get'],
  )
  assert.equal(telegram.states.employee.status.connected, true)
  assert.equal(telegram.states.employee.link, null)
  assert.equal(telegram.states.employee.busy, false)
})

test('events during a pending status check coalesce into one follow-up refresh', async () => {
  adapter((config) => response(config, status()))
  telegram.watchConnection('applicant')
  await settle()
  const pending = deferred()
  let first
  adapter((config) => {
    if (!first) {
      first = config
      return pending.promise
    }
    return response(config, status({ connected: true }))
  })
  calls = []
  const refreshing = connections[0].onChanged()
  await settle()

  connections[0].onChanged()
  connections[0].onChanged()
  connections[0].onReady()
  pending.resolve(response(first, status()))
  await refreshing

  assert.equal(calls.length, 2)
  assert.equal(telegram.states.applicant.status.connected, true)
})

test('closing a card connection aborts its background request and discards late events and status', async () => {
  const pending = deferred()
  adapter(() => pending.promise)
  const stop = telegram.watchConnection('employee')
  await settle()
  const connection = connections[0]
  const config = calls[0]

  stop()
  pending.resolve(response(config, status({ connected: true })))
  await settle()
  await connection.onChanged()
  await connection.onReady()

  assert.equal(connection.closed, true)
  assert.equal(connection.isCurrent(), false)
  assert.equal(config.signal.aborted, true)
  assert.equal(calls.length, 1)
  assert.equal(telegram.states.employee.status, null)
  assert.equal(telegram.states.employee.loading, false)
})

test('logout closes only its account connection and old same-ID callbacks cannot affect a new login', async () => {
  adapter((config) => response(config, status()))
  telegram.watchConnection('employee')
  telegram.watchConnection('applicant')
  await settle()
  const [employeeConnection, applicantConnection] = connections

  auth.clearSession()
  auth.user = account()
  calls = []
  await employeeConnection.onChanged()
  await employeeConnection.onReady()

  assert.equal(employeeConnection.closed, true)
  assert.equal(employeeConnection.isCurrent(), false)
  assert.equal(applicantConnection.isCurrent(), true)
  assert.equal(applicantConnection.closed, undefined)
  assert.equal(telegram.states.employee.status, null)
  assert.deepEqual(calls, [])
  telegram.watchConnection('employee')
  await settle()
  assert.equal(connections.length, 3)
  assert.equal(telegram.states.employee.status.connected, false)
})

test('inactive and invalid scopes never start Telegram realtime or status requests', async () => {
  auth.clearSession()

  telegram.watchConnection('employee')()
  telegram.watchConnection('invalid')()

  assert.deepEqual(connections, [])
  assert.deepEqual(calls, [])
})
