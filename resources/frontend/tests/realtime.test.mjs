import test, { before, after, beforeEach, afterEach } from 'node:test'
import assert from 'node:assert/strict'
import { registerHooks } from 'node:module'
import { readFileSync } from 'node:fs'
import { setImmediate } from 'node:timers/promises'
import { reactive } from 'vue'
import { mountComponent, settle } from './helpers/component-harness.mjs'

let realtime
let hooks
let auth
let connections
let calls
let handler
let previousWindow
const settings = {}
class FakeEcho {
  constructor(options) {
    this.options = options
    this.channels = []
    connections.push(this)
  }
  private(name) {
    const channel = {
      name,
      listeners: {},
      listen(event, callback) {
        this.listeners[event] = callback
        return this
      },
      subscribed(callback) {
        this.ready = callback
        return this
      },
      notification: (callback) => {
        channel.callback = callback
        return channel
      },
    }
    this.channels.push(channel)
    return channel
  }
  disconnect() {
    this.disconnected = true
  }
}

before(async () => {
  globalThis.__phase15RealtimeSettings = settings
  globalThis.__phase15Echo = FakeEcho
  globalThis.__phase15Auth = () => auth
  globalThis.__phase15Http = {
    post: (...args) => {
      calls.push(args)
      return handler(...args)
    },
  }
  const modules = {
    'laravel-echo': 'export default globalThis.__phase15Echo',
    'pusher-js': 'export default class Pusher {}',
    '@/stores/auth': 'export const useAuthStore = () => globalThis.__phase15Auth()',
    '@/api/http': 'export default globalThis.__phase15Http',
  }
  hooks = registerHooks({
    resolve(specifier, context, nextResolve) {
      if (modules[specifier])
        return {
          url: `data:text/javascript,${encodeURIComponent(modules[specifier])}`,
          shortCircuit: true,
        }
      return nextResolve(specifier, context)
    },
    load(url, context, nextLoad) {
      if (url.endsWith('/src/realtime/echo.js'))
        return {
          format: 'module',
          shortCircuit: true,
          source: readFileSync(new URL(url), 'utf8').replaceAll(
            'import.meta.env',
            'globalThis.__phase15RealtimeSettings',
          ),
        }
      return nextLoad(url, context)
    },
  })
  realtime = await import('../src/realtime/echo.js')
  hooks.deregister()
})
after(() => {
  hooks?.deregister()
  for (const key of [
    '__phase15RealtimeSettings',
    '__phase15Echo',
    '__phase15Auth',
    '__phase15Http',
  ])
    delete globalThis[key]
})
beforeEach(() => {
  previousWindow = globalThis.window
  globalThis.window = {}
  auth = {
    user: { id: 12, is_active: true },
    revalidations: 0,
    revalidateSession() {
      this.revalidations += 1
    },
    get authenticated() {
      return this.user?.is_active === true
    },
  }
  for (const key of Object.keys(settings)) delete settings[key]
  Object.assign(settings, { VITE_REVERB_APP_KEY: 'public-test-key' })
  connections = []
  calls = []
  handler = async () => ({ data: { auth: 'public-test-key:fixture-signature' } })
})
afterEach(() => {
  realtime.disconnectRealtime()
  if (previousWindow === undefined) delete globalThis.window
  else globalThis.window = previousWindow
})

test('anonymous accounts and missing configuration do not open websocket connections', () => {
  auth.user = null
  assert.equal(realtime.connectRealtime(), undefined)
  auth.user = { id: 12, is_active: true }
  settings.VITE_REVERB_APP_KEY = ''
  assert.equal(realtime.connectRealtime(), undefined)
  assert.equal(connections.length, 0)
})
test('active account connects once to port 8082 and subscribes only to its own notification channel', () => {
  let refreshes = 0
  const connection = realtime.connectRealtime(() => {
    refreshes += 1
  })
  assert.equal(realtime.connectRealtime(), connection)
  assert.equal(connections.length, 1)
  assert.equal(connection.options.wsPort, 8082)
  assert.equal(connection.options.wssPort, 8082)
  assert.equal(connection.options.wsHost, '127.0.0.1')
  assert.deepEqual(
    connection.channels.map((item) => item.name),
    ['App.Models.User.12'],
  )
  connection.channels[0].callback({ id: 'fixture' })
  assert.equal(refreshes, 1)
})
test('private authorization uses the shared CSRF HTTP client and passes the server signature to Echo', async () => {
  const connection = realtime.connectRealtime()
  const results = []
  connection.options
    .authorizer({ name: 'private-App.Models.User.12' })
    .authorize('123.456', (...result) => results.push(result))
  await setImmediate()
  assert.equal(calls[0][0], '/broadcasting/auth')
  assert.deepEqual(calls[0][1], {
    socket_id: '123.456',
    channel_name: 'private-App.Models.User.12',
  })
  assert.equal(calls[0][2].signal.aborted, false)
  assert.deepEqual(results, [[null, { auth: 'public-test-key:fixture-signature' }]])
})

