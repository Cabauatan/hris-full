import test, { before, after, beforeEach, afterEach } from 'node:test'
import assert from 'node:assert/strict'
import { registerHooks } from 'node:module'
import { readFileSync } from 'node:fs'
import { setImmediate } from 'node:timers/promises'
import { createPinia, setActivePinia } from 'pinia'
import { AxiosError } from 'axios'
import { reactive } from 'vue'
import { mountComponent } from './helpers/component-harness.mjs'

let realtime, httpModule, useInterviewStore, store
let echoes, peerConnections, calls, intervals, timeouts, managers, captures
let savedGlobals, savedNavigator, handler, mediaHandler
const settings = { VITE_REVERB_APP_KEY: 'public-interview-test-key' }
const roomUuid = '211bf060-03ce-4ba9-971c-7cf7c1c27dc0'

class FakeEcho {
  constructor(options) {
    this.options = options
    this.channels = []
    this.connectionHandlers = {}
    this.connector = {
      pusher: {
        connection: {
          bind: (name, callback) => {
            this.connectionHandlers[name] = callback
          },
        },
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

class FakePeerConnection {
  constructor(options) {
    this.options = options
    this.tracks = []
    this.remoteDescriptions = []
    this.candidates = []
    this.signalingState = 'stable'
    this.connectionState = 'new'
    peerConnections.push(this)
  }
  addTrack(track, stream) {
    this.tracks.push({ track, stream })
  }
  async setLocalDescription() {
    this.localDescription = {
      type: this.signalingState === 'have-remote-offer' ? 'answer' : 'offer',
      sdp: 'test-session-description',
    }
    this.signalingState = this.localDescription.type === 'answer' ? 'stable' : 'have-local-offer'
  }
  async setRemoteDescription(description) {
    this.remoteDescriptions.push(description)
    this.remoteDescription = description
    this.signalingState = description.type === 'offer' ? 'have-remote-offer' : 'stable'
  }
  async addIceCandidate(candidate) {
    this.candidates.push(candidate)
  }
  close() {
    this.closed = true
    this.connectionState = 'closed'
  }
}

function stream() {
  const tracks = ['audio', 'video'].map((kind) => ({
    kind,
    enabled: true,
    stops: 0,
    stop() {
      this.stops += 1
    },
  }))
  return {
    getTracks: () => tracks,
    getAudioTracks: () => tracks.filter(({ kind }) => kind === 'audio'),
    getVideoTracks: () => tracks.filter(({ kind }) => kind === 'video'),
  }
}
function actor(kind = 'user', id = 7) {
  return { kind, id, key: `${kind}:${id}`, name: `Test ${kind} ${id}` }
}
function details(scope = 'user', id = 7) {
  return {
    interview: { id: 42, uuid: roomUuid, status: 'scheduled' },
    actor: actor(scope, id),
    participants: [actor('user', 7), actor('applicant', 7)],
    ice_servers: [{ urls: 'stun:example.test:3478' }],
  }
}
function deferred() {
  let resolve, reject
  const promise = new Promise((yes, no) => {
    resolve = yes
    reject = no
  })
  return { promise, resolve, reject }
}
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
async function settle() {
  await setImmediate()
  await setImmediate()
}
function paths() {
  return calls.map(({ method, url }) => `${method.toUpperCase()} ${url}`)
}
function signal(
  type,
  sender = actor('applicant', 7),
  target = 'user:7',
  payload = {},
  uuid = roomUuid,
) {
  return { type, sender, target_key: target, payload, interview_uuid: uuid }
}
function peerManager({ local = actor(), roster = [actor(), actor('applicant', 7)], send } = {}) {
  const sent = [],
    remotes = [],
    states = [],
    errors = []
  const manager = realtime.createInterviewPeers({
    actor: local,
    participants: roster,
    stream: stream(),
    iceServers: [],
    send:
      send ||
      (async (type, target, payload) => {
        sent.push({ type, target, payload })
      }),
    onRemote: (key, remote) => remotes.push({ key, remote }),
    onState: (key, state) => states.push({ key, state }),
    onError: (error) => errors.push(error),
    PeerConnection: FakePeerConnection,
  })
  managers.push(manager)
  return { manager, sent, remotes, states, errors }
}
function transport(options = {}) {
  const events = { signals: [], ended: [], errors: [], ready: 0 }
  const connection = realtime.connectInterview({
    uuid: roomUuid,
    scope: 'user',
    onSignal: (event) => events.signals.push(event),
    onEnded: (event) => events.ended.push(event),
    onReady: () => {
      events.ready += 1
    },
    onError: (error) => events.errors.push(error),
    ...options,
  })
  return { connection, events, echo: echoes.at(-1) }
}

before(async () => {
  globalThis.__hrisInterviewEcho = FakeEcho
  globalThis.__hrisInterviewSettings = settings
  const stubs = {
    'laravel-echo': 'export default globalThis.__hrisInterviewEcho',
    'pusher-js': 'export default class Pusher {}',
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
            'globalThis.__hrisInterviewSettings',
          ),
        }
      return nextLoad(url, context)
    },
  })
  try {
    httpModule = await import('../src/api/http.js')
    realtime = await import('../src/realtime/interview.js')
    ;({ useInterviewStore } = await import('../src/stores/interview.js'))
  } finally {
    hooks.deregister()
  }
})
after(() => {
  delete globalThis.__hrisInterviewEcho
  delete globalThis.__hrisInterviewSettings
})
beforeEach(() => {
  savedGlobals = Object.fromEntries(
    [
      'window',
      'RTCPeerConnection',
      'setInterval',
      'clearInterval',
      'setTimeout',
      'clearTimeout',
    ].map((key) => [key, globalThis[key]]),
  )
  savedNavigator = Object.getOwnPropertyDescriptor(globalThis, 'navigator')
  globalThis.window = new EventTarget()
  globalThis.RTCPeerConnection = FakePeerConnection
  echoes = []
  peerConnections = []
  calls = []
  managers = []
  captures = []
  intervals = new Map()
  timeouts = new Map()
  let timerId = 0
  globalThis.setInterval = (callback, delay) => {
    const id = ++timerId
    intervals.set(id, { callback, delay })
    return id
  }
  globalThis.clearInterval = (id) => intervals.delete(id)
  globalThis.setTimeout = (callback, delay) => {
    const id = ++timerId
    timeouts.set(id, { callback, delay })
    return id
  }
  globalThis.clearTimeout = (id) => timeouts.delete(id)
  mediaHandler = async () => stream()
  Object.defineProperty(globalThis, 'navigator', {
    configurable: true,
    value: {
      mediaDevices: {
        getUserMedia: (...args) => {
          captures.push(args)
          return mediaHandler(...args)
        },
      },
    },
  })
  for (const key of Object.keys(settings)) delete settings[key]
  settings.VITE_REVERB_APP_KEY = 'public-interview-test-key'
  httpModule.resetCsrf()
  httpModule.http.defaults.adapter = async (config) => {
    calls.push(config)
    return handler(config)
  }
  handler = (config) => {
    if (config.url.endsWith('/csrf-cookie')) return response(config, '', 204)
    if (config.url.endsWith('/join'))
      return response(config, {
        data: details(config.url.startsWith('/applicant') ? 'applicant' : 'user'),
      })
    if (config.url.endsWith('/signal')) return response(config, '', 204)
    if (config.url.endsWith('/broadcasting/auth'))
      return response(config, { auth: 'public-test-signature' })
    if (config.method === 'get') return response(config, { data: { abilities: { join: true } } })
    throw new Error(`Unexpected request ${config.method} ${config.url}`)
  }
  setActivePinia(createPinia())
  store = useInterviewStore()
})
afterEach(() => {
  store?.leave(false)
  store?.$dispose()
  setActivePinia(undefined)
  for (const manager of managers) manager.close()
  for (const echo of echoes) echo.disconnect()
  httpModule.resetCsrf()
  for (const [key, value] of Object.entries(savedGlobals)) {
    if (value === undefined) delete globalThis[key]
    else globalThis[key] = value
  }
  if (savedNavigator) Object.defineProperty(globalThis, 'navigator', savedNavigator)
  else delete globalThis.navigator
})

