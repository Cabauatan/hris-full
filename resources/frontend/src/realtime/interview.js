import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import http from '@/api/http'

const settings = import.meta.env || {}

export function interviewPath(scope, id) {
  return `/${scope === 'applicant' ? 'applicant' : 'recruitment'}/interviews/${id}`
}

export function connectInterview({
  uuid,
  scope,
  onSignal = () => {},
  onEnded = () => {},
  onMessage = () => {},
  onReady = () => {},
  onError = () => {},
}) {
  const port = Number(settings.VITE_REVERB_PORT || 8082)
  if (!settings.VITE_REVERB_APP_KEY || !Number.isInteger(port) || port < 1 || port > 65535)
    throw new Error('The interview connection is not configured. Contact HR.')
  let closed = false
  let subscribed = false
  const pending = new Set()
  const secure = settings.VITE_REVERB_SCHEME === 'https'
  const echo = new Echo({
    broadcaster: 'reverb',
    Pusher,
    key: settings.VITE_REVERB_APP_KEY,
    wsHost: settings.VITE_REVERB_HOST || globalThis.window?.location?.hostname || '127.0.0.1',
    wsPort: port,
    wssPort: port,
    forceTLS: secure,
    // Pusher's ws transport also carries TLS; forceTLS selects the wss URL.
    enabledTransports: ['ws'],
    authorizer: (channel) => ({
      authorize(socketId, callback) {
        const controller = new AbortController()
        pending.add(controller)
        http
          .post(
            `/${scope === 'applicant' ? 'applicant' : 'recruitment'}/interviews/broadcasting/auth`,
            { socket_id: socketId, channel_name: channel.name },
            { signal: controller.signal },
          )
          .then(
            ({ data }) => {
              if (!closed) callback(null, data)
            },
            (error) => {
              if (!closed) {
                callback(error, null)
                onError(error)
              }
            },
          )
          .finally(() => pending.delete(controller))
      },
    }),
  })
  echo
    .private(`Recruitment.Interview.${uuid}`)
    .listen('.interview.signal', (event) => {
      if (!closed) onSignal(event)
    })
    .listen('.interview.ended', (event) => {
      if (!closed) onEnded(event)
    })
    .listen('.interview.message', (event) => {
      if (!closed) onMessage(event)
    })
    .subscribed(() => {
      if (!closed) {
        subscribed = true
        onReady()
      }
    })
    .error((error) => {
      if (!closed) onError(error)
    })
  echo.connector?.pusher?.connection?.bind('state_change', ({ current }) => {
    if (!closed && subscribed && current !== 'connected')
      onError(new Error('The interview connection was interrupted. Please rejoin.'))
  })
  return {
    close() {
      closed = true
      for (const controller of pending) controller.abort()
      pending.clear()
      echo.disconnect()
    },
  }
}

// Each panel participant has a separate peer connection. Actor keys also distinguish
// applicant IDs from staff IDs when the two tables contain the same numeric ID.
export function createInterviewPeers({
  actor,
  participants,
  stream,
  iceServers,
  send,
  onRemote,
  onState,
  onError,
  PeerConnection = globalThis.RTCPeerConnection,
}) {
  const peers = new Map()
  const roster = new Set(participants.map((item) => item.key))
  let closed = false
  const current = (key, peer) => !closed && peers.get(key) === peer

  function remove(key) {
    const peer = peers.get(key)
    if (!peer) return
    peers.delete(key)
    peer.pc.onnegotiationneeded = null
    peer.pc.onicecandidate = null
    peer.pc.ontrack = null
    peer.pc.onconnectionstatechange = null
    peer.pc.close()
    onRemote(key, null)
    onState(key, 'waiting')
  }

  function ensure(key) {
    if (peers.has(key)) return peers.get(key)
    const pc = new PeerConnection({ iceServers })
    const peer = {
      pc,
      makingOffer: false,
      ignoreOffer: false,
      answering: false,
      candidates: [],
      incoming: Promise.resolve(),
      outgoing: Promise.resolve(),
    }
    peers.set(key, peer)
    onState(key, 'connecting')
    const transmit = (type, payload) => {
      peer.outgoing = peer.outgoing.then(() => {
        if (current(key, peer)) return send(type, key, payload)
      })
      peer.outgoing.catch((error) => {
        if (current(key, peer)) onError(error)
      })
      return peer.outgoing
    }
    pc.onicecandidate = ({ candidate }) => {
      if (!candidate || !current(key, peer)) return
      const { candidate: value, sdpMid, sdpMLineIndex, usernameFragment } = candidate.toJSON()
      transmit('ice', { candidate: value, sdpMid, sdpMLineIndex, usernameFragment })
    }
    pc.ontrack = ({ streams }) => {
      if (current(key, peer) && streams[0]) onRemote(key, streams[0])
    }
    pc.onconnectionstatechange = () => {
      if (current(key, peer)) onState(key, pc.connectionState)
    }
    pc.onnegotiationneeded = async () => {
      try {
        peer.makingOffer = true
        await pc.setLocalDescription()
        if (current(key, peer))
          await transmit(pc.localDescription.type, { sdp: pc.localDescription.sdp })
      } catch (error) {
        if (current(key, peer)) onError(error)
      } finally {
        peer.makingOffer = false
      }
    }
    for (const track of stream.getTracks()) pc.addTrack(track, stream)
    return peer
  }

  async function receive(event) {
    const key = event.sender?.key
    if (
      closed ||
      !roster.has(key) ||
      key === actor.key ||
      (event.target_key && event.target_key !== actor.key)
    )
      return
    if (event.type === 'leave') {
      remove(key)
      return
    }
    if (event.type === 'ready') {
      if (!event.target_key) remove(key)
      ensure(key)
      if (!event.target_key) await send('ready', key, {})
      return
    }
    if (!['offer', 'answer', 'ice'].includes(event.type) || event.target_key !== actor.key) return
    const peer = ensure(key)
    peer.incoming = peer.incoming
      .then(async () => {
        if (!current(key, peer)) return
        const pc = peer.pc
        if (event.type === 'ice') {
          if (peer.ignoreOffer) return
          if (!pc.remoteDescription) {
            if (peer.candidates.length < 100) peer.candidates.push(event.payload)
            return
          }
          await pc.addIceCandidate(event.payload)
          return
        }
        const offerCollision =
          event.type === 'offer' &&
          (peer.makingOffer || (pc.signalingState !== 'stable' && !peer.answering))
        const polite = actor.key > key
        peer.ignoreOffer = !polite && offerCollision
        if (peer.ignoreOffer) {
          peer.candidates = []
          return
        }
        peer.answering = event.type === 'answer'
        await pc.setRemoteDescription({ type: event.type, sdp: event.payload.sdp })
        peer.answering = false
        if (!current(key, peer)) return
        for (const candidate of peer.candidates) await pc.addIceCandidate(candidate)
        peer.candidates = []
        if (event.type === 'offer') {
          await pc.setLocalDescription()
          if (current(key, peer)) await send('answer', key, { sdp: pc.localDescription.sdp })
        }
      })
      .catch((error) => {
        if (current(key, peer)) onError(error)
      })
    return peer.incoming
  }

  return {
    start: () => send('ready', null, {}),
    receive,
    close() {
      closed = true
      for (const key of [...peers.keys()]) remove(key)
    },
  }
}
