import test, { before, after, beforeEach, afterEach } from 'node:test'
import assert from 'node:assert/strict'
import { registerHooks } from 'node:module'
import { readFileSync } from 'node:fs'
import { setImmediate } from 'node:timers/promises'

let realtime
let echoes
let calls
let handler
let previousWindow
const settings = {}

class FakeEcho {
  constructor(options) {
    this.options = options
    this.channels = []
    echoes.push(this)
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
    }
    this.channels.push(channel)
    return channel
  }
  disconnect() {
    this.disconnected = true
  }
}

before(async () => {
  globalThis.__telegramRealtimeSettings = settings
  globalThis.__telegramEcho = FakeEcho
  globalThis.__telegramHttp = {
    post: (...args) => {
      calls.push(args)
      return handler(...args)
    },
  }
  const modules = {
    'laravel-echo': 'export default globalThis.__telegramEcho',
    'pusher-js': 'export default class Pusher {}',
    '@/api/http': 'export default globalThis.__telegramHttp',
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
      if (url.endsWith('/src/realtime/account.js'))
        return {
          format: 'module',
          shortCircuit: true,
          source: readFileSync(new URL(url), 'utf8').replaceAll(
            'import.meta.env',
            'globalThis.__telegramRealtimeSettings',
          ),
        }
      return nextLoad(url, context)
    },
  })
  try {
    realtime = await import('../src/realtime/telegram.js')
  } finally {
    hooks.deregister()
  }
})
after(() => {
  for (const name of ['__telegramRealtimeSettings', '__telegramEcho', '__telegramHttp'])
    delete globalThis[name]
})
beforeEach(() => {
  previousWindow = globalThis.window
  globalThis.window = {}
  for (const key of Object.keys(settings)) delete settings[key]
  Object.assign(settings, { VITE_REVERB_APP_KEY: 'public-telegram-test-key' })
  echoes = []
  calls = []
  handler = async () => ({ data: { auth: 'fixture-signature' } })
})
afterEach(() => {
  if (previousWindow === undefined) delete globalThis.window
  else globalThis.window = previousWindow
})

function connect(overrides = {}) {
  return realtime.connectTelegramRealtime({
    scope: 'employee',
    account: '12',
    isCurrent: () => true,
    onChanged() {},
    onReady() {},
    ...overrides,
  })
}

test('anonymous, invalid identities and missing realtime configuration do not open a socket', () => {
  for (const account of ['', '0', '-1', 'invalid', '1.5'])
    assert.equal(connect({ account }), undefined)
  assert.equal(connect({ scope: 'invalid' }), undefined)
  assert.equal(connect({ isCurrent: () => false }), undefined)
  settings.VITE_REVERB_APP_KEY = ''
  assert.equal(connect(), undefined)
  assert.deepEqual(echoes, [])
})

for (const [scope, channelName, endpoint] of [
  ['employee', 'App.Models.User.12', '/broadcasting/auth'],
  ['applicant', 'App.Models.Applicant.12', '/applicant/broadcasting/auth'],
]) {
  test(`${scope} subscribes to its own private channel using its scoped CSRF client`, async () => {
    connect({ scope })
    const echo = echoes[0]
    const channel = echo.channels[0]
    const results = []

    echo.options
      .authorizer({ name: `private-${channel.name}` })
      .authorize('123.456', (...args) => results.push(args))
    await setImmediate()

    assert.equal(channel.name, channelName)
    assert.equal(echo.options.broadcaster, 'reverb')
    assert.equal(echo.options.wsHost, '127.0.0.1')
    assert.equal(echo.options.wsPort, 8082)
    assert.equal(calls[0][0], endpoint)
    assert.deepEqual(calls[0][1], {
      socket_id: '123.456',
      channel_name: `private-${channelName}`,
    })
    assert.equal(calls[0][2].signal.aborted, false)
    assert.deepEqual(results, [[null, { auth: 'fixture-signature' }]])
  })
}

test('websocket host follows the browser host unless explicitly configured', () => {
  window.location = { hostname: '192.0.2.10' }
  const browserHost = connect()
  assert.equal(echoes[0].options.wsHost, '192.0.2.10')
  browserHost.close()

  settings.VITE_REVERB_HOST = 'reverb.example.test'
  const configuredHost = connect()
  assert.equal(echoes[1].options.wsHost, 'reverb.example.test')
  configuredHost.close()
})