test('one applicant and one HR with equal numeric IDs discover distinct peers in either join order without ready ping-pong', async () => {
  for (const order of [
    [actor('user', 7), actor('applicant', 7)],
    [actor('applicant', 7), actor('user', 7)],
  ]) {
    const members = new Map(),
      queue = [],
      sent = []
    const roster = order
    const attach = (local) => {
      const { manager } = peerManager({
        local,
        roster,
        send: async (type, target_key, payload) => {
          const event = { sender: local, type, target_key, payload }
          sent.push(event)
          queue.push(event)
        },
      })
      members.set(local.key, manager)
      return manager
    }
    const drain = async () => {
      while (queue.length) {
        const event = queue.shift()
        for (const member of members.values()) await member.receive(event)
      }
    }
    const first = attach(order[0])
    await first.start()
    await drain()
    assert.equal(sent.length, 1)
    const second = attach(order[1])
    await second.start()
    await drain()
    assert.equal(sent.length, 3)
    assert.equal(sent[2].target_key, order[1].key)
    assert.equal(queue.length, 0)
    for (const manager of members.values()) manager.close()
  }
  assert.equal(peerConnections.length, 4)
})

test('a panel of four HR users and one applicant creates one peer per other participant and targeted discovery terminates', async () => {
  const roster = [actor('applicant', 7), ...[7, 8, 9, 10].map((id) => actor('user', id))]
  const members = [],
    queue = [],
    sent = []
  for (const local of roster) {
    const { manager } = peerManager({
      local,
      roster,
      send: async (type, target_key, payload) => {
        const event = { sender: local, type, target_key, payload }
        sent.push(event)
        queue.push(event)
      },
    })
    members.push(manager)
  }
  for (const member of members) await member.start()
  while (queue.length) {
    assert.ok(sent.length <= 25, 'Discovery must not create an unbounded ready loop')
    const event = queue.shift()
    for (const member of members) await member.receive(event)
  }
  assert.equal(peerConnections.length, 20)
  assert.equal(sent.filter(({ target_key }) => target_key === null).length, 5)
  assert.equal(sent.filter(({ target_key }) => target_key !== null).length, 20)
  assert.ok(peerConnections.every(({ tracks }) => tracks.length === 2))
})

