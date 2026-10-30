function setting(settings, name) {
  const value = settings[name]
  if (value === undefined || value === '') return ''
  if (typeof value !== 'string' || /[\u0000-\u0020\u007f]/.test(value))
    throw new Error(`Invalid ${name}.`)
  return value
}

function httpUrl(value, name) {
  let url
  try {
    url = new URL(value)
  } catch {
    throw new Error(`Invalid ${name}.`)
  }
  const host = url.hostname
  const validHost = host.startsWith('[')
    ? /^\[[a-f0-9:.]+\]$/i.test(host)
    : host.length <= 253 &&
      host.split('.').every((label) => /^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i.test(label))
  if (
    !['http:', 'https:'].includes(url.protocol) ||
    !validHost ||
    url.username ||
    url.password ||
    url.search ||
    url.hash ||
    value.includes('\\')
  )
    throw new Error(`Invalid ${name}.`)
  return url
}

export function buildCloudflareSecurityHeaders(settings = {}) {
  const connections = new Set(["'self'"])
  const images = new Set(["'self'", 'data:', 'blob:'])
  const api = setting(settings, 'VITE_API_BASE_URL')
  if (api) {
    if (api.startsWith('//')) throw new Error('Invalid VITE_API_BASE_URL.')
    if (/^[a-z][a-z0-9+.-]*:/i.test(api)) {
      const origin = httpUrl(api, 'VITE_API_BASE_URL').origin
      connections.add(origin)
      images.add(origin)
    } else if (api.includes('\\') || api.includes('?') || api.includes('#')) {
      throw new Error('Invalid VITE_API_BASE_URL.')
    }
  }

  const host = setting(settings, 'VITE_REVERB_HOST')
  if (host) {
    const scheme = setting(settings, 'VITE_REVERB_SCHEME') || 'http'
    const port = setting(settings, 'VITE_REVERB_PORT') || '8082'
    if (!['http', 'https'].includes(scheme)) throw new Error('Invalid VITE_REVERB_SCHEME.')
    if (!/^\d{1,5}$/.test(port) || Number(port) < 1 || Number(port) > 65535)
      throw new Error('Invalid VITE_REVERB_PORT.')
    const url = httpUrl(`${scheme}://${host}:${port}`, 'VITE_REVERB_HOST')
    if (url.hostname !== host.toLowerCase() || url.pathname !== '/')
      throw new Error('Invalid VITE_REVERB_HOST.')
    connections.add(url.origin)
    url.protocol = scheme === 'https' ? 'wss:' : 'ws:'
    connections.add(url.origin)
  }

  const policy = [
    "default-src 'self'",
    "script-src 'self'",
    "style-src 'self' 'unsafe-inline'",
    `img-src ${[...images].join(' ')}`,
    "font-src 'self' data:",
    `connect-src ${[...connections].join(' ')}`,
    "media-src 'self' blob:",
    "worker-src 'self' blob:",
    "object-src 'none'",
    "base-uri 'self'",
    "frame-ancestors 'none'",
    "form-action 'self'",
  ].join('; ')

  return [
    '/*',
    '  Strict-Transport-Security: max-age=31536000',
    '  X-Content-Type-Options: nosniff',
    '  X-Frame-Options: DENY',
    '  Referrer-Policy: no-referrer',
    '  Permissions-Policy: camera=(self), microphone=(self), geolocation=()',
    `  Content-Security-Policy: ${policy}`,
    '',
  ].join('\n')
}
