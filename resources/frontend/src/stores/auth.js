import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import http, { resetAuthSession } from '@/api/http'

export const useAuthStore = defineStore('auth', () => {
  const user = ref(null)
  const initialized = ref(false)
  const connectionError = ref('')
  let initializing
  let revalidating
  let sessionVersion = 0
  const employee = computed(() => user.value?.employee || null)
  const permissions = computed(() => user.value?.permissions || [])
  const roles = computed(() => user.value?.roles || [])
  const authenticated = computed(() => user.value?.is_active === true)
  const can = (permission) => authenticated.value && permissions.value.includes(permission)
  const canAny = (list = []) => list.length === 0 || list.some(can)

  function invalidateRequests() {
    sessionVersion += 1
    initializing = undefined
    revalidating = undefined
    resetAuthSession()
    return sessionVersion
  }

  function clearSession() {
    invalidateRequests()
    user.value = null
    initialized.value = true
    connectionError.value = ''
  }

  async function initialize(force = false) {
    if (initialized.value && !force) return user.value
    if (!initializing) {
      const version = sessionVersion
      const request = http
        .get('/auth/me', { skipAuthEvent: true })
        .then(({ data }) => {
          if (version !== sessionVersion) return user.value
          user.value = data.data
          connectionError.value = ''
          return user.value
        })
        .catch((error) => {
          if (version !== sessionVersion) return user.value
          user.value = null
          connectionError.value = ''
          if (error.response?.status !== 401) {
            connectionError.value =
              'Unable to connect to HRIS. Check your connection and try again.'
          }
          return null
        })
        .finally(() => {
          if (version === sessionVersion) initialized.value = true
          if (initializing === request) initializing = undefined
        })
      initializing = request
    }
    return initializing
  }

  async function signIn(payload) {
    const version = invalidateRequests()
    const { data } = await http.post('/auth/login', payload, { skipAuthEvent: true })
    if (version !== sessionVersion) return user.value
    invalidateRequests()
    user.value = data.data
    initialized.value = true
    connectionError.value = ''
    return user.value
  }

  function revalidateSession() {
    if (!authenticated.value) return Promise.resolve(false)
    if (revalidating) return revalidating
    const version = sessionVersion
    const account = user.value.id
    const current = () => version === sessionVersion && user.value?.id === account
    const request = http
      .get('/auth/me', { skipAuthEvent: true })
      .then(() => current() && authenticated.value)
      .catch((failure) => {
        if (current() && failure.response?.status === 401) {
          clearSession()
          globalThis.window?.dispatchEvent(new Event('hris:session-expired'))
        }
        return false
      })
      .finally(() => {
        if (revalidating === request) revalidating = undefined
      })
    revalidating = request
    return request
  }

  async function signOut() {
    const version = invalidateRequests()
    try {
      await http.post('/auth/logout', undefined, { skipAuthEvent: true })
    } catch (error) {
      if (error.response?.status !== 401) throw error
    }
    if (version === sessionVersion) clearSession()
  }

  async function changePassword(payload) {
    const version = sessionVersion
    const { data } = await http.put('/auth/password', payload)
    if (version !== sessionVersion) return
    invalidateRequests()
    user.value = data.data
  }

  return {
    user,
    employee,
    permissions,
    roles,
    authenticated,
    initialized,
    connectionError,
    can,
    canAny,
    initialize,
    revalidateSession,
    signIn,
    signOut,
    changePassword,
    clearSession,
  }
})