test('outsider, self, non-targeted descriptions and signals targeting another panelist cannot create a peer', async () => {
  const { manager, sent } = peerManager()
  await manager.receive(signal('ready', actor('user', 999), null))
  await manager.receive(signal('ready', actor(), null))
  await manager.receive(
    signal('offer', actor('applicant', 7), 'user:99', { sdp: 'other participant' }),
  )
  await manager.receive(signal('offer', actor('applicant', 7), null, { sdp: 'untargeted offer' }))
  assert.equal(peerConnections.length, 0)
  assert.deepEqual(sent, [])
})

test('ICE received before remote SDP is queued and applied only after the offer is accepted', async () => {
  const { manager, sent, errors } = peerManager()
  const candidate = { candidate: 'candidate:test', sdpMid: '0', sdpMLineIndex: 0 }
  await manager.receive(signal('ice', actor('applicant', 7), 'user:7', candidate))
  const pc = peerConnections[0]
  assert.deepEqual(pc.candidates, [])
  await manager.receive(signal('offer', actor('applicant', 7), 'user:7', { sdp: 'remote offer' }))
  assert.deepEqual(pc.candidates, [candidate])
  assert.deepEqual(pc.remoteDescriptions, [{ type: 'offer', sdp: 'remote offer' }])
  assert.equal(sent[0].type, 'answer')
  assert.equal(sent[0].target, 'applicant:7')
  assert.deepEqual(errors, [])
})

test('polite participant accepts a colliding offer and answers while impolite participant ignores offer and associated ICE', async () => {
  const polite = peerManager({ local: actor('user', 7) })
  await polite.manager.receive(signal('ready', actor('applicant', 7), 'user:7'))
  const politePc = peerConnections[0]
  await politePc.onnegotiationneeded()
  assert.equal(politePc.signalingState, 'have-local-offer')
  await polite.manager.receive(
    signal('offer', actor('applicant', 7), 'user:7', { sdp: 'colliding offer' }),
  )
  assert.equal(politePc.remoteDescriptions.length, 1)
  assert.deepEqual(
    polite.sent.map(({ type }) => type),
    ['offer', 'answer'],
  )

  const impolite = peerManager({ local: actor('applicant', 7) })
  await impolite.manager.receive(signal('ready', actor('user', 7), 'applicant:7'))
  const impolitePc = peerConnections[1]
  await impolitePc.onnegotiationneeded()
  await impolite.manager.receive(
    signal('offer', actor('user', 7), 'applicant:7', { sdp: 'colliding offer' }),
  )
  await impolite.manager.receive(
    signal('ice', actor('user', 7), 'applicant:7', { candidate: 'ignored ICE' }),
  )
  assert.deepEqual(impolitePc.remoteDescriptions, [])
  assert.deepEqual(impolitePc.candidates, [])
  await impolite.manager.receive(
    signal('answer', actor('user', 7), 'applicant:7', { sdp: 'accepted answer' }),
  )
  await impolite.manager.receive(
    signal('ice', actor('user', 7), 'applicant:7', { candidate: 'accepted ICE' }),
  )
  assert.deepEqual(impolitePc.candidates, [{ candidate: 'accepted ICE' }])
  assert.deepEqual(impolite.errors, [])
})

