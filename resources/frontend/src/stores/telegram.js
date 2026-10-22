import { computed, onScopeDispose, reactive, watch } from 'vue'
import { defineStore } from 'pinia'
import http, { apiError } from '@/api/http'
import { useAuthStore } from '@/stores/auth'
import { useApplicantStore } from '@/stores/applicant'
import { connectTelegramRealtime } from '@/realtime/telegram'

const scopes = ['employee', 'applicant']
const emptyState = () => ({
  status: null,
  loading: false,
  busy: false,
  error: '',
  link: null,
  linkExpired: false,
})

function validLink(payload, username) {
  if (typeof payload?.url !== 'string' || typeof payload?.expires_at !== 'string') return null
  const expiresAt = Date.parse(payload.expires_at)
  if (!Number.isFinite(expiresAt) || expiresAt <= Date.now()) return null
  try {
    const url = new URL(payload.url)
    const parameters = [...url.searchParams.entries()]
    if (
      url.protocol !== 'https:' ||
      url.host !== 't.me' ||
      url.username ||
      url.password ||
      url.hash ||
      url.pathname !== `/${username}` ||
      parameters.length !== 1 ||
      parameters[0][0] !== 'start' ||
      !/^[A-Za-z0-9_-]{43}$/.test(parameters[0][1])
    )
      return null
    return { url: url.href, expires_at: payload.expires_at }
  } catch {
    return null
  }
}

export const useTelegramStore = defineStore('telegram', () => {
  const auth = useAuthStore()
  const applicant = useApplicantStore()
  const states = reactive({ employee: emptyState(), applicant: emptyState() })
  const accounts = {
    employee: computed(() => (auth.authenticated ? String(auth.user?.id || '') : '')),
    applicant: computed(() => (applicant.authenticated ? String(applicant.user?.id || '') : '')),
  }
  const requests = {
    employee: { version: 0 },
    applicant: { version: 0 },
  }
  const connections = { employee: new Set(), applicant: new Set() }
  const identity = (scope) => accounts[scope]?.value || ''
  const endpoint = (scope) => `${scope === 'applicant' ? '/applicant' : ''}/auth/telegram`

  function clearLink(scope, expired = false) {
    clearTimeout(requests[scope].timer)
    requests[scope].timer = undefined
    states[scope].link = null
    states[scope].linkExpired = expired
  }

  function reset(scope) {
    for (const close of [...connections[scope]]) close()
    const request = requests[scope]
    request.version += 1
    request.controller?.abort()
    request.controller = undefined
    clearLink(scope)
    Object.assign(states[scope], emptyState())
  }

  function begin(scope, action) {
    const request = requests[scope]
    request.controller?.abort()
    const controller = new AbortController()
    request.controller = controller
    const version = ++request.version
    const account = identity(scope)
    const current = () =>
      account !== '' && account === identity(scope) && version === request.version
    states[scope].loading = action === 'load'
    states[scope].busy = action !== 'load'
    states[scope].error = ''
    return { controller, current }
  }

  async function load(scope, { signal } = {}) {
    if (!scopes.includes(scope) || !identity(scope) || states[scope].busy || signal?.aborted)
      return false
    const state = states[scope]
    const request = begin(scope, 'load')
    const { controller } = request
    const current = () => request.current() && !signal?.aborted
    const abort = () => controller.abort()
    signal?.addEventListener('abort', abort, { once: true })
    try {
      const { data } = await http.get(endpoint(scope), { signal: controller.signal })
      if (!current()) return false
      const status = data.data
      if (
        typeof status?.configured !== 'boolean' ||
        typeof status?.connected !== 'boolean' ||
        (status.configured && !/^[A-Za-z][A-Za-z0-9_]{4,31}$/.test(status.bot_username || ''))
      )
        throw new Error('Invalid Telegram status')
      if (state.status?.bot_username !== status.bot_username) clearLink(scope)
      state.status = {
        configured: status.configured,
        bot_username: status.configured ? status.bot_username : null,
        connected: status.connected,
        linked_at: typeof status.linked_at === 'string' ? status.linked_at : null,
      }
      if (status.connected || !status.configured) clearLink(scope)
      return true
    } catch (failure) {
      if (current() && failure.code !== 'ERR_CANCELED') state.error = apiError(failure).message
      return false
    } finally {
      signal?.removeEventListener('abort', abort)
      if (request.current()) {
        state.loading = false
        requests[scope].controller = undefined
      }
    }
  }

  function watchConnection(scope) {
    const account = identity(scope)
    if (!scopes.includes(scope) || !account) return () => {}
    const lifetime = new AbortController()
    let refreshing = false
    let dirty = false
    const current = () => !lifetime.signal.aborted && account === identity(scope)
    async function refresh() {
      if (!current()) return
      dirty = true
      if (refreshing || states[scope].busy) return
      refreshing = true
      try {
        while (dirty && current() && !states[scope].busy) {
          dirty = false
          await load(scope, { signal: lifetime.signal })
        }
      } finally {
        refreshing = false
      }
    }
    const stopBusyWatch = watch(
      () => states[scope].busy,
      (busy) => {
        if (!busy && dirty) refresh()
      },
      { flush: 'sync' },
    )
    const connection = connectTelegramRealtime({
      scope,
      account,
      isCurrent: current,
      onChanged: refresh,
      onReady: refresh,
    })
    const close = () => {
      if (lifetime.signal.aborted) return
      lifetime.abort()
      stopBusyWatch()
      connection?.close()
      connections[scope].delete(close)
    }
    connections[scope].add(close)
    refresh()
    return close
  }

  async function connect(scope) {
    if (
      !scopes.includes(scope) ||
      !identity(scope) ||
      states[scope].busy ||
      !states[scope].status?.configured ||
      states[scope].status.connected
    )
      return false
    const state = states[scope]
    const { controller, current } = begin(scope, 'connect')
    clearLink(scope)
    try {
      const { data } = await http.post(`${endpoint(scope)}/link`, undefined, {
        signal: controller.signal,
      })
      if (!current()) return false
      const link = validLink(data.data, state.status.bot_username)
      if (!link) {
        state.error = 'Unable to open the Telegram connection link. Please try again.'
        return false
      }
      state.link = link
      requests[scope].timer = setTimeout(
        () => clearLink(scope, true),
        Date.parse(link.expires_at) - Date.now(),
      )
      requests[scope].timer?.unref?.()
      return true
    } catch (failure) {
      if (current() && failure.code !== 'ERR_CANCELED') state.error = apiError(failure).message
      return false
    } finally {
      if (current()) {
        requests[scope].controller = undefined
        state.busy = false
      }
    }
  }

  async function disconnect(scope) {
    if (!scopes.includes(scope) || !identity(scope) || states[scope].busy) return false
    const state = states[scope]
    const { controller, current } = begin(scope, 'disconnect')
    try {
      await http.delete(endpoint(scope), { signal: controller.signal })
      if (!current()) return false
      clearLink(scope)
      if (state.status) state.status = { ...state.status, connected: false, linked_at: null }
      return true
    } catch (failure) {
      if (current() && failure.code !== 'ERR_CANCELED') state.error = apiError(failure).message
      return false
    } finally {
      if (current()) {
        requests[scope].controller = undefined
        state.busy = false
      }
    }
  }

  for (const scope of scopes) watch(accounts[scope], () => reset(scope), { flush: 'sync' })
  onScopeDispose(() => scopes.forEach(reset))

  return { states, identity, load, watchConnection, connect, disconnect }
})
