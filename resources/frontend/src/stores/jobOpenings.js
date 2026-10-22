import { ref } from 'vue'
import { defineStore } from 'pinia'
import http, { apiError } from '@/api/http'

export const useJobOpeningsStore = defineStore('jobOpenings', () => {
  const openings = ref([])
  const options = ref([])
  const selected = ref(null)
  const pagination = ref({ current_page: 1, last_page: 1, total: 0 })
  const loading = ref(false)
  const optionsLoading = ref(false)
  const selectionLoading = ref(false)
  const error = ref('')
  const optionsError = ref('')
  const selectionError = ref('')
  let listVersion = 0
  let optionsVersion = 0
  let selectionVersion = 0
  let listController
  let optionsController
  let selectionController
  const publicRequest = { authScope: 'applicant', skipAuthEvent: true }

  async function loadOpenings(page = 1, search = '') {
    const version = ++listVersion
    listController?.abort()
    const controller = new AbortController()
    listController = controller
    loading.value = true
    error.value = ''
    try {
      const { data } = await http.get('/careers/job-openings', {
        ...publicRequest,
        params: { page, per_page: 15, ...(search.trim() ? { search: search.trim() } : {}) },
        signal: controller.signal,
      })
      if (version !== listVersion) return
      openings.value = data.data
      pagination.value = data.meta || pagination.value
    } catch (problem) {
      if (version === listVersion && problem.code !== 'ERR_CANCELED') {
        openings.value = []
        pagination.value = { current_page: 1, last_page: 1, total: 0 }
        error.value = apiError(problem).message
      }
    } finally {
      if (version === listVersion) loading.value = false
    }
  }

  async function loadOptions() {
    const version = ++optionsVersion
    optionsController?.abort()
    const controller = new AbortController()
    optionsController = controller
    optionsLoading.value = true
    optionsError.value = ''
    try {
      const { data } = await http.get('/careers/job-openings', {
        ...publicRequest,
        params: { per_page: 100, page: 1 },
        signal: controller.signal,
      })
      const available = [...data.data]
      for (let page = 2; page <= (data.meta?.last_page || 1); page += 1) {
        if (version !== optionsVersion) return
        const { data: next } = await http.get('/careers/job-openings', {
          ...publicRequest,
          params: { per_page: 100, page },
          signal: controller.signal,
        })
        available.push(...next.data)
      }
      if (version === optionsVersion) {
        options.value = available.filter((opening) => opening.accepting_applications === true)
        return options.value
      }
    } catch (problem) {
      if (version === optionsVersion && problem.code !== 'ERR_CANCELED') {
        options.value = []
        optionsError.value = apiError(problem).message
      }
    } finally {
      if (version === optionsVersion) optionsLoading.value = false
    }
    return null
  }

  function clearSelection() {
    selectionVersion += 1
    selectionController?.abort()
    selected.value = null
    selectionLoading.value = false
    selectionError.value = ''
  }

  async function loadOpening(id) {
    clearSelection()
    if (!/^[1-9]\d*$/.test(String(id)) || !Number.isSafeInteger(Number(id))) {
      selectionError.value = 'This position is unavailable. Choose an open position below.'
      return null
    }
    const version = selectionVersion
    const controller = new AbortController()
    selectionController = controller
    selectionLoading.value = true
    try {
      const { data } = await http.get(`/careers/job-openings/${id}`, {
        ...publicRequest,
        signal: controller.signal,
      })
      if (version !== selectionVersion) return null
      if (data.data.accepting_applications !== true) {
        selectionError.value = 'This position is no longer accepting applications.'
        return null
      }
      selected.value = data.data
      return data.data
    } catch (problem) {
      if (version === selectionVersion && problem.code !== 'ERR_CANCELED')
        selectionError.value =
          problem.response?.status === 404
            ? 'This position is no longer accepting applications. Choose another open position.'
            : apiError(problem).message
    } finally {
      if (version === selectionVersion) selectionLoading.value = false
    }
    return null
  }

  return {
    openings,
    options,
    selected,
    pagination,
    loading,
    optionsLoading,
    selectionLoading,
    error,
    optionsError,
    selectionError,
    loadOpenings,
    loadOptions,
    loadOpening,
    clearSelection,
  }
})