test('status changes and repeated subscription success request refreshes without trusting event data', () => {
  const changed = []
  let ready = 0
  connect({
    onChanged: (...args) => changed.push(args),
    onReady: () => {
      ready += 1
    },
  })
  const channel = echoes[0].channels[0]

  channel.listeners['.telegram.connection.updated']({ connected: true, chat_id: 'untrusted' })
  channel.ready()
  channel.ready()

  assert.deepEqual(changed, [[]])
  assert.equal(ready, 2)
})

for (const [scope, guard] of [
  ['employee', 'web'],
  ['applicant', 'applicant'],
]) {
  test(`${scope} session events revalidate only their matching realm and ignore closed connections`, () => {
    let checks = 0
    const connection = connect({ scope, onSessionChanged: () => checks++ })
    const channel = echoes[0].channels[0]
    channel.listeners['.account.session.changed']({ guard: guard === 'web' ? 'applicant' : 'web' })
    assert.equal(checks, 0)
    channel.listeners['.account.session.changed']({ guard, active: false })
    assert.equal(checks, 1)
    connection.close()
    channel.listeners['.account.session.changed']({ guard })
    assert.equal(checks, 1)
  })
}

test('closing aborts authorization and ignores late signatures, events and subscription callbacks', async () => {
  let release
  let changed = 0
  let ready = 0
  handler = () =>
    new Promise((resolve) => {
      release = resolve
    })
  const connection = connect({
    onChanged: () => {
      changed += 1
    },
    onReady: () => {
      ready += 1
    },
  })
  const echo = echoes[0]
  const results = []
  const authorize = echo.options.authorizer({ name: 'private-App.Models.User.12' })
  authorize.authorize('1.2', (...args) => results.push(args))

  connection.close()
  release({ data: { auth: 'expired-signature' } })
  await setImmediate()
  echo.channels[0].listeners['.telegram.connection.updated']({})
  echo.channels[0].ready()
  authorize.authorize('3.4', (...args) => results.push(args))

  assert.equal(echo.disconnected, true)
  assert.equal(calls[0][2].signal.aborted, true)
  assert.equal(calls.length, 1)
  assert.deepEqual(results, [])
  assert.equal(changed, 0)
  assert.equal(ready, 0)
})

test('changing the current account invalidates callbacks even before the old socket closes', async () => {
  let active = true
  let changed = 0
  let release
  handler = () =>
    new Promise((resolve) => {
      release = resolve
    })
  connect({
    isCurrent: () => active,
    onChanged: () => {
      changed += 1
    },
  })
  const echo = echoes[0]
  const results = []
  const authorizer = echo.options.authorizer({ name: 'private-App.Models.User.12' })
  authorizer.authorize('1.2', (...args) => results.push(args))

  active = false
  release({ data: { auth: 'previous-account-signature' } })
  await setImmediate()
  echo.channels[0].listeners['.telegram.connection.updated']({})
  authorizer.authorize('3.4', (...args) => results.push(args))

  assert.equal(calls.length, 1)
  assert.equal(changed, 0)
  assert.deepEqual(results, [])
})

test('failed channel authorization reaches Echo without accepting a signature', async () => {
  const failure = new Error('Forbidden channel')
  handler = async () => {
    throw failure
  }
  connect()
  const results = []

  echoes[0].options
    .authorizer({ name: 'private-App.Models.User.12' })
    .authorize('1.2', (...args) => results.push(args))
  await setImmediate()

  assert.deepEqual(results, [[failure, null]])
})

test('TLS uses only wss and invalid ports never create a connection', () => {
  settings.VITE_REVERB_SCHEME = 'https'
  settings.VITE_REVERB_PORT = '8443'
  connect()

  assert.equal(echoes[0].options.forceTLS, true)
  assert.deepEqual(echoes[0].options.enabledTransports, ['wss'])
  assert.equal(echoes[0].options.wssPort, 8443)
  settings.VITE_REVERB_PORT = '0'
  assert.equal(connect(), undefined)
  assert.equal(echoes.length, 1)
})
