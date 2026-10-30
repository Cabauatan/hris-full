import test, { before, after, beforeEach, afterEach } from 'node:test'
import assert from 'node:assert/strict'
import { registerHooks } from 'node:module'
import { readFileSync } from 'node:fs'
import { webcrypto } from 'node:crypto'
import Echo from 'laravel-echo'

let Pusher
let interview
let account
let notifications
let previousGlobals
let socketCreated
const sockets = []
const settings = {}
const blockedNetwork = () => {
  throw new Error('The transport regression test must not use the network.')
}

// Keep the installed Echo and browser Pusher implementations. Replace only the
// network boundary so their actual transport selection and URL generation run.
class NetworkDisabledWebSocket {
  static CONNECTING = 0
  static OPEN = 1
  static CLOSING = 2
  static CLOSED = 3
  constructor(url) {
    this.url = url
    this.readyState = NetworkDisabledWebSocket.CONNECTING
    sockets.push(url)
    socketCreated?.(url)
  }
  send() {}
  close() {
    this.readyState = NetworkDisabledWebSocket.CLOSED
  }
}

before(async () => {
  const globals = {
    window: undefined,
    self: undefined,
    document: undefined,
    navigator: { onLine: true },
    fetch: blockedNetwork,
    __hrisTransportSettings: settings,
    __hrisTransportAuth: { authenticated: true, user: { id: 42 } },
    __hrisTransportHttp: { post: blockedNetwork },
    __hrisInstalledPusher: undefined,
  }
  previousGlobals = new Map(
    Object.keys(globals).map((name) => [name, Object.getOwnPropertyDescriptor(globalThis, name)]),
  )
  for (const [name, value] of Object.entries(globals))
    Object.defineProperty(globalThis, name, { configurable: true, writable: true, value })
  const location = { protocol: 'https:', hostname: 'portal.fixture.invalid' }
  const document = {
    location,
    body: {},
    querySelector: () => null,
    addEventListener() {},
    removeEventListener() {},
    createElement: () => ({ style: {} }),
    getElementsByTagName: () => [],
  }
  globalThis.document = document
  globalThis.window = {
    document,
    location,
    navigator: globalThis.navigator,
    crypto: webcrypto,
    WebSocket: NetworkDisabledWebSocket,
    XMLHttpRequest: blockedNetwork,
    fetch: blockedNetwork,
    JSON,
    console,
    setTimeout,
    clearTimeout,
    setInterval,
    clearInterval,
    addEventListener() {},
    removeEventListener() {},
    localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
  }
  globalThis.self = window
  ;({ default: Pusher } = await import('pusher-js/dist/web/pusher.js'))
  globalThis.__hrisInstalledPusher = Pusher
  const modules = {
    'pusher-js': 'export default globalThis.__hrisInstalledPusher',
    '@/stores/auth': 'export const useAuthStore = () => globalThis.__hrisTransportAuth',
    '@/api/http': 'export default globalThis.__hrisTransportHttp',
  }
  const hooks = registerHooks({
    resolve(specifier, context, nextResolve) {
      if (modules[specifier])
        return {
          url: `data:text/javascript,${encodeURIComponent(modules[specifier])}`,
          shortCircuit: true,
        }
      return nextResolve(specifier, context)
    },
    load(url, context, nextLoad) {
      if (/\/src\/realtime\/(interview|account|echo)\.js$/.test(url))
        return {
          format: 'module',
          shortCircuit: true,
          source: readFileSync(new URL(url), 'utf8').replaceAll(
            'import.meta.env',
            'globalThis.__hrisTransportSettings',
          ),
        }
      return nextLoad(url, context)
    },
  })
  try {
    interview = await import('../src/realtime/interview.js')
    account = await import('../src/realtime/account.js')
    notifications = await import('../src/realtime/echo.js')
  } finally {
    hooks.deregister()
  }
})

beforeEach(() => {
  sockets.length = 0
  Object.assign(settings, {
    VITE_REVERB_APP_KEY: 'public-transport-test-key',
    VITE_REVERB_HOST: 'socket.fixture.invalid',
    VITE_REVERB_PORT: '443',
    VITE_REVERB_SCHEME: 'https',
  })
  window.location.protocol = 'https:'
})

afterEach(() => {
  socketCreated = undefined
  notifications.disconnectRealtime()
  for (const client of Pusher.instances) client.disconnect()
})

after(() => {
  for (const [name, descriptor] of previousGlobals) {
    if (descriptor) Object.defineProperty(globalThis, name, descriptor)
    else delete globalThis[name]
  }
})

test('installed Pusher rejects the former wss-only transport configuration even with TLS', () => {
  const echo = new Echo({
    broadcaster: 'reverb',
    Pusher,
    key: settings.VITE_REVERB_APP_KEY,
    wsHost: settings.VITE_REVERB_HOST,
    wsPort: 443,
    wssPort: 443,
    forceTLS: true,
    enabledTransports: ['wss'],
  })
  try {
    assert.equal(echo.connector.pusher.connection.strategy.isSupported(), false)
    assert.equal(echo.connector.pusher.connection.state, 'failed')
    assert.deepEqual(sockets, [])
  } finally {
    echo.disconnect()
  }
})

const connectors = {
  interview: () => interview.connectInterview({ uuid: 'fixture-interview', scope: 'employee' }),
  'employee account': () =>
    account.connectAccountRealtime({ scope: 'employee', account: 42, isCurrent: () => true }),
  'applicant account': () =>
    account.connectAccountRealtime({ scope: 'applicant', account: 42, isCurrent: () => true }),
  notifications: () => {
    notifications.connectRealtime()
    return { close: () => notifications.disconnectRealtime() }
  },
}

for (const [name, connect] of Object.entries(connectors)) {
  for (const secure of [true, false]) {
    test(
      `${name} opens an actual ${secure ? 'WSS' : 'WS'} transport with installed Echo/Pusher`,
      { timeout: 5000 },
      async () => {
        if (!secure) {
          settings.VITE_REVERB_HOST = '192.168.100.51'
          settings.VITE_REVERB_PORT = '8082'
          settings.VITE_REVERB_SCHEME = 'http'
          window.location.protocol = 'http:'
        }
        const opened = new Promise((resolve) => {
          socketCreated = resolve
        })
        const connection = connect()
        try {
          const client = Pusher.instances.at(-1)
          assert.equal(client.connection.strategy.isSupported(), true)
          assert.equal(client.config.useTLS, secure)
          assert.equal(client.config.wsPort, secure ? 443 : 8082)
          assert.equal(client.config.wssPort, secure ? 443 : 8082)
          const url = await opened
          const prefix = secure
            ? 'wss://socket.fixture.invalid:443/app/'
            : 'ws://192.168.100.51:8082/app/'
          assert.ok(url.startsWith(prefix), url)
          assert.equal(new URL(url).pathname, '/app/public-transport-test-key')
          assert.deepEqual(sockets, [url])
        } finally {
          connection.close()
        }
      },
    )
  }
}
