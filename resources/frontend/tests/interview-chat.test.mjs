import test, { before, after, beforeEach, afterEach } from 'node:test'
import assert from 'node:assert/strict'
import { registerHooks } from 'node:module'
import { readFileSync } from 'node:fs'
import { setImmediate } from 'node:timers/promises'
import * as vue from 'vue'
import { reactive } from 'vue'
import { renderToString } from 'vue/server-renderer'
import { compileScript, parse } from '@vue/compiler-sfc'
import { createPinia, setActivePinia } from 'pinia'
import { AxiosError } from 'axios'
import { evaluateModule, mountComponent } from './helpers/component-harness.mjs'

let httpModule, realtime, useInterviewChatStore, store
let echoes, calls, handler, intervals, savedInterval, savedClearInterval
let autoReady
const uuid = 'ef21cc21-2829-4fd5-9789-36d6822c2b5f'
const room = { id: 42, uuid, abilities: { join: true } }

class FakeEcho {
  constructor(options) {
    this.options = options
    this.channels = []
    this.connectionHandlers = {}
    this.connector = {
      pusher: {
        connection: { bind: (name, callback) => (this.connectionHandlers[name] = callback) },
      },
    }
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
        if (autoReady) callback()
        return this
      },
      error(callback) {
        this.failed = callback
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

function message(id, body = `Message ${id}`, kind = 'applicant', interview_uuid = uuid) {
  return {
    id,
    interview_uuid,
    body,
    client_message_id: `5b421ac2-2044-488f-af63-${String(id).padStart(12, '0')}`,
    sender: { kind, id: 7, key: `${kind}:7`, name: kind === 'user' ? 'Test HR' : 'Test Applicant' },
    created_at: '2026-10-29T08:00:00.000000Z',
  }
}
function response(config, data = {}, status = 200) {
  return { config, data, status, statusText: String(status), headers: {}, request: {} }
}
function page(config, values = [], meta = {}) {
  return response(config, { data: values, meta: { has_more: false, can_send: true, ...meta } })
}
function deny(config, status) {
  const result = response(config, { message: `Test HTTP ${status}` }, status)
  throw new AxiosError('Test request failed', AxiosError.ERR_BAD_REQUEST, config, {}, result)
}
function deferred() {
  let resolve, reject
  const promise = new Promise((yes, no) => {
    resolve = yes
    reject = no
  })
  return { promise, resolve, reject }
}
async function settle() {
  await setImmediate()
  await setImmediate()
}
function requests(method = 'get') {
  return calls.filter((item) => item.url.endsWith('/messages') && item.method === method)
}

before(async () => {
  globalThis.__chatEcho = FakeEcho
  const stubs = {
    'laravel-echo': 'export default globalThis.__chatEcho',
    'pusher-js': 'export default class Pusher {}',
    '@/stores/auth': 'export const useAuthStore = () => globalThis.__chatAuth',
    '@/stores/applicant': 'export const useApplicantStore = () => globalThis.__chatApplicant',
  }
  const hooks = registerHooks({
    resolve(specifier, context, nextResolve) {
      if (stubs[specifier])
        return {
          url: `data:text/javascript,${encodeURIComponent(stubs[specifier])}`,
          shortCircuit: true,
        }
      if (specifier.startsWith('@/'))
        return nextResolve(
          new URL(
            `../src/${specifier.slice(2)}${specifier.endsWith('.js') ? '' : '.js'}`,
            import.meta.url,
          ).href,
          context,
        )
      return nextResolve(specifier, context)
    },
    load(url, context, nextLoad) {
      if (url.endsWith('/src/realtime/interview.js'))
        return {
          format: 'module',
          shortCircuit: true,
          source: readFileSync(new URL(url), 'utf8').replaceAll(
            'import.meta.env',
            '({ VITE_REVERB_APP_KEY: "public-chat-test-key" })',
          ),
        }
      return nextLoad(url, context)
    },
  })
  try {
    httpModule = await import('../src/api/http.js')
    realtime = await import('../src/realtime/interview.js')
    ;({ useInterviewChatStore } = await import('../src/stores/interviewChat.js'))
  } finally {
    hooks.deregister()
  }
})

beforeEach(() => {
  globalThis.__chatAuth = reactive({ authenticated: true, user: { id: 7 } })
  globalThis.__chatApplicant = reactive({ authenticated: true, user: { id: 7 } })
  echoes = []
  autoReady = false
  calls = []
  intervals = new Map()
  savedInterval = globalThis.setInterval
  savedClearInterval = globalThis.clearInterval
  globalThis.setInterval = (callback) => {
    const id = Symbol('poll')
    intervals.set(id, callback)
    return id
  }
  globalThis.clearInterval = (id) => intervals.delete(id)
  httpModule.resetCsrf()
  handler = (config) => {
    if (config.url.endsWith('/csrf-cookie')) return response(config, '', 204)
    if (config.url.endsWith('/messages') && config.method === 'get') return page(config)
    if (config.url.endsWith('/broadcasting/auth'))
      return response(config, { auth: 'test-signature' })
    if (config.url.endsWith('/messages') && config.method === 'post') {
      const input = JSON.parse(config.data)
      return response(config, { data: { ...message(1, input.body, 'user'), ...input } }, 201)
    }
    throw new Error(`Unexpected request: ${config.url}`)
  }
  httpModule.http.defaults.adapter = async (config) => {
    calls.push(config)
    return handler(config)
  }
  setActivePinia(createPinia())
  store = useInterviewChatStore()
})

afterEach(() => {
  store.reset()
  store.$dispose()
  setActivePinia(undefined)
  httpModule.resetCsrf()
  globalThis.setInterval = savedInterval
  globalThis.clearInterval = savedClearInterval
})
after(() => {
  for (const key of ['__chatEcho', '__chatAuth', '__chatApplicant']) delete globalThis[key]
})

for (const scope of ['user', 'applicant']) {
  test(`${scope} chat loads its scoped history without requesting camera or microphone`, async () => {
    assert.equal(await store.open(scope, room), true)
    assert.equal(
      requests()[0].url,
      `/${scope === 'user' ? 'recruitment' : 'applicant'}/interviews/42/messages`,
    )
    assert.equal(store.ownKey, `${scope}:7`)
    assert.equal(store.canSend, true)
    assert.equal(echoes.length, 1)
    assert.equal(
      calls.some(({ url }) => /\/(join|signal)$/.test(url)),
      false,
    )
    assert.equal(intervals.size, 1)
  })
}

test('anonymous and invalid identities or room references never request private history', async () => {
  globalThis.__chatAuth.authenticated = false
  assert.equal(await store.open('user', room), false)
  globalThis.__chatAuth.authenticated = true
  globalThis.__chatAuth.user = { id: -1 }
  assert.equal(await store.open('user', room), false)
  globalThis.__chatAuth.user = { id: 7 }
  assert.equal(await store.open('user', { ...room, id: 0 }), false)
  assert.equal(await store.open('user', { ...room, uuid: '' }), false)
  assert.equal(await store.open('outsider', room), false)

  assert.deepEqual(requests(), [])
  assert.deepEqual(echoes, [])
})

test('completed interviews show their transcript without opening a room connection or allowing sends', async () => {
  handler = (config) => page(config, [message(2)], { can_send: false })
  await store.open('user', { ...room, abilities: { join: false } })

  assert.equal(store.messages[0].body, 'Message 2')
  assert.equal(store.canSend, false)
  assert.deepEqual(echoes, [])
  store.draft = 'Cannot send'
  assert.equal(await store.send(), false)
  assert.equal(requests('post').length, 0)
})

test('out-of-order invalidations fetch authorized text, preserve order and never render injected event content', async () => {
  const original = handler
  let values = [message(5), message(6)]
  handler = (config) => (config.url.endsWith('/messages') ? page(config, values) : original(config))
  await store.open('user', room)
  const channel = echoes[0].channels[0]
  values = [message(7), message(8)]
  channel.listeners['.interview.message']({ interview_uuid: uuid, message_id: 8 })
  channel.listeners['.interview.message']({ interview_uuid: uuid, message_id: 7 })
  channel.listeners['.interview.message']({ interview_uuid: uuid, message_id: 6 })
  channel.listeners['.interview.message']({ interview_uuid: 'foreign', message_id: 20 })
  channel.listeners['.interview.message']({
    interview_uuid: uuid,
    message_id: 21,
    message: message(21, 'Injected text that was never authorized'),
  })
  await settle()

  assert.deepEqual(
    store.messages.map(({ id }) => id),
    [5, 6, 7, 8],
  )
  assert.equal(requests().at(-1).params.after_id, 6)
})

test('after-cursor catchup fills every missing page rather than advancing to a later broadcast ID', async () => {
  let initial = true
  handler = (config) => {
    if (initial) {
      initial = false
      return page(config, [message(5)])
    }
    return config.params.after_id === 5
      ? page(config, [message(6), message(7)], { has_more: true })
      : page(config, [message(8), message(9)])
  }
  await store.open('user', room)
  echoes[0].channels[0].listeners['.interview.message']({
    interview_uuid: uuid,
    message_id: 9,
  })
  await settle()

  assert.deepEqual(
    requests()
      .slice(1)
      .map(({ params }) => params.after_id),
    [5, 7],
  )
  assert.deepEqual(
    store.messages.map(({ id }) => id),
    [5, 6, 7, 8, 9],
  )
})

test('a subscription ready during the initial load catches the snapshot gap immediately', async () => {
  autoReady = true
  handler = (config) => page(config, config.params ? [message(6)] : [message(5)])
  await store.open('user', room)
  await settle()

  assert.equal(requests()[1].params.after_id, 5)
  assert.deepEqual(
    store.messages.map(({ id }) => id),
    [5, 6],
  )
  assert.equal(store.live, true)
})

test('an invalidation arriving during a final-page snapshot schedules another immediate authorized fetch', async () => {
  const pending = deferred()
  let waiting = true
  handler = (config) => {
    if (!config.params) return page(config, [message(5)])
    if (waiting) return pending.promise
    return page(config, [message(7)])
  }
  await store.open('user', room)
  const channel = echoes[0].channels[0]
  channel.listeners['.interview.message']({ interview_uuid: uuid, message_id: 6 })
  await settle()
  channel.listeners['.interview.message']({ interview_uuid: uuid, message_id: 7 })
  waiting = false
  pending.resolve(page(requests()[1], [message(6)]))
  await settle()

  assert.deepEqual(
    requests()
      .slice(1)
      .map(({ params }) => params.after_id),
    [5, 6],
  )
  assert.deepEqual(
    store.messages.map(({ id }) => id),
    [5, 6, 7],
  )
})

test('earlier history uses its own cursor and never resets the new-message catchup watermark', async () => {
  handler = (config) => {
    if (config.params?.before_id) return page(config, [message(3), message(4)])
    if (config.params?.after_id !== undefined) return page(config, [message(7)])
    return page(config, [message(5), message(6)], { has_more: true })
  }
  await store.open('user', room)
  assert.equal(await store.earlier(), true)
  await store.refresh()

  assert.deepEqual(
    requests().map(({ params }) => params || {}),
    [{}, { before_id: 5 }, { after_id: 6 }],
  )
  assert.deepEqual(
    store.messages.map(({ id }) => id),
    [3, 4, 5, 6, 7],
  )
  assert.equal(store.hasEarlier, false)
})

test('retrying an uncertain send reuses its UUID and the server reply cannot erase a newer draft', async () => {
  const original = handler
  let attempts = 0
  const pending = deferred()
  handler = (config) => {
    if (config.url.endsWith('/messages') && config.method === 'post') {
      attempts += 1
      if (attempts === 1) return deny(config, 503)
      return pending.promise
    }
    return original(config)
  }
  await store.open('user', room)
  store.draft = '  Original message  '
  assert.equal(await store.send(), false)
  const first = JSON.parse(requests('post')[0].data)
  const retry = store.send()
  await settle()
  store.draft = 'A newer draft'
  const config = requests('post')[1]
  const input = JSON.parse(config.data)
  pending.resolve(response(config, { data: { ...message(4, input.body, 'user'), ...input } }))
  assert.equal(await retry, true)

  assert.match(first.client_message_id, /^[0-9a-f-]{36}$/)
  assert.deepEqual(input, first)
  assert.equal(input.body, 'Original message')
  assert.equal(store.draft, 'A newer draft')
  assert.equal(store.messages.length, 1)
})

test('blank or oversized UTF-8 messages never send a request', async () => {
  await store.open('user', room)
  for (const text of [' \n\t ', '😀'.repeat(501), 'a'.repeat(2001)]) {
    store.draft = text
    assert.equal(await store.send(), false)
  }
  assert.equal(requests('post').length, 0)
})

test('an initial connection failure keeps polling and recovers history without losing the draft', async () => {
  handler = (config) => deny(config, 503)
  assert.equal(await store.open('user', room), false)
  store.draft = 'Draft preserved'
  assert.equal(intervals.size, 1)
  handler = (config) => page(config, [message(3)])
  await [...intervals.values()][0]()

  assert.equal(store.error, '')
  assert.equal(store.messages[0].id, 3)
  assert.equal(store.draft, 'Draft preserved')
  assert.equal(store.canSend, true)
})

test('a room closing stops realtime and sends while retaining its authorized transcript', async () => {
  await store.open('user', room)
  const echo = echoes[0]
  handler = (config) => page(config, [message(3)], { can_send: false })
  echo.channels[0].listeners['.interview.ended']({ interview_uuid: uuid })
  await settle()

  assert.equal(echo.disconnected, true)
  assert.equal(store.live, false)
  assert.equal(store.canSend, false)
  assert.equal(store.messages[0].id, 3)
})

test('a history response captured before interview end cannot reopen sending or its socket', async () => {
  const pending = deferred()
  let waiting = false
  handler = (config) => (waiting ? pending.promise : page(config, [message(5)]))
  await store.open('user', room)
  waiting = true
  const refresh = store.refresh()
  await settle()
  echoes[0].channels[0].listeners['.interview.ended']({ interview_uuid: uuid })
  waiting = false
  pending.resolve(page(requests()[1], [message(6)], { can_send: true }))
  await refresh

  assert.equal(store.canSend, false)
  assert.equal(echoes.length, 1)
  assert.equal(echoes[0].disconnected, true)
})

test('revoked history access clears private messages, draft, socket and polling', async () => {
  handler = (config) => page(config, [message(3)])
  await store.open('user', room)
  store.draft = 'Private draft'
  handler = (config) => deny(config, 403)
  echoes[0].channels[0].listeners['.interview.message']({
    interview_uuid: uuid,
    message_id: 4,
    message: message(4, 'Cannot be accepted from a stale socket'),
  })
  await settle()

  assert.deepEqual(store.messages, [])
  assert.equal(store.draft, '')
  assert.equal(store.canSend, false)
  assert.equal(echoes[0].disconnected, true)
  assert.equal(intervals.size, 0)
  assert.match(store.error, /access/i)
})

test('an account change in the other realm cannot close chat, while the active account logout clears it', async () => {
  await store.open('applicant', room)
  globalThis.__chatAuth.user = { id: 8 }
  assert.equal(store.canSend, true)
  globalThis.__chatApplicant.authenticated = false

  assert.equal(store.ownKey, '')
  assert.equal(echoes[0].disconnected, true)
  assert.equal(intervals.size, 0)
})

test('late history cannot restore a replaced room', async () => {
  const pending = deferred()
  handler = () => pending.promise
  const old = store.open('user', room)
  await settle()
  const config = requests()[0]
  handler = (config) => page(config, [message(2, 'New room', 'applicant', 'new-room')])
  await store.open('user', { ...room, id: 43, uuid: 'new-room' })
  pending.resolve(page(config, [message(99)]))
  await old

  assert.equal(config.signal.aborted, true)
  assert.deepEqual(
    store.messages.map(({ body }) => body),
    ['New room'],
  )
  assert.equal(requests().at(-1).url, '/recruitment/interviews/43/messages')
})

test('a late send and captured old socket event cannot change the replacement room or its draft', async () => {
  const original = handler
  const pending = deferred()
  handler = (config) =>
    config.method === 'post' && config.url.endsWith('/messages')
      ? pending.promise
      : original(config)
  await store.open('user', room)
  const oldEcho = echoes[0]
  store.draft = 'Old room message'
  const sending = store.send()
  await settle()
  const config = requests('post')[0]
  handler = (config) => page(config, [message(2, 'New room text', 'applicant', 'new-room')])
  await store.open('user', { ...room, id: 43, uuid: 'new-room' })
  store.draft = 'New room draft'
  const before = requests().length
  oldEcho.channels[0].listeners['.interview.message']({ interview_uuid: uuid, message_id: 99 })
  pending.resolve(response(config, { data: message(99, 'Old room response') }))

  assert.equal(await sending, false)
  assert.equal(config.signal.aborted, true)
  assert.equal(requests().length, before)
  assert.deepEqual(
    store.messages.map(({ body }) => body),
    ['New room text'],
  )
  assert.equal(store.draft, 'New room draft')
})

test('an own POST response does not skip intervening participant messages during catchup', async () => {
  const original = handler
  handler = (config) => {
    if (config.url.endsWith('/messages')) {
      if (config.method === 'post') {
        const input = JSON.parse(config.data)
        return response(config, { data: { ...message(9, input.body, 'user'), ...input } })
      }
      return page(
        config,
        config.params?.after_id === 5
          ? [message(6), message(7), message(8), message(9)]
          : [message(5)],
      )
    }
    return original(config)
  }
  await store.open('user', room)
  store.draft = 'Own message'
  await store.send()
  await settle()

  assert.equal(requests()[1].params.after_id, 5)
  assert.deepEqual(
    store.messages.map(({ id }) => id),
    [5, 6, 7, 8, 9],
  )
})

test('polling notices the room opening and restores realtime after a connection interruption', async () => {
  let open = false
  handler = (config) => page(config, [], { can_send: open })
  await store.open('user', { ...room, abilities: { join: false } })
  assert.equal(echoes.length, 0)
  open = true
  await [...intervals.values()][0]()
  const first = echoes[0]
  first.channels[0].ready()
  await settle()
  assert.equal(store.live, true)
  first.channels[0].failed(new Error('Connection interrupted'))
  assert.equal(first.disconnected, true)
  assert.equal(store.live, false)
  await [...intervals.values()][0]()
  assert.equal(echoes.length, 2)
  assert.equal(store.canSend, true)
})

test('message-only room connections ignore video signals and stop dispatching messages after close', () => {
  const received = []
  const connection = realtime.connectInterview({
    uuid,
    scope: 'user',
    onMessage: (value) => received.push(value),
  })
  const channel = echoes[0].channels[0]
  channel.listeners['.interview.signal']({ type: 'ready' })
  channel.listeners['.interview.message']({ interview_uuid: uuid, message: message(1) })
  connection.close()
  channel.listeners['.interview.message']({ interview_uuid: uuid, message: message(2) })

  assert.equal(received.length, 1)
  assert.equal(received[0].message.id, 1)
})

test('the chat component opens scoped history, reacts to room changes and clears on unmount', async () => {
  const component = await mountComponent(
    'components/InterviewChat.vue',
    {
      '@/stores/interviewChat': { useInterviewChatStore: () => store },
    },
    { scope: 'user', actorId: 7, interview: room },
  )
  try {
    assert.equal(requests()[0].url, '/recruitment/interviews/42/messages')
    component.props.interview = { ...room, id: 43, uuid: 'replacement' }
    await settle()
    assert.equal(requests().at(-1).url, '/recruitment/interviews/43/messages')
  } finally {
    component.app.unmount()
  }
  assert.equal(store.ownKey, '')
  assert.equal(intervals.size, 0)
  assert.equal(echoes.at(-1).disconnected, true)
})

test('the rendered chat escapes sender names and message HTML while preserving plain-text line breaks', async () => {
  const record = message(8, '<img src=x onerror=alert(1)>\nSecond line')
  record.sender.name = '<script>Sender</script>'
  const chat = reactive({
    messages: [record],
    draft: '',
    canSend: true,
    live: true,
    ownKey: 'user:7',
    error: '',
    sendError: '',
    loading: false,
    hasEarlier: false,
    open() {},
    reset() {},
    send() {},
    refresh() {},
  })
  const button = {
    props: ['label'],
    setup: (props) => () => vue.h('button', {}, props.label),
  }
  const modules = new Proxy(
    {
      vue,
      '@/stores/interviewChat': { useInterviewChatStore: () => chat },
      'primevue/button': { default: button },
      'primevue/message': { default: {} },
    },
    { get: (target, key) => target[key] || { default: {} } },
  )
  const filename = new URL('../src/components/InterviewChat.vue', import.meta.url)
  const { descriptor } = parse(readFileSync(filename, 'utf8'))
  const component = evaluateModule(
    compileScript(descriptor, { id: 'interview-chat', inlineTemplate: true }).content,
    modules,
  )
  const html = await renderToString(
    vue.createSSRApp(component, { scope: 'user', actorId: 7, interview: room }),
  )

  assert.ok(html.includes('&lt;img'))
  assert.ok(html.includes('&lt;script&gt;Sender&lt;/script&gt;'))
  assert.ok(html.includes('\nSecond line'))
  assert.equal(html.includes('<img'), false)
  assert.equal(html.includes('<script>'), false)
  assert.ok(html.includes('Interview messages'))
  assert.ok(html.includes('Applicant'))
})
