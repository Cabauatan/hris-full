import { computed, onScopeDispose, ref, watch } from 'vue'
import { defineStore } from 'pinia'
import http, { apiError } from '@/api/http'
import { useAuthStore } from '@/stores/auth'
import { useApplicantStore } from '@/stores/applicant'
import { connectInterview, interviewPath } from '@/realtime/interview'

export const useInterviewChatStore = defineStore('interviewChat', () => {
  const auth = useAuthStore()
  const applicant = useApplicantStore()
  const messages = ref([])
  const draft = ref('')
  const loading = ref(false)
  const loadingEarlier = ref(false)
  const sending = ref(false)
  const hasEarlier = ref(false)
  const sendAllowed = ref(false)
  const live = ref(false)
  const error = ref('')
  const sendError = ref('')
  const active = ref(null)
  let version = 0
  let lifetime
  let connection
  let poll
  let catchup
  let syncedThrough = 0
  let attempt
  let needsCatchup = false
  let closedByEvent = false

  function identity(scope) {
    const account = scope === 'applicant' ? applicant : auth
    return account.authenticated &&
      Number.isSafeInteger(Number(account.user?.id)) &&
      Number(account.user.id) > 0
      ? Number(account.user.id)
      : null
  }

  const canSend = computed(() => !!active.value && sendAllowed.value && !loading.value)
  const ownKey = computed(() =>
    active.value ? `${active.value.scope}:${active.value.actorId}` : '',
  )

  function closeConnection() {
    connection?.close()
    connection = undefined
    live.value = false
  }

  function reset() {
    version += 1
    lifetime?.abort()
    lifetime = undefined
    clearInterval(poll)
    poll = undefined
    closeConnection()
    catchup = undefined
    syncedThrough = 0
    attempt = undefined
    needsCatchup = false
    closedByEvent = false
    active.value = null
    messages.value = []
    draft.value = ''
    loading.value = false
    loadingEarlier.value = false
    sending.value = false
    hasEarlier.value = false
    sendAllowed.value = false
    error.value = ''
    sendError.value = ''
  }

  function context() {
    const currentVersion = version
    const room = active.value
    const controller = lifetime
    return {
      room,
      signal: controller?.signal,
      current: () =>
        !!room &&
        !controller?.signal.aborted &&
        version === currentVersion &&
        identity(room.scope) === room.actorId,
    }
  }

  function validMessage(value, uuid) {
    const sender = value?.sender
    return (
      Number.isSafeInteger(value?.id) &&
      value.id > 0 &&
      value.interview_uuid === uuid &&
      typeof value.body === 'string' &&
      value.body.length <= 2000 &&
      typeof value.client_message_id === 'string' &&
      ['user', 'applicant'].includes(sender?.kind) &&
      Number.isSafeInteger(sender.id) &&
      sender.id > 0 &&
      sender.key === `${sender.kind}:${sender.id}` &&
      typeof sender.name === 'string' &&
      typeof value.created_at === 'string' &&
      Number.isFinite(Date.parse(value.created_at))
    )
  }

  function merge(values, uuid) {
    const records = new Map(messages.value.map((item) => [item.id, item]))
    for (const value of values) {
      if (!validMessage(value, uuid)) continue
      records.set(value.id, {
        id: value.id,
        interview_uuid: uuid,
        body: value.body,
        client_message_id: value.client_message_id,
        sender: {
          kind: value.sender.kind,
          id: value.sender.id,
          key: value.sender.key,
          name: value.sender.name,
        },
        created_at: value.created_at,
      })
    }
    messages.value = [...records.values()].sort((left, right) => left.id - right.id)
  }

  function denied(failure) {
    if (![401, 403, 404].includes(failure.response?.status)) return false
    const message = apiError(failure).message
    reset()
    error.value = message
    return true
  }

  function applyAvailability(value, request) {
    sendAllowed.value = value === true && !closedByEvent
    if (!sendAllowed.value) closeConnection()
    else if (request.current()) connect(request)
  }

  function connect(request) {
    if (connection || !request.current() || !sendAllowed.value) return
    try {
      connection = connectInterview({
        uuid: request.room.uuid,
        scope: request.room.scope,
        onMessage(event) {
          if (!request.current() || event?.interview_uuid !== request.room.uuid) return
          refresh()
        },
        onEnded(event) {
          if (!request.current() || event?.interview_uuid !== request.room.uuid) return
          closedByEvent = true
          sendAllowed.value = false
          closeConnection()
          refresh()
        },
        onReady() {
          if (!request.current()) return
          live.value = true
          refresh()
        },
        onError(failure) {
          if (!request.current()) return
          if ([401, 403, 404].includes(failure.response?.status)) {
            // A closed room can still have a readable transcript. Recheck its history.
            sendAllowed.value = false
          }
          closeConnection()
        },
      })
    } catch {
      // History polling continues when the realtime connection is unavailable.
      live.value = false
    }
  }

  async function fetchPage(request, params) {
    const { data } = await http.get(
      `${interviewPath(request.room.scope, request.room.id)}/messages`,
      {
        params,
        signal: request.signal,
      },
    )
    if (!request.current()) return null
    if (!Array.isArray(data.data) || typeof data.meta?.can_send !== 'boolean')
      throw new Error('Invalid interview message response')
    merge(data.data, request.room.uuid)
    applyAvailability(data.meta.can_send, request)
    return data
  }

  async function refresh() {
    if (!active.value) return false
    needsCatchup = true
    if (loading.value) return false
    if (catchup) return catchup
    const request = context()
    const operation = Promise.resolve().then(async () => {
      try {
        let rounds = 0
        do {
          needsCatchup = false
          for (let page = 0; page < 10 && request.current(); page += 1) {
            const data = await fetchPage(request, { after_id: syncedThrough })
            if (!data) return false
            error.value = ''
            const ids = data.data
              .filter((item) => validMessage(item, request.room.uuid))
              .map((item) => item.id)
            const previous = syncedThrough
            syncedThrough = Math.max(syncedThrough, ...ids)
            if (!data.meta.has_more || syncedThrough <= previous) break
          }
          rounds += 1
        } while (needsCatchup && request.current() && rounds < 3)
        return request.current()
      } catch (failure) {
        if (request.current() && failure.code !== 'ERR_CANCELED' && !denied(failure))
          error.value = apiError(failure).message
        return false
      }
    })
    catchup = operation
    try {
      return await operation
    } finally {
      if (catchup === operation) catchup = undefined
    }
  }

  async function open(scope, interview) {
    reset()
    const actorId = identity(scope)
    if (
      !['user', 'applicant'].includes(scope) ||
      !actorId ||
      !Number.isSafeInteger(Number(interview?.id)) ||
      Number(interview.id) < 1 ||
      typeof interview?.uuid !== 'string' ||
      !interview.uuid
    )
      return false
    active.value = { scope, actorId, id: Number(interview.id), uuid: interview.uuid }
    lifetime = new AbortController()
    const request = context()
    loading.value = true
    poll = setInterval(refresh, 10000)
    try {
      const data = await fetchPage(request)
      if (!data) return false
      hasEarlier.value = data.meta.has_more === true
      const ids = data.data
        .filter((item) => validMessage(item, request.room.uuid))
        .map((item) => item.id)
      syncedThrough = Math.max(0, ...ids)
      return true
    } catch (failure) {
      if (request.current() && failure.code !== 'ERR_CANCELED' && !denied(failure))
        error.value = apiError(failure).message
      return false
    } finally {
      if (request.current()) {
        loading.value = false
        if (needsCatchup) refresh()
      }
    }
  }

  async function earlier() {
    if (!active.value || loading.value || loadingEarlier.value || !hasEarlier.value) return false
    const request = context()
    const first = messages.value[0]?.id
    if (!first) return false
    loadingEarlier.value = true
    try {
      const data = await fetchPage(request, { before_id: first })
      if (!data) return false
      hasEarlier.value = data.meta.has_more === true
      error.value = ''
      return true
    } catch (failure) {
      if (request.current() && failure.code !== 'ERR_CANCELED' && !denied(failure))
        error.value = apiError(failure).message
      return false
    } finally {
      if (request.current()) loadingEarlier.value = false
    }
  }

  async function send() {
    if (!canSend.value || sending.value) return false
    const original = draft.value
    const body = original.trim()
    sendError.value = ''
    if (!body || new TextEncoder().encode(body).length > 2000) {
      sendError.value = body
        ? 'Your message is too long. Shorten it and try again.'
        : 'Enter a message.'
      return false
    }
    const request = context()
    if (!attempt || attempt.body !== body) {
      try {
        attempt = { body, client_message_id: crypto.randomUUID() }
      } catch {
        sendError.value = 'Unable to prepare your message. Reload this page and try again.'
        return false
      }
    }
    const submitted = attempt
    sending.value = true
    try {
      const { data } = await http.post(
        `${interviewPath(request.room.scope, request.room.id)}/messages`,
        submitted,
        { signal: request.signal },
      )
      if (!request.current()) return false
      if (!validMessage(data.data, request.room.uuid)) throw new Error('Invalid interview message')
      merge([data.data], request.room.uuid)
      if (draft.value === original) draft.value = ''
      if (attempt === submitted) attempt = undefined
      refresh()
      return true
    } catch (failure) {
      if (request.current() && failure.code !== 'ERR_CANCELED') {
        if ([401, 404].includes(failure.response?.status)) denied(failure)
        else {
          sendError.value = apiError(failure).message
          if (failure.response?.status === 403) {
            sendAllowed.value = false
            closeConnection()
            refresh()
          }
        }
      }
      return false
    } finally {
      if (request.current()) sending.value = false
    }
  }

  watch(
    () => [identity('user'), identity('applicant')],
    () => {
      if (active.value && identity(active.value.scope) !== active.value.actorId) reset()
    },
    { flush: 'sync' },
  )
  onScopeDispose(reset)

  return {
    messages,
    draft,
    loading,
    loadingEarlier,
    sending,
    hasEarlier,
    canSend,
    live,
    error,
    sendError,
    ownKey,
    open,
    refresh,
    earlier,
    send,
    reset,
  }
})