test('peer leave removes the stream, closes its connection and ignores captured late track and ICE callbacks', async () => {
  const { manager, remotes, states, sent } = peerManager()
  await manager.receive(signal('ready', actor('applicant', 7), 'user:7'))
  const pc = peerConnections[0],
    trackCallback = pc.ontrack,
    iceCallback = pc.onicecandidate
  const remote = stream()
  trackCallback({ streams: [remote] })
  await manager.receive(signal('leave', actor('applicant', 7), null))
  trackCallback({ streams: [stream()] })
  iceCallback({ candidate: { toJSON: () => ({ candidate: 'late ICE' }) } })
  await settle()
  assert.equal(pc.closed, true)
  assert.deepEqual(remotes, [
    { key: 'applicant:7', remote },
    { key: 'applicant:7', remote: null },
  ])
  assert.deepEqual(states.at(-1), { key: 'applicant:7', state: 'waiting' })
  assert.deepEqual(sent, [])
})

test('closing while remote description is pending prevents a late answer and a renewed peer', async () => {
  const { manager, sent, errors } = peerManager()
  await manager.receive(signal('ready', actor('applicant', 7), 'user:7'))
  const pc = peerConnections[0],
    pendingDescription = deferred()
  pc.setRemoteDescription = async () => pendingDescription.promise
  const receive = manager.receive(
    signal('offer', actor('applicant', 7), 'user:7', { sdp: 'pending description' }),
  )
  await settle()
  manager.close()
  pendingDescription.resolve()
  await receive
  await manager.receive(signal('ready', actor('applicant', 7), null))
  assert.equal(peerConnections.length, 1)
  assert.equal(pc.closed, true)
  assert.deepEqual(sent, [])
  assert.deepEqual(errors, [])
})

for (const scope of ['user', 'applicant'])
  test(`${scope} websocket authorization uses its own guard endpoint and real CSRF bootstrap`, async () => {
    const { connection, echo } = transport({ scope })
    const results = []
    echo.options
      .authorizer({ name: `private-Recruitment.Interview.${roomUuid}` })
      .authorize('1.2', (...value) => results.push(value))
    await settle()
    assert.deepEqual(paths(), [
      `GET /${scope === 'applicant' ? 'applicant/auth' : 'auth'}/csrf-cookie`,
      `POST /${scope === 'applicant' ? 'applicant' : 'recruitment'}/interviews/broadcasting/auth`,
    ])
    assert.deepEqual(JSON.parse(calls[1].data), {
      socket_id: '1.2',
      channel_name: `private-Recruitment.Interview.${roomUuid}`,
    })
    assert.equal(calls[1].withCredentials, true)
    assert.equal(calls[1].withXSRFToken, true)
    assert.deepEqual(results, [[null, { auth: 'public-test-signature' }]])
    assert.equal(echo.channels[0].name, `Recruitment.Interview.${roomUuid}`)
    connection.close()
  })

test('websocket host follows the browser host unless explicitly configured', (t) => {
  t.after(() => delete settings.VITE_REVERB_HOST)
  window.location = { hostname: '192.0.2.10' }
  const browserHost = transport()
  assert.equal(browserHost.echo.options.wsHost, '192.0.2.10')
  browserHost.connection.close()

  settings.VITE_REVERB_HOST = 'reverb.example.test'
  const configuredHost = transport()
  assert.equal(configuredHost.echo.options.wsHost, 'reverb.example.test')
  configuredHost.connection.close()
})

