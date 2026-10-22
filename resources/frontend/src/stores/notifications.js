import { computed, onScopeDispose, ref, watch } from 'vue'
import { defineStore } from 'pinia'
import http, { apiError } from '@/api/http'
import { useAuthStore } from '@/stores/auth'

function count(value) {
  return Number.isSafeInteger(value) && value >= 0 ? value : null
}

export const useNotificationsStore = defineStore('notifications', () => {
  const auth = useAuthStore()
  const items = ref([])
  const filter = ref('all')
  const page = ref(1)
  const lastPage = ref(1)
  const total = ref(0)
  const unreadCount = ref(null)
  const loading = ref(false)
  const loadingMore = ref(false)
  const markingId = ref(null)
  const markingAll = ref(false)
  const error = ref('')
  const countError = ref('')
  const hasMore = computed(() => page.value < lastPage.value)
  const mutating = computed(() => markingId.value !== null || markingAll.value)
  const account = computed(() => (auth.authenticated ? String(auth.user?.id || '') : ''))
  let sessionVersion = 0
  let listVersion = 0
  let countVersion = 0
  let listController
  let countController
  let countRequest
  let polling = false
  let pollTimer

  function current(session, identity) {
    return session === sessionVersion && identity !== '' && identity === account.value
  }

  function applyCount(value) {
    if (count(value) !== null) {
      unreadCount.value = value
      countError.value = ''
    }
  }

  function cancelList() {
    ++listVersion
    listController?.abort()
    listController = undefined
    loading.value = false
    loadingMore.value = false
  }

  function reset() {
    ++sessionVersion
    ++countVersion
    cancelList()
    countController?.abort()
    countController = undefined
    countRequest = undefined
    items.value = []
    filter.value = 'all'
    page.value = 1
    lastPage.value = 1
    total.value = 0
    unreadCount.value = null
    markingId.value = null
    markingAll.value = false
    error.value = ''
    countError.value = ''
  }

  async function load({ append = false, filter: selection = filter.value } = {}) {
    if (!account.value || (append && (loading.value || loadingMore.value || !hasMore.value))) return
    if (!['all', 'unread'].includes(selection)) return
    if (selection !== filter.value) append = false
    const version = ++listVersion
    const session = sessionVersion
    const identity = account.value
    const unreadVersion = ++countVersion
    const nextPage = append ? page.value + 1 : 1
    listController?.abort()
    const controller = new AbortController()
    listController = controller
    if (selection !== filter.value) {
      items.value = []
      total.value = 0
      page.value = 1
      lastPage.value = 1
    }
    filter.value = selection
    loading.value = !append
    loadingMore.value = append
    error.value = ''
    try {
      const { data } = await http.get('/notifications', {
        params: { filter: selection, page: nextPage, per_page: 20 },
        signal: controller.signal,
      })
      if (!current(session, identity) || version !== listVersion) return
      const rows = Array.isArray(data.data) ? data.data : []
      items.value = append
        ? [...new Map([...items.value, ...rows].map((item) => [item.id, item])).values()]
        : rows
      page.value = data.meta?.current_page || nextPage
      lastPage.value = data.meta?.last_page || 1
      total.value = count(data.meta?.total) ?? rows.length
      if (unreadVersion === countVersion) applyCount(data.meta?.unread_count)
    } catch (failure) {
      if (!current(session, identity) || version !== listVersion || failure.code === 'ERR_CANCELED')
        return
      error.value = apiError(failure).message
    } finally {
      if (current(session, identity) && version === listVersion) {
        loading.value = false
        loadingMore.value = false
        listController = undefined
      }
    }
  }

  async function refreshUnreadCount() {
    if (!account.value || mutating.value) return
    if (countRequest) return countRequest
    const session = sessionVersion
    const identity = account.value
    const version = ++countVersion
    const controller = new AbortController()
    countController = controller
    const request = http
      .get('/notifications/unread-count', { signal: controller.signal })
      .then(({ data }) => {
        if (!current(session, identity) || version !== countVersion || mutating.value) return
        applyCount(data.data?.unread_count)
        countError.value = ''
      })
      .catch((failure) => {
        if (
          !current(session, identity) ||
          version !== countVersion ||
          failure.code === 'ERR_CANCELED'
        )
          return
        countError.value = apiError(failure).message
      })
      .finally(() => {
        if (countRequest === request) {
          countRequest = undefined
          countController = undefined
        }
      })
    countRequest = request
    return request
  }

  async function markRead(item) {
    if (
      !account.value ||
      mutating.value ||
      item?.read_at ||
      !/^[\da-f]{8}-[\da-f]{4}-[\da-f]{4}-[\da-f]{4}-[\da-f]{12}$/i.test(item?.id || '')
    )
      return
    const session = sessionVersion
    const identity = account.value
    ++countVersion
    markingId.value = item.id
    error.value = ''
    try {
      const { data } = await http.patch(`/notifications/${item.id}/read`)
      if (!current(session, identity)) return
      ++countVersion
      applyCount(data.meta?.unread_count)
      items.value = items.value.map((row) => (row.id === item.id ? data.data : row))
      if (filter.value === 'unread') items.value = items.value.filter((row) => !row.read_at)
      await load()
    } catch (failure) {
      if (current(session, identity)) error.value = apiError(failure).message
    } finally {
      if (current(session, identity)) markingId.value = null
    }
  }

  async function markAllRead() {
    if (!account.value || mutating.value || !(unreadCount.value > 0)) return
    const session = sessionVersion
    const identity = account.value
    ++countVersion
    markingAll.value = true
    error.value = ''
    try {
      const { data } = await http.patch('/notifications/read-all')
      if (!current(session, identity)) return
      ++countVersion
      applyCount(data.data?.unread_count)
      await load()
    } catch (failure) {
      if (current(session, identity)) error.value = apiError(failure).message
    } finally {
      if (current(session, identity)) markingAll.value = false
    }
  }

  function pollIfVisible() {
    if (polling && (typeof document === 'undefined' || !document.hidden)) refreshUnreadCount()
  }

  function startPolling() {
    if (polling) return
    polling = true
    pollTimer = setInterval(pollIfVisible, 60000)
    if (typeof document !== 'undefined')
      document.addEventListener('visibilitychange', pollIfVisible)
    if (typeof window !== 'undefined') window.addEventListener('focus', pollIfVisible)
    pollIfVisible()
  }

  function stopPolling() {
    polling = false
    clearInterval(pollTimer)
    pollTimer = undefined
    if (typeof document !== 'undefined')
      document.removeEventListener('visibilitychange', pollIfVisible)
    if (typeof window !== 'undefined') window.removeEventListener('focus', pollIfVisible)
    ++countVersion
    countController?.abort()
    countController = undefined
    countRequest = undefined
  }

  watch(
    account,
    () => {
      reset()
      pollIfVisible()
    },
    { flush: 'sync' },
  )
  onScopeDispose(() => {
    stopPolling()
    reset()
  })

  return {
    items,
    filter,
    page,
    lastPage,
    total,
    unreadCount,
    loading,
    loadingMore,
    markingId,
    markingAll,
    error,
    countError,
    hasMore,
    mutating,
    load,
    cancelList,
    refreshUnreadCount,
    markRead,
    markAllRead,
    startPolling,
    stopPolling,
    reset,
  }
})
