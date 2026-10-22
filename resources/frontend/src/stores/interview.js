import { ref, shallowRef } from 'vue'
import { defineStore } from 'pinia'
import http, { apiError } from '@/api/http'
import { connectInterview, createInterviewPeers, interviewPath } from '@/realtime/interview'

export const useInterviewStore = defineStore('interview', () => {
  const room = shallowRef(null)
  const localStream = shallowRef(null)
  const remoteStreams = shallowRef({})
  const peerStates = ref({})
  const joining = ref(false)
  const joined = ref(false)
  const error = ref('')
  const micEnabled = ref(true)
  const cameraEnabled = ref(true)
  let version = 0
  let connection
  let peers
  let controller
  let timer
  let readyTimer
  let activeScope
  let activeId
  let leaving = Promise.resolve()

  function stopStream(stream) {
    for (const track of stream?.getTracks() || []) track.stop()
  }

  function leave(notify = true) {
    const scope = activeScope,
      id = activeId,
      wasJoined = joined.value
    version += 1
    clearInterval(timer)
    clearTimeout(readyTimer)
    controller?.abort()
    connection?.close()
    peers?.close()
    stopStream(localStream.value)
    localStream.value = null
    remoteStreams.value = {}
    peerStates.value = {}
    room.value = null
    joining.value = false
    joined.value = false
    error.value = ''
    connection = undefined
    peers = undefined
    activeScope = undefined
    activeId = undefined
    if (notify && wasJoined)
      leaving = http
        .post(
          `${interviewPath(scope, id)}/signal`,
          { type: 'leave', payload: {} },
          { skipAuthEvent: true },
        )
        .catch(() => {})
  }

  async function join(scope, id, expectedActorId) {
    if (joining.value || joined.value) return
    leave(false)
    const currentVersion = version
    const current = () => version === currentVersion
    error.value = ''
    joining.value = true
    controller = new AbortController()
    activeScope = scope
    activeId = id
    const fail = (failure) => {
      if (!current()) return
      const message =
        failure instanceof Error && !failure.response ? failure.message : apiError(failure).message
      leave(false)
      error.value = message
    }
    try {
      await leaving
      if (!current()) return
      if (!globalThis.navigator?.mediaDevices?.getUserMedia || !globalThis.RTCPeerConnection)
        throw new Error('Camera calls need a supported browser using HTTPS or localhost.')
      const { data } = await http.post(`${interviewPath(scope, id)}/join`, undefined, {
        signal: controller.signal,
      })
      if (!current()) return
      const details = data.data
      if (details.actor.kind !== scope || Number(details.actor.id) !== Number(expectedActorId))
        throw new Error('Your account changed. Please reload before joining.')
      const stream = await navigator.mediaDevices.getUserMedia({
        audio: true,
        video: { width: { ideal: 640 }, height: { ideal: 360 } },
      })
      if (!current()) {
        stopStream(stream)
        return
      }
      localStream.value = stream
      for (const track of stream.getTracks())
        track.onended = () =>
          fail(new Error('Your camera or microphone disconnected. Please rejoin.'))
      room.value = details
      micEnabled.value = true
      cameraEnabled.value = true
      const send = (type, target_key, payload) => {
        if (!current()) return Promise.resolve()
        return http.post(
          `${interviewPath(scope, id)}/signal`,
          { type, target_key, payload },
          { signal: controller.signal },
        )
      }
      peers = createInterviewPeers({
        actor: details.actor,
        participants: details.participants,
        stream,
        iceServers: details.ice_servers,
        send,
        onRemote(key, remote) {
          if (current()) {
            const values = { ...remoteStreams.value }
            if (remote) values[key] = remote
            else delete values[key]
            remoteStreams.value = values
          }
        },
        onState(key, state) {
          if (current()) peerStates.value = { ...peerStates.value, [key]: state }
        },
        onError: fail,
      })
      connection = connectInterview({
        uuid: details.interview.uuid,
        scope,
        onSignal(event) {
          if (current() && event.interview_uuid === details.interview.uuid)
            peers.receive(event).catch(fail)
        },
        onEnded(event) {
          if (current() && event.interview_uuid === details.interview.uuid)
            fail(new Error('HR has ended this interview.'))
        },
        onReady() {
          if (current()) {
            clearTimeout(readyTimer)
            joining.value = false
            joined.value = true
            peers.start().catch(fail)
          }
        },
        onError: fail,
      })
      if (!joined.value)
        readyTimer = setTimeout(
          () =>
            fail(
              new Error(
                'Unable to connect to the interview room. Check your connection and rejoin.',
              ),
            ),
          15000,
        )
      timer = setInterval(async () => {
        if (!current()) return
        try {
          const response = await http.get(interviewPath(scope, id), { signal: controller.signal })
          if (current() && !response.data.data.abilities.join)
            fail(new Error('This interview room is no longer available.'))
        } catch (failure) {
          fail(failure)
        }
      }, 20000)
    } catch (failure) {
      if (failure.name === 'NotAllowedError')
        fail(
          new Error(
            'Camera or microphone access was declined. Allow access in your browser, then join again.',
          ),
        )
      else if (failure.name === 'NotFoundError')
        fail(new Error('Connect a camera and microphone, then join again.'))
      else if (failure.name === 'NotReadableError')
        fail(new Error('Your camera or microphone is busy. Close other calls, then join again.'))
      else fail(failure)
    }
  }

  function toggleMic() {
    micEnabled.value = !micEnabled.value
    for (const track of localStream.value?.getAudioTracks() || []) track.enabled = micEnabled.value
  }
  function toggleCamera() {
    cameraEnabled.value = !cameraEnabled.value
    for (const track of localStream.value?.getVideoTracks() || [])
      track.enabled = cameraEnabled.value
  }
  return {
    room,
    localStream,
    remoteStreams,
    peerStates,
    joining,
    joined,
    error,
    micEnabled,
    cameraEnabled,
    join,
    leave,
    toggleMic,
    toggleCamera,
  }
})