test('closing the websocket aborts pending authorization and ignores late subscription, signal and authorization callbacks', async () => {
  const pending = deferred()
  handler = (config) =>
    config.url.endsWith('/csrf-cookie') ? response(config, '', 204) : pending.promise
  const { connection, events, echo } = transport()
  const results = []
  echo.options
    .authorizer({ name: `private-Recruitment.Interview.${roomUuid}` })
    .authorize('1.2', (...value) => results.push(value))
  await settle()
  const request = calls.at(-1)
  connection.close()
  assert.equal(request.signal.aborted, true)
  pending.resolve(response(request, { auth: 'late signature' }))
  await settle()
  echo.channels[0].ready()
  echo.channels[0].listeners['.interview.signal'](signal('ready'))
  echo.channels[0].listeners['.interview.ended']({ interview_uuid: roomUuid })
  assert.equal(echo.disconnected, true)
  assert.deepEqual(results, [])
  assert.deepEqual(events, { signals: [], ended: [], errors: [], ready: 0 })
})

test('socket authorization denial reports the error without accepting a signature', async () => {
  handler = (config) =>
    config.url.endsWith('/csrf-cookie') ? response(config, '', 204) : failResponse(config, 403)
  const { connection, events, echo } = transport(),
    results = []
  echo.options
    .authorizer({ name: `private-Recruitment.Interview.${roomUuid}` })
    .authorize('1.2', (...value) => results.push(value))
  await settle()
  assert.equal(results[0][0].response.status, 403)
  assert.equal(results[0][1], null)
  assert.equal(events.errors[0].response.status, 403)
  connection.close()
})

test('camera and microphone remain idle until join and websocket subscription starts participant discovery', async () => {
  assert.equal(captures.length, 0)
  assert.equal(echoes.length, 0)
  await store.join('user', 42, 7)
  assert.equal(captures.length, 1)
  assert.equal(captures[0][0].audio, true)
  assert.equal(store.joining, true)
  assert.equal(store.joined, false)
  assert.equal(
    calls.some(({ url }) => url.endsWith('/signal')),
    false,
  )
  echoes[0].channels[0].ready()
  await settle()
  assert.equal(store.joining, false)
  assert.equal(store.joined, true)
  assert.deepEqual(JSON.parse(calls.at(-1).data), { type: 'ready', target_key: null, payload: {} })
  assert.equal(timeouts.size, 0)
  assert.equal(intervals.size, 1)
})

test('identity changes or an applicant/user numeric ID collision cannot start media capture', async () => {
  handler = (config) =>
    config.url.endsWith('/csrf-cookie')
      ? response(config, '', 204)
      : response(config, { data: details('applicant', 7) })
  await store.join('user', 42, 7)
  assert.match(store.error, /account changed/i)
  assert.equal(captures.length, 0)
  assert.equal(echoes.length, 0)
  assert.equal(store.joining, false)
  await store.join('applicant', 42, 8)
  assert.match(store.error, /account changed/i)
  assert.equal(captures.length, 0)
})

test('camera permission denial returns an actionable message and closes the join request without opening a socket', async () => {
  mediaHandler = async () => {
    const error = new Error('Permission denied')
    error.name = 'NotAllowedError'
    throw error
  }
  await store.join('applicant', 42, 7)
  assert.match(store.error, /access was declined/i)
  assert.equal(store.localStream, null)
  assert.equal(store.joining, false)
  assert.equal(echoes.length, 0)
  assert.equal(calls.find(({ url }) => url.endsWith('/join')).signal.aborted, true)
})

test('leaving during capture stops every late track and cannot restore the abandoned room', async () => {
  const pending = deferred(),
    lateStream = stream()
  mediaHandler = async () => pending.promise
  const joining = store.join('applicant', 42, 7)
  await settle()
  assert.equal(captures.length, 1)
  store.leave(false)
  pending.resolve(lateStream)
  await joining
  assert.ok(lateStream.getTracks().every(({ stops }) => stops === 1))
  assert.equal(store.localStream, null)
  assert.equal(store.room, null)
  assert.equal(store.joined, false)
  assert.equal(echoes.length, 0)
})

test('cancelling a pending join aborts the request and never captures media when its response arrives', async () => {
  const pending = deferred()
  handler = (config) =>
    config.url.endsWith('/csrf-cookie') ? response(config, '', 204) : pending.promise
  const joining = store.join('user', 42, 7)
  await settle()
  const request = calls.find(({ url }) => url.endsWith('/join'))
  store.leave(false)
  assert.equal(request.signal.aborted, true)
  pending.resolve(response(request, { data: details() }))
  await joining
  assert.equal(captures.length, 0)
  assert.equal(store.joining, false)
  assert.equal(store.error, '')
})

