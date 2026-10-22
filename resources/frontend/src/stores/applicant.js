import { computed, ref, watch } from 'vue'
import { defineStore } from 'pinia'
import http, { apiError, resetAuthSession } from '@/api/http'

export const useApplicantStore = defineStore('applicant', () => {
  const user = ref(null)
  const initialized = ref(false)
  const connectionError = ref('')
  const sessionBlocked = ref(false)
  const applications = ref([])
  const pagination = ref({ current_page: 1, last_page: 1, total: 0 })
  const loading = ref(false)
  const saving = ref(false)
  const error = ref('')
  const fieldErrors = ref({})
  const attempt = ref(null)
  const examLoading = ref(false)
  const interviews = ref({})
  let sessionVersion = 0
  let listVersion = 0
  let examVersion = 0
  let initializing
  let revalidating
  let listController
  let examController
  const authenticated = computed(() => user.value?.is_active === true)
  const identity = computed(() => (authenticated.value ? String(user.value.id) : ''))
  const hasActiveApplication = computed(() =>
    applications.value.some((item) => ['pending', 'approved', 'hired'].includes(item.status)),
  )

  function invalidate() {
    sessionVersion += 1
    initializing = undefined
    revalidating = undefined
    resetAuthSession('applicant')
    return sessionVersion
  }
  function clearRecords() {
    listVersion += 1
    examVersion += 1
    listController?.abort()
    examController?.abort()
    applications.value = []
    pagination.value = { current_page: 1, last_page: 1, total: 0 }
    attempt.value = null
    interviews.value = {}
    loading.value = false
    saving.value = false
    examLoading.value = false
    error.value = ''
    fieldErrors.value = {}
  }
  watch(identity, clearRecords, { flush: 'sync' })
  function current(version, account) {
    return version === sessionVersion && account !== '' && account === identity.value
  }
  function clearSession() {
    invalidate()
    user.value = null
    initialized.value = true
    connectionError.value = ''
    sessionBlocked.value = false
    clearRecords()
  }
  async function initialize(force = false) {
    if (initialized.value && !force) return user.value
    if (!initializing) {
      const version = sessionVersion
      const request = http
        .get('/applicant/auth/me', { skipAuthEvent: true })
        .then(({ data }) => {
          if (version !== sessionVersion) return user.value
          user.value = data.data
          connectionError.value = ''
          sessionBlocked.value = false
          return user.value
        })
        .catch((failure) => {
          if (version !== sessionVersion) return user.value
          user.value = null
          sessionBlocked.value = failure.response?.status === 403
          connectionError.value = sessionBlocked.value
            ? 'Your applicant account is inactive. Sign out this applicant session or contact HR.'
            : failure.response?.status === 401
              ? ''
              : 'Unable to connect to the applicant portal. Please try again.'
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
  async function authenticate(action, payload) {
    const version = invalidate()
    const { data } = await http.post(`/applicant/auth/${action}`, payload, { skipAuthEvent: true })
    if (version !== sessionVersion) return user.value
    invalidate()
    user.value = data.data
    initialized.value = true
    connectionError.value = ''
    sessionBlocked.value = false
    return user.value
  }
  function revalidateSession() {
    if (!authenticated.value) return Promise.resolve(false)
    if (revalidating) return revalidating
    const version = sessionVersion
    const account = identity.value
    const request = http
      .get('/applicant/auth/me', { skipAuthEvent: true })
      .then(() => current(version, account))
      .catch((failure) => {
        if (current(version, account) && failure.response?.status === 401) {
          clearSession()
          globalThis.window?.dispatchEvent(new Event('hris:applicant-session-expired'))
        }
        return false
      })
      .finally(() => {
        if (revalidating === request) revalidating = undefined
      })
    revalidating = request
    return request
  }
  const signIn = (payload) => authenticate('login', payload)
  const register = (payload) => authenticate('register', payload)
  async function signOut() {
    const version = invalidate()
    try {
      await http.post('/applicant/auth/logout', undefined, { skipAuthEvent: true })
    } catch (failure) {
      if (failure.response?.status !== 401) throw failure
    }
    if (version === sessionVersion) clearSession()
  }
  async function loadApplications(page = 1) {
    const account = identity.value
    if (!account) return
    const session = sessionVersion
    const version = ++listVersion
    listController?.abort()
    const controller = new AbortController()
    listController = controller
    loading.value = true
    error.value = ''
    try {
      const { data } = await http.get('/applicant/applications', {
        params: { page, per_page: 15 },
        signal: controller.signal,
      })
      if (!current(session, account) || version !== listVersion) return
      applications.value = data.data
      pagination.value = data.meta || pagination.value
      await Promise.all(applications.value.map((application) => loadInterviews(application.id)))
    } catch (failure) {
      if (current(session, account) && version === listVersion && failure.code !== 'ERR_CANCELED')
        error.value = apiError(failure).message
    } finally {
      if (current(session, account) && version === listVersion) loading.value = false
    }
  }
  async function apply(payload) {
    if (!identity.value || saving.value || hasActiveApplication.value) return null
    const account = identity.value
    const session = sessionVersion
    saving.value = true
    error.value = ''
    fieldErrors.value = {}
    try {
      const { data } = await http.post('/applicant/applications', {
        job_opening_id: payload.job_opening_id,
        cover_letter: payload.cover_letter || null,
      })
      if (!current(session, account)) return null
      await loadApplications()
      return data.data
    } catch (failure) {
      if (current(session, account)) {
        const detail = apiError(failure)
        error.value = detail.message
        fieldErrors.value = detail.errors
      }
      return null
    } finally {
      if (current(session, account)) saving.value = false
    }
  }
  async function loadExam(id) {
    const account = identity.value
    const session = sessionVersion
    const version = ++examVersion
    examController?.abort()
    attempt.value = null
    saving.value = false
    examLoading.value = false
    error.value = ''
    if (!account) return
    if (!/^\d+$/.test(String(id))) {
      error.value = 'This assessment is unavailable.'
      return
    }
    const controller = new AbortController()
    examController = controller
    examLoading.value = true
    try {
      const { data } = await http.get(`/applicant/exams/${id}`, { signal: controller.signal })
      if (current(session, account) && version === examVersion) attempt.value = data.data
    } catch (failure) {
      if (current(session, account) && version === examVersion && failure.code !== 'ERR_CANCELED')
        error.value = apiError(failure).message
    } finally {
      if (current(session, account) && version === examVersion) examLoading.value = false
    }
  }
  async function submitExam(answers) {
    if (!identity.value || saving.value || !attempt.value?.abilities?.submit) return false
    const account = identity.value
    const session = sessionVersion
    const version = examVersion
    const id = attempt.value.id
    saving.value = true
    error.value = ''
    fieldErrors.value = {}
    try {
      const { data } = await http.post(`/applicant/exams/${id}/submit`, { answers })
      if (!current(session, account) || version !== examVersion || attempt.value?.id !== id)
        return false
      attempt.value = data.data
      return true
    } catch (failure) {
      if (current(session, account) && version === examVersion) {
        const detail = apiError(failure)
        error.value = detail.message
        fieldErrors.value = detail.errors
      }
      return false
    } finally {
      if (current(session, account) && version === examVersion) saving.value = false
    }
  }
  async function loadInterviews(applicationId) {
    const account = identity.value
    const session = sessionVersion
    try {
      const { data } = await http.get(`/applicant/applications/${applicationId}/interviews`)
      if (current(session, account))
        interviews.value = { ...interviews.value, [applicationId]: data.data }
    } catch (failure) {
      if (current(session, account)) error.value = apiError(failure).message
    }
  }
  return {
    user,
    initialized,
    authenticated,
    connectionError,
    sessionBlocked,
    applications,
    pagination,
    loading,
    saving,
    error,
    fieldErrors,
    attempt,
    examLoading,
    interviews,
    hasActiveApplication,
    initialize,
    revalidateSession,
    signIn,
    register,
    signOut,
    clearSession,
    loadApplications,
    apply,
    loadExam,
    submitExam,
    loadInterviews,
  }
})