test('session changes and reconnects revalidate the employee through its own server session', () => {
  const connection = realtime.connectRealtime()
  const channel = connection.channels[0]
  channel.listeners['.account.session.changed']({ guard: 'applicant' })
  assert.equal(auth.revalidations, 0)
  channel.listeners['.account.session.changed']({ guard: 'web' })
  channel.ready()
  assert.equal(auth.revalidations, 2)
  realtime.disconnectRealtime()
  channel.listeners['.account.session.changed']({ guard: 'web' })
  channel.ready()
  assert.equal(auth.revalidations, 2)
})

test('previous-account session events cannot revalidate a different current employee', () => {
  const connection = realtime.connectRealtime()
  auth.user = { id: 99, is_active: true }
  connection.channels[0].listeners['.account.session.changed']({ guard: 'web' })
  connection.channels[0].ready()
  assert.equal(auth.revalidations, 0)
})

test('websocket host follows the browser host unless explicitly configured', () => {
  window.location = { hostname: '192.0.2.10' }
  const browserHost = realtime.connectRealtime()
  assert.equal(browserHost.options.wsHost, '192.0.2.10')
  realtime.disconnectRealtime()

  settings.VITE_REVERB_HOST = 'reverb.example.test'
  const configuredHost = realtime.connectRealtime()
  assert.equal(configuredHost.options.wsHost, 'reverb.example.test')
})
test('disconnect aborts authorization and ignores late signatures and notifications', async () => {
  let release
  let refreshes = 0
  const results = []
  handler = () =>
    new Promise((resolve) => {
      release = () => resolve({ data: { auth: 'old-signature' } })
    })
  const connection = realtime.connectRealtime(() => {
    refreshes += 1
  })
  connection.options
    .authorizer({ name: 'private-App.Models.User.12' })
    .authorize('1.2', (...result) => results.push(result))
  realtime.disconnectRealtime()
  assert.equal(connection.disconnected, true)
  assert.equal(calls[0][2].signal.aborted, true)
  release()
  await setImmediate()
  connection.channels[0].callback({})
  assert.deepEqual(results, [])
  assert.equal(refreshes, 0)
})
test('switching accounts disconnects the old socket and ignores its late notification', () => {
  let oldRefreshes = 0
  let newRefreshes = 0
  const first = realtime.connectRealtime(() => {
    oldRefreshes += 1
  })
  auth.user = { id: 99, is_active: true }
  const second = realtime.connectRealtime(() => {
    newRefreshes += 1
  })
  first.channels[0].callback({})
  second.channels[0].callback({})
  assert.equal(first.disconnected, true)
  assert.equal(second.channels[0].name, 'App.Models.User.99')
  assert.equal(oldRefreshes, 0)
  assert.equal(newRefreshes, 1)
})
test('TLS configuration uses wss while invalid configured ports cannot open a connection', () => {
  settings.VITE_REVERB_SCHEME = 'https'
  settings.VITE_REVERB_PORT = '8443'
  const connection = realtime.connectRealtime()
  assert.equal(connection.options.forceTLS, true)
  assert.deepEqual(connection.options.enabledTransports, ['wss'])
  assert.equal(connection.options.wssPort, 8443)
  realtime.disconnectRealtime()
  settings.VITE_REVERB_PORT = '0'
  assert.equal(realtime.connectRealtime(), undefined)
  assert.equal(connections.length, 1)
})
test('authorization failure reaches Echo without accepting a signature', async () => {
  const error = new Error('Forbidden fixture channel')
  handler = async () => {
    throw error
  }
  const connection = realtime.connectRealtime()
  const results = []
  connection.options
    .authorizer({ name: 'private-App.Models.User.12' })
    .authorize('1.2', (...result) => results.push(result))
  await setImmediate()
  assert.deepEqual(results, [[error, null]])
})
test('the real app layout connects by account, refreshes the inbox and disconnects on logout and unmount', async () => {
  const previousDocument = globalThis.document
  globalThis.document = { documentElement: { classList: { contains: () => false } } }
  const account = reactive({
    user: { id: 12, name: 'Fixture' },
    roles: [],
    get authenticated() {
      return this.user !== null
    },
  })
  let starts = 0,
    stops = 0,
    connects = 0,
    disconnects = 0,
    refreshes = 0,
    loads = 0,
    notify
  const inbox = {
    countError: '',
    unreadCount: 0,
    startPolling: () => {
      starts += 1
    },
    stopPolling: () => {
      stops += 1
    },
    refreshUnreadCount: () => {
      refreshes += 1
    },
    load: () => {
      loads += 1
    },
  }
  let mounted
  try {
    mounted = await mountComponent('layout/AppLayout.vue', {
      'vue-router': {
        useRoute: () => ({ name: 'notifications', meta: {}, fullPath: '/notifications' }),
        useRouter: () => ({}),
      },
      '@/stores/auth': { useAuthStore: () => account },
      '@/stores/notifications': { useNotificationsStore: () => inbox },
      '@/stores/ui': { useUiStore: () => reactive({ dark: false, toggleTheme() {} }) },
      '@/config/navigation': { navigationGroups: () => [] },
      '@/realtime/echo': {
        connectRealtime: (callback) => {
          connects += 1
          notify = callback
        },
        disconnectRealtime: () => {
          disconnects += 1
        },
      },
    })
    assert.equal(starts, 1)
    assert.equal(connects, 1)
    notify()
    assert.equal(refreshes, 1)
    assert.equal(loads, 1)
    account.user = { id: 99, name: 'Next fixture' }
    await settle()
    assert.equal(connects, 2)
    account.user = null
    await settle()
    assert.equal(connects, 2)
    assert.equal(disconnects, 3)
  } finally {
    mounted?.app.unmount()
    if (previousDocument === undefined) delete globalThis.document
    else globalThis.document = previousDocument
  }
  assert.equal(stops, 1)
  assert.equal(disconnects, 4)
})