test('a rejected server join never requests camera permission or opens a socket', async () => {
  handler = (config) =>
    config.url.endsWith('/csrf-cookie') ? response(config, '', 204) : failResponse(config, 403)
  await store.join('applicant', 42, 7)
  assert.match(store.error, /do not have access/i)
  assert.equal(captures.length, 0)
  assert.equal(echoes.length, 0)
  assert.equal(store.room, null)
  assert.equal(store.joining, false)
})

test('duplicate join clicks during admission and after subscription cannot create another capture or socket', async () => {
  const pending = deferred()
  handler = (config) =>
    config.url.endsWith('/csrf-cookie') ? response(config, '', 204) : pending.promise
  const first = store.join('user', 42, 7)
  await settle()
  await store.join('user', 42, 7)
  const request = calls.find(({ url }) => url.endsWith('/join'))
  pending.resolve(response(request, { data: details() }))
  await first
  echoes[0].channels[0].ready()
  await settle()
  await store.join('user', 42, 7)
  assert.equal(calls.filter(({ url }) => url.endsWith('/join')).length, 1)
  assert.equal(captures.length, 1)
  assert.equal(echoes.length, 1)
})

test('late capture from an abandoned join stops old tracks without replacing the new room media', async () => {
  const pending = deferred(),
    abandoned = stream(),
    replacement = stream()
  mediaHandler = () => (captures.length === 1 ? pending.promise : Promise.resolve(replacement))
  const firstJoin = store.join('user', 42, 7)
  await settle()
  store.leave(false)
  await store.join('user', 43, 7)
  assert.equal(store.localStream, replacement)
  pending.resolve(abandoned)
  await firstJoin
  assert.equal(store.localStream, replacement)
  assert.ok(abandoned.getTracks().every(({ stops }) => stops === 1))
  assert.ok(replacement.getTracks().every(({ stops }) => stops === 0))
  assert.equal(echoes.length, 1)
  assert.equal(store.joining, true)
})

test('replacement join waits for the previous leave signal and ignores callbacks from old media', async () => {
  await store.join('user', 42, 7)
  const oldStream = store.localStream,
    oldEnded = oldStream.getAudioTracks()[0].onended
  echoes[0].channels[0].ready()
  await settle()
  const pendingLeave = deferred()
  const normal = handler
  handler = (config) =>
    config.url.endsWith('/signal') && JSON.parse(config.data).type === 'leave'
      ? pendingLeave.promise
      : normal(config)
  store.leave()
  const replacementJoin = store.join('user', 43, 7)
  await settle()
  assert.equal(calls.filter(({ url }) => url.endsWith('/join')).length, 1)
  assert.equal(captures.length, 1)
  const leaveRequest = calls.find(
    ({ url, data }) => url.endsWith('/signal') && JSON.parse(data).type === 'leave',
  )
  pendingLeave.resolve(response(leaveRequest, '', 204))
  await replacementJoin
  oldEnded()
  assert.equal(captures.length, 2)
  assert.equal(echoes.length, 2)
  assert.equal(store.error, '')
  assert.ok(store.localStream.getTracks().every(({ stops }) => stops === 0))
  assert.equal(store.joining, true)
})

test('device disconnection stops microphone and camera and closes the active room', async () => {
  await store.join('user', 42, 7)
  const local = store.localStream
  echoes[0].channels[0].ready()
  await settle()
  local.getVideoTracks()[0].onended()
  assert.match(store.error, /camera or microphone disconnected/i)
  assert.equal(store.joined, false)
  assert.equal(store.room, null)
  assert.ok(local.getTracks().every(({ stops }) => stops === 1))
  assert.equal(echoes[0].disconnected, true)
  assert.equal(intervals.size, 0)
})

