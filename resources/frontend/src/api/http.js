import axios from 'axios'

export function apiBaseUrl(value = '') {
  const base = value.trim().replace(/\/+$/, '')
  if (!base) return '/api/v1'
  if (base.endsWith('/api/v1')) return base
  if (base.endsWith('/api')) return `${base}/v1`
  return `${base}/api/v1`
}

export const http = axios.create({
  baseURL: apiBaseUrl(import.meta.env?.VITE_API_BASE_URL),
  timeout: 30000,
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json' },
})

const csrfStates = { employee: { ready: false }, applicant: { ready: false } }
let csrfVersion = 0
const sessionVersions = { employee: 0, applicant: 0 }

function requestScope(config) {
  return config.authScope === 'applicant' || /(?:^|\/)applicant(?:\/|$)/.test(config.url || '')
    ? 'applicant'
    : 'employee'
}

export async function ensureCsrf(scope = 'employee') {
  const state = csrfStates[scope]
  if (state.ready) return
  if (!state.request) {
    const version = csrfVersion
    const request = http
      .get(scope === 'applicant' ? '/applicant/auth/csrf-cookie' : '/auth/csrf-cookie', {
        skipAuthEvent: true,
      })
      .then(() => {
        if (version === csrfVersion) state.ready = true
      })
      .finally(() => {
        if (state.request === request) state.request = undefined
      })
    state.request = request
  }
  return state.request
}

export function resetCsrf() {
  csrfVersion += 1
  for (const state of Object.values(csrfStates)) {
    state.ready = false
    state.request = undefined
  }
}
export function resetAuthSession(scope = 'employee') {
  sessionVersions[scope] += 1
  resetCsrf()
}
export async function refreshCsrf(scope = 'employee') {
  resetCsrf()
  await ensureCsrf(scope)
}

http.interceptors.request.use(async (config) => {
  const scope = requestScope(config)
  const version = sessionVersions[scope]
  config.hrisAuthScope = scope
  config.hrisSessionVersion = version
  if (!['get', 'head', 'options'].includes((config.method || 'get').toLowerCase())) {
    await ensureCsrf(scope)
    if (version !== sessionVersions[scope])
      throw new axios.CanceledError('The session changed before the request was sent.', config)
  }
  config.hrisApplicantSessionVersion = sessionVersions.applicant
  return config
})

http.interceptors.response.use(
  (response) => {
    if (
      response.config?.url === '/auth/logout' &&
      response.config?.method === 'post' &&
      response.config?.hrisSessionVersion === sessionVersions.employee &&
      response.config?.hrisApplicantSessionVersion === sessionVersions.applicant
    ) {
      resetAuthSession('applicant')
      if (typeof window !== 'undefined') window.dispatchEvent(new Event('hris:employee-logout'))
    }
    return response
  },
  (error) => {
    const status = error.response?.status
    const scope = error.config?.hrisAuthScope || 'employee'
    const current = error.config?.hrisSessionVersion === sessionVersions[scope]
    if (current && (status === 419 || status === 401)) resetCsrf()
    if (
      current &&
      status === 401 &&
      !error.config?.skipAuthEvent &&
      typeof window !== 'undefined'
    ) {
      window.dispatchEvent(
        new Event(
          scope === 'applicant' ? 'hris:applicant-session-expired' : 'hris:session-expired',
        ),
      )
    }
    return Promise.reject(error)
  },
)

export function apiError(error) {
  const status = error?.response?.status
  const data = error?.response?.data
  const messages = {
    401: 'Your session has ended. Please sign in again.',
    403: 'You do not have access to this record or action.',
    419: 'Your session token expired. Please retry your request.',
    429: 'Too many attempts. Please wait before trying again.',
  }
  return {
    status,
    message: messages[status] || data?.message || 'Unable to reach the server. Please try again.',
    errors: data?.errors || {},
  }
}

export async function downloadFile(url, params = {}, { signal, shouldDownload } = {}) {
  let response
  try {
    response = await http.get(url, { params, responseType: 'blob', signal })
  } catch (error) {
    const payload = error.response?.data
    if (
      typeof Blob !== 'undefined' &&
      payload instanceof Blob &&
      payload.size <= 128 * 1024 &&
      /json/i.test(payload.type || error.response?.headers?.['content-type'] || '')
    ) {
      try {
        const parsed = JSON.parse(await payload.text())
        if (parsed && typeof parsed === 'object' && !Array.isArray(parsed))
          error.response.data = parsed
      } catch {
        /* Keep the original response when the server did not return valid JSON. */
      }
    }
    throw error
  }
  if (signal?.aborted || (shouldDownload && !shouldDownload())) return false
  const disposition = response.headers['content-disposition'] || ''
  const encoded = disposition.match(/filename\*=UTF-8''([^;]+)/i)?.[1]
  const plain = disposition.match(/filename="?([^";]+)"?/i)?.[1]
  let filename = plain || 'document'
  if (encoded) {
    try {
      filename = decodeURIComponent(encoded)
    } catch {
      /* Keep the fallback filename. */
    }
  }
  filename =
    filename
      .split(/[\\/]/)
      .pop()
      .replace(/[\u0000-\u001f]/g, '') || 'document'
  const href = URL.createObjectURL(response.data)
  const link = document.createElement('a')
  link.href = href
  link.download = filename
  document.body.append(link)
  link.click()
  link.remove()
  window.setTimeout(() => URL.revokeObjectURL(href), 1000)
  return true
}

export default http