test('applicant layout revalidates on private session events, reconnect and visible return, then closes old connections', async () => {
  const oldDocument = globalThis.document
  const document = new EventTarget()
  document.visibilityState = 'hidden'
  globalThis.document = document
  const account = reactive({
    user: null,
    get authenticated() {
      return this.user?.is_active === true
    },
    revalidateSession() {
      checks += 1
    },
  })
  let checks = 0
  const connections = []
  let mounted
  try {
    mounted = await mountComponent('layout/ApplicantLayout.vue', {
      'vue-router': { useRouter: () => ({}) },
      '@/stores/applicant': { useApplicantStore: () => account },
      '@/stores/ui': { useUiStore: () => reactive({ dark: false }) },
      '@/realtime/account': {
        connectAccountRealtime(options) {
          const connection = {
            options,
            closed: false,
            close() {
              this.closed = true
            },
          }
          connections.push(connection)
          return connection
        },
      },
    })
    assert.equal(connections.length, 0)
    account.user = { id: 12, is_active: true }
    await settle()
    assert.equal(connections.length, 1)
    assert.equal(connections[0].options.scope, 'applicant')
    assert.equal(connections[0].options.account, 12)
    connections[0].options.onSessionChanged()
    connections[0].options.onReady()
    document.dispatchEvent(new Event('visibilitychange'))
    assert.equal(checks, 2)
    document.visibilityState = 'visible'
    document.dispatchEvent(new Event('visibilitychange'))
    assert.equal(checks, 3)
    account.user = { id: 99, is_active: true }
    await settle()
    assert.equal(connections[0].closed, true)
    assert.equal(connections[0].options.isCurrent(), false)
    assert.equal(connections[1].options.isCurrent(), true)
    account.user = null
    await settle()
    assert.equal(connections[1].closed, true)
  } finally {
    mounted?.app.unmount()
    if (oldDocument === undefined) delete globalThis.document
    else globalThis.document = oldDocument
  }
  document.dispatchEvent(new Event('visibilitychange'))
  assert.equal(checks, 3)
})