test('a late authorization refresh from the abandoned room cannot close its replacement', async () => {
  await store.join('user', 42, 7)
  echoes[0].channels[0].ready()
  await settle()
  const pendingRefresh = deferred(),
    normal = handler
  handler = (config) =>
    config.url === '/recruitment/interviews/42' ? pendingRefresh.promise : normal(config)
  const refresh = [...intervals.values()][0].callback()
  await settle()
  const request = calls.find(({ url }) => url === '/recruitment/interviews/42')
  store.leave(false)
  await store.join('user', 43, 7)
  echoes[1].channels[0].ready()
  await settle()
  const replacement = store.localStream
  pendingRefresh.resolve(response(request, { data: { abilities: { join: false } } }))
  await refresh
  assert.equal(store.localStream, replacement)
  assert.equal(store.joined, true)
  assert.equal(store.error, '')
  assert.equal(echoes[1].disconnected, undefined)
  assert.ok(replacement.getTracks().every(({ stops }) => stops === 0))
})

test('mute and camera controls change the actual local media track enabled state', async () => {
  await store.join('user', 42, 7)
  const local = store.localStream
  store.toggleMic()
  store.toggleCamera()
  assert.equal(store.micEnabled, false)
  assert.equal(store.cameraEnabled, false)
  assert.equal(local.getAudioTracks()[0].enabled, false)
  assert.equal(local.getVideoTracks()[0].enabled, false)
  store.toggleMic()
  store.toggleCamera()
  assert.ok(local.getTracks().every(({ enabled }) => enabled))
})

test('leave closes all peers and the socket, clears timers and aborts requests while sending one leave signal', async () => {
  await store.join('user', 42, 7)
  const echo = echoes[0],
    channel = echo.channels[0],
    local = store.localStream
  channel.ready()
  await settle()
  channel.listeners['.interview.signal'](signal('ready', actor('applicant', 7), 'user:7'))
  await settle()
  const pc = peerConnections[0],
    oldTrack = pc.ontrack
  oldTrack({ streams: [stream()] })
  assert.ok(store.remoteStreams['applicant:7'])
  const request = calls.find(({ url }) => url.endsWith('/join'))
  store.leave()
  await settle()
  oldTrack({ streams: [stream()] })
  assert.equal(echo.disconnected, true)
  assert.equal(pc.closed, true)
  assert.equal(request.signal.aborted, true)
  assert.ok(local.getTracks().every(({ stops }) => stops === 1))
  assert.equal(store.room, null)
  assert.equal(store.localStream, null)
  assert.deepEqual(store.remoteStreams, {})
  assert.deepEqual(store.peerStates, {})
  assert.equal(timeouts.size, 0)
  assert.equal(intervals.size, 0)
  const leaveSignals = calls.filter(
    ({ url, data }) => url.endsWith('/signal') && JSON.parse(data).type === 'leave',
  )
  assert.equal(leaveSignals.length, 1)
  assert.equal(leaveSignals[0].skipAuthEvent, true)
})

test('subscription timeout stops media and socket and clears the authorization refresh timer', async () => {
  await store.join('user', 42, 7)
  const local = store.localStream,
    timeout = [...timeouts.values()][0]
  assert.equal(timeout.delay, 15000)
  timeout.callback()
  assert.match(store.error, /unable to connect/i)
  assert.equal(store.joined, false)
  assert.equal(store.joining, false)
  assert.equal(echoes[0].disconnected, true)
  assert.ok(local.getTracks().every(({ stops }) => stops === 1))
  assert.equal(timeouts.size, 0)
  assert.equal(intervals.size, 0)
})

test('wrong-room signals and ended events are ignored while the assigned room end stops all local tracks', async () => {
  await store.join('applicant', 42, 7)
  const channel = echoes[0].channels[0],
    local = store.localStream
  channel.ready()
  await settle()
  channel.listeners['.interview.signal'](
    signal('ready', actor('user', 7), 'applicant:7', {}, 'different-room'),
  )
  channel.listeners['.interview.ended']({ interview_uuid: 'different-room' })
  await settle()
  assert.equal(peerConnections.length, 0)
  assert.equal(store.joined, true)
  assert.equal(store.error, '')
  channel.listeners['.interview.ended']({ interview_uuid: roomUuid })
  assert.match(store.error, /ended this interview/i)
  assert.equal(store.joined, false)
  assert.ok(local.getTracks().every(({ stops }) => stops === 1))
  assert.equal(echoes[0].disconnected, true)
  assert.equal(intervals.size, 0)
})

