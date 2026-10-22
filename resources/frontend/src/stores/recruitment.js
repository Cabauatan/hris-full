import { computed, ref, watch } from 'vue'
import { defineStore } from 'pinia'
import http, { apiError } from '@/api/http'
import { useAuthStore } from '@/stores/auth'

export const useRecruitmentStore = defineStore('recruitment', () => {
  const auth = useAuthStore()
  const applications = ref([])
  const exams = ref([])
  const interviewers = ref([])
  const interviews = ref([])
  const selected = ref(null)
  const attempt = ref(null)
  const pagination = ref({ current_page: 1, last_page: 1, total: 0 })
  const loading = ref(false)
  const detailLoading = ref(false)
  const busy = ref(false)
  const error = ref('')
  const fieldErrors = ref({})
  const jobOpenings = ref([])
  const openingPagination = ref({ current_page: 1, last_page: 1, total: 0 })
  const openingsLoading = ref(false)
  const openingsBusy = ref(false)
  const openingsError = ref('')
  const openingErrors = ref({})
  let epoch = 0
  let listVersion = 0
  let selectionVersion = 0
  let attemptVersion = 0
  let catalogVersion = 0
  let openingListVersion = 0
  let openingMutationVersion = 0
  const account = computed(() => (auth.authenticated ? String(auth.user?.id || '') : ''))
  const canManage = computed(() => auth.can('recruitment.manage'))
  const canGrade = computed(() => auth.can('recruitment.grade'))
  function clear() {
    epoch += 1
    listVersion += 1
    selectionVersion += 1
    attemptVersion += 1
    catalogVersion += 1
    openingListVersion += 1
    openingMutationVersion += 1
    applications.value = []
    exams.value = []
    interviewers.value = []
    interviews.value = []
    selected.value = null
    attempt.value = null
    loading.value = false
    detailLoading.value = false
    busy.value = false
    error.value = ''
    fieldErrors.value = {}
    pagination.value = { current_page: 1, last_page: 1, total: 0 }
    jobOpenings.value = []
    openingPagination.value = { current_page: 1, last_page: 1, total: 0 }
    openingsLoading.value = false
    openingsBusy.value = false
    openingsError.value = ''
    openingErrors.value = {}
  }
  watch(account, clear, { flush: 'sync' })
  const current = (version, identity) =>
    version === epoch && identity !== '' && identity === account.value
  function failure(errorValue) {
    const detail = apiError(errorValue)
    error.value = detail.message
    fieldErrors.value = detail.errors
  }
  async function loadJobOpenings(filters = {}, page = 1) {
    if (!account.value || !auth.can('recruitment.view')) return
    const identity = account.value
    const version = epoch
    const request = ++openingListVersion
    openingsLoading.value = true
    openingsError.value = ''
    try {
      const { data } = await http.get('/recruitment/job-openings', {
        params: {
          page,
          per_page: 15,
          ...(filters.status ? { status: filters.status } : {}),
          ...(filters.search?.trim() ? { search: filters.search.trim() } : {}),
        },
      })
      if (current(version, identity) && request === openingListVersion) {
        jobOpenings.value = data.data
        openingPagination.value = data.meta || openingPagination.value
      }
    } catch (problem) {
      if (current(version, identity) && request === openingListVersion) {
        jobOpenings.value = []
        openingPagination.value = { current_page: 1, last_page: 1, total: 0 }
        openingsError.value = apiError(problem).message
      }
    } finally {
      if (current(version, identity) && request === openingListVersion)
        openingsLoading.value = false
    }
  }
  async function saveJobOpening(payload, opening = null) {
    if (
      !account.value ||
      !canManage.value ||
      openingsBusy.value ||
      (opening && !opening.abilities?.update)
    )
      return null
    const identity = account.value
    const version = epoch
    const request = ++openingMutationVersion
    openingsBusy.value = true
    openingsError.value = ''
    openingErrors.value = {}
    try {
      const { data } = opening
        ? await http.put(`/recruitment/job-openings/${opening.id}`, payload)
        : await http.post('/recruitment/job-openings', payload)
      if (current(version, identity) && request === openingMutationVersion) return data.data
    } catch (problem) {
      if (current(version, identity) && request === openingMutationVersion) {
        const detail = apiError(problem)
        openingsError.value = detail.message
        openingErrors.value = detail.errors
      }
    } finally {
      if (current(version, identity) && request === openingMutationVersion)
        openingsBusy.value = false
    }
    return null
  }
  async function loadApplications(filters = {}, page = 1) {
    if (!account.value || !auth.can('recruitment.view')) return
    const identity = account.value
    const version = epoch
    const list = ++listVersion
    loading.value = true
    error.value = ''
    const params = {
      per_page: 15,
      page,
      ...(filters.status ? { status: filters.status } : {}),
      ...(filters.search?.trim() ? { search: filters.search.trim() } : {}),
    }
    try {
      const { data } = await http.get('/recruitment/applications', { params })
      if (current(version, identity) && list === listVersion) {
        applications.value = data.data
        pagination.value = data.meta || pagination.value
      }
    } catch (problem) {
      if (current(version, identity) && list === listVersion) failure(problem)
    } finally {
      if (current(version, identity) && list === listVersion) loading.value = false
    }
  }
  async function loadCatalogs() {
    if (!account.value) return
    const identity = account.value
    const version = epoch
    const catalog = ++catalogVersion
    try {
      const [{ data: examData }, { data: interviewerData }] = await Promise.all([
        http.get('/recruitment/exams', { params: { per_page: 100 } }),
        http.get('/recruitment/interviewers'),
      ])
      const allExams = [...examData.data]
      for (let page = 2; page <= (examData.meta?.last_page || 1); page += 1) {
        if (!current(version, identity) || catalog !== catalogVersion) return
        const { data } = await http.get('/recruitment/exams', { params: { per_page: 100, page } })
        allExams.push(...data.data)
      }
      if (current(version, identity) && catalog === catalogVersion) {
        exams.value = allExams
        interviewers.value = interviewerData.data
      }
    } catch (problem) {
      if (current(version, identity) && catalog === catalogVersion) failure(problem)
    }
  }
  async function selectApplication(id) {
    const identity = account.value
    const version = epoch
    const selection = ++selectionVersion
    attemptVersion += 1
    selected.value = null
    attempt.value = null
    interviews.value = []
    error.value = ''
    fieldErrors.value = {}
    busy.value = false
    if (!identity) return
    detailLoading.value = true
    try {
      const [{ data: applicationData }, { data: interviewData }] = await Promise.all([
        http.get(`/recruitment/applications/${id}`),
        http.get(`/recruitment/applications/${id}/interviews`),
      ])
      if (current(version, identity) && selection === selectionVersion) {
        selected.value = applicationData.data
        interviews.value = interviewData.data
      }
    } catch (problem) {
      if (current(version, identity) && selection === selectionVersion) failure(problem)
    } finally {
      if (current(version, identity) && selection === selectionVersion) detailLoading.value = false
    }
  }
  async function applicationAction(action, payload = {}) {
    const abilities = {
      approve: 'approve',
      reject: 'reject',
      hire: 'hire',
      exams: 'assign_exam',
      interviews: 'schedule_interview',
    }
    if (!account.value || busy.value || !selected.value?.abilities?.[abilities[action]])
      return false
    const identity = account.value
    const version = epoch
    const selection = selectionVersion
    const id = selected.value.id
    busy.value = true
    error.value = ''
    fieldErrors.value = {}
    try {
      await http.post(`/recruitment/applications/${id}/${action}`, payload)
      if (
        !current(version, identity) ||
        selection !== selectionVersion ||
        selected.value?.id !== id
      )
        return false
      await selectApplication(id)
      return true
    } catch (problem) {
      if (current(version, identity) && selection === selectionVersion) failure(problem)
      return false
    } finally {
      if (current(version, identity) && selection === selectionVersion) busy.value = false
    }
  }
  async function createExam(payload) {
    if (!account.value || !canManage.value || busy.value) return false
    const identity = account.value
    const version = epoch
    busy.value = true
    error.value = ''
    fieldErrors.value = {}
    try {
      await http.post('/recruitment/exams', payload)
      if (!current(version, identity)) return false
      await loadCatalogs()
      return true
    } catch (problem) {
      if (current(version, identity)) failure(problem)
      return false
    } finally {
      if (current(version, identity)) busy.value = false
    }
  }
  async function loadAttempt(id) {
    const identity = account.value
    const version = epoch
    const selection = selectionVersion
    const request = ++attemptVersion
    attempt.value = null
    busy.value = false
    error.value = ''
    fieldErrors.value = {}
    if (!identity) return
    try {
      const { data } = await http.get(`/recruitment/attempts/${id}`)
      if (
        current(version, identity) &&
        selection === selectionVersion &&
        request === attemptVersion
      )
        attempt.value = data.data
    } catch (problem) {
      if (
        current(version, identity) &&
        selection === selectionVersion &&
        request === attemptVersion
      )
        failure(problem)
    }
  }
  async function gradeAttempt(grades) {
    if (!account.value || busy.value || !attempt.value?.abilities?.grade) return false
    const identity = account.value
    const version = epoch
    const selection = selectionVersion
    const request = attemptVersion
    const id = attempt.value.id
    busy.value = true
    error.value = ''
    fieldErrors.value = {}
    try {
      const { data } = await http.post(`/recruitment/attempts/${id}/grade`, { grades })
      if (
        !current(version, identity) ||
        selection !== selectionVersion ||
        request !== attemptVersion ||
        attempt.value?.id !== id
      )
        return false
      attempt.value = data.data
      const index = selected.value?.attempts?.findIndex((item) => item.id === id)
      if (index >= 0) selected.value.attempts[index] = data.data
      const applicationId = selected.value?.id
      if (applicationId) {
        const { data: applicationData } = await http.get(
          `/recruitment/applications/${applicationId}`,
        )
        if (
          !current(version, identity) ||
          selection !== selectionVersion ||
          request !== attemptVersion
        )
          return false
        selected.value = applicationData.data
      }
      return true
    } catch (problem) {
      if (
        current(version, identity) &&
        selection === selectionVersion &&
        request === attemptVersion
      )
        failure(problem)
      return false
    } finally {
      if (
        current(version, identity) &&
        selection === selectionVersion &&
        request === attemptVersion
      )
        busy.value = false
    }
  }
  async function interviewAction(interview, action, payload = {}) {
    if (
      !account.value ||
      busy.value ||
      !interview?.abilities?.[action] ||
      !['complete', 'cancel'].includes(action)
    )
      return false
    const identity = account.value
    const version = epoch
    const selection = selectionVersion
    const applicationId = selected.value?.id
    busy.value = true
    error.value = ''
    fieldErrors.value = {}
    try {
      await http.post(`/recruitment/interviews/${interview.id}/${action}`, payload)
      if (!current(version, identity) || selection !== selectionVersion) return false
      await selectApplication(applicationId)
      return true
    } catch (problem) {
      if (current(version, identity) && selection === selectionVersion) failure(problem)
      return false
    } finally {
      if (current(version, identity) && selection === selectionVersion) busy.value = false
    }
  }
  return {
    applications,
    exams,
    interviewers,
    interviews,
    selected,
    attempt,
    pagination,
    loading,
    detailLoading,
    busy,
    error,
    fieldErrors,
    canManage,
    canGrade,
    jobOpenings,
    openingPagination,
    openingsLoading,
    openingsBusy,
    openingsError,
    openingErrors,
    loadJobOpenings,
    saveJobOpening,
    clear,
    loadApplications,
    loadCatalogs,
    selectApplication,
    applicationAction,
    createExam,
    loadAttempt,
    gradeAttempt,
    interviewAction,
  }
})