for (const denial of ['abilities', 'http'])
  test(`authorization refresh ${denial} denial leaves the room and stops camera capture`, async () => {
    await store.join('user', 42, 7)
    const local = store.localStream
    echoes[0].channels[0].ready()
    await settle()
    handler = (config) =>
      denial === 'http'
        ? failResponse(config, 403)
        : response(config, { data: { abilities: { join: false } } })
    const refresh = [...intervals.values()][0]
    assert.equal(refresh.delay, 20000)
    await refresh.callback()
    assert.equal(store.joined, false)
    assert.ok(local.getTracks().every(({ stops }) => stops === 1))
    assert.equal(echoes[0].disconnected, true)
    assert.equal(intervals.size, 0)
    assert.match(store.error, denial === 'http' ? /do not have access/i : /no longer available/i)
  })

test('a lost socket after subscription ends the call and does not continue capturing microphone or video', async () => {
  await store.join('user', 42, 7)
  const local = store.localStream,
    echo = echoes[0]
  echo.channels[0].ready()
  await settle()
  echo.connectionHandlers.state_change({ current: 'disconnected' })
  assert.match(store.error, /connection was interrupted/i)
  assert.equal(store.joined, false)
  assert.ok(local.getTracks().every(({ stops }) => stops === 1))
  assert.equal(echo.disconnected, true)
  assert.equal(intervals.size, 0)
})

test('the real room view cancels capture when its active employee identity changes', async () => {
  const auth = reactive({ user: { id: 7 }, authenticated: true })
  const applicant = reactive({ user: { id: 7 }, authenticated: true })
  const route = reactive({ params: { interviewId: 42 }, meta: {} })
  const mounted = await mountComponent('views/InterviewRoomView.vue', {
    'vue-router': { useRoute: () => route, useRouter: () => ({ push() {} }) },
    '@/api/http': { default: httpModule.http, apiError: httpModule.apiError },
    '@/stores/auth': { useAuthStore: () => auth },
    '@/stores/applicant': { useApplicantStore: () => applicant },
    '@/stores/interview': { useInterviewStore: () => store },
    '@/realtime/interview': { interviewPath: realtime.interviewPath },
  })
  try {
    await settle()
    assert.equal(captures.length, 0)
    const pendingCapture = deferred(),
      late = stream()
    mediaHandler = () => pendingCapture.promise
    const joining = store.join('user', 42, 7)
    await settle()
    auth.user = { id: 8 }
    await settle()
    assert.equal(store.joining, false)
    pendingCapture.resolve(late)
    await joining
    assert.ok(late.getTracks().every(({ stops }) => stops === 1))
    assert.equal(echoes.length, 0)
  } finally {
    mounted.app.unmount()
  }
})

test('the real applicant room reacts only to its active identity and stops media on logout and unmount', async () => {
  const auth = reactive({ user: { id: 7 }, authenticated: true })
  const applicant = reactive({ user: { id: 7 }, authenticated: true })
  const route = reactive({ params: { interviewId: 42 }, meta: { applicant: true } })
  const mounted = await mountComponent('views/InterviewRoomView.vue', {
    'vue-router': { useRoute: () => route, useRouter: () => ({ push() {} }) },
    '@/api/http': { default: httpModule.http, apiError: httpModule.apiError },
    '@/stores/auth': { useAuthStore: () => auth },
    '@/stores/applicant': { useApplicantStore: () => applicant },
    '@/stores/interview': { useInterviewStore: () => store },
    '@/realtime/interview': { interviewPath: realtime.interviewPath },
  })
  let unmounted = false
  try {
    await settle()
    await store.join('applicant', 42, 7)
    const local = store.localStream
    echoes[0].channels[0].ready()
    await settle()
    auth.user = { id: 8 }
    await settle()
    assert.equal(store.joined, true)
    applicant.user = null
    applicant.authenticated = false
    await settle()
    assert.equal(store.joined, false)
    assert.ok(local.getTracks().every(({ stops }) => stops === 1))
    assert.equal(intervals.size, 0)
    applicant.user = { id: 7 }
    applicant.authenticated = true
    await settle()
    await store.join('applicant', 42, 7)
    const replacement = store.localStream
    mounted.app.unmount()
    unmounted = true
    assert.ok(replacement.getTracks().every(({ stops }) => stops === 1))
    assert.equal(echoes[1].disconnected, true)
  } finally {
    if (!unmounted) mounted.app.unmount()
  }
})
