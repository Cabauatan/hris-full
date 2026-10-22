const METHODS = new Set(['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'])
const SAFE_METHODS = new Set(['GET', 'HEAD'])
const HOP_HEADERS = new Set([
  'connection',
  'keep-alive',
  'proxy-authenticate',
  'proxy-authorization',
  'proxy-connection',
  'te',
  'trailer',
  'transfer-encoding',
  'upgrade',
])
const FORWARDING_HEADERS = new Set([
  'host',
  'forwarded',
  'via',
  'client-ip',
  'true-client-ip',
  'fastly-client-ip',
  'x-real-ip',
  'x-client-ip',
  'x-cluster-client-ip',
  'x-remote-ip',
  'x-remote-addr',
  'x-proxyuser-ip',
  'x-http-host-override',
  'x-rewrite-url',
  'cdn-loop',
])

function error(status, message) {
  return Response.json(
    { message },
    {
      status,
      headers: { 'Cache-Control': 'no-store, private', 'X-Content-Type-Options': 'nosniff' },
    },
  )
}

function configuredOrigin(value, backend = false) {
  if (typeof value !== 'string' || !/^https:\/\/[^/?#:@\s]+\/?$/.test(value)) return null
  try {
    const url = new URL(value)
    if (value !== url.origin && value !== `${url.origin}/`) return null
    const hostname = url.hostname
    const label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?'
    if (backend) {
      if (!new RegExp(`^${label}\\.onrender\\.com$`).test(hostname)) return null
    } else {
      if (!new RegExp(`^(?:${label}\\.)+${label}$`).test(hostname)) return null
      if (/^(?:\d+\.)+\d+$/.test(hostname)) return null
      if (/\.(?:localhost|local|internal|invalid|test)$/.test(hostname)) return null
    }
    return url.origin
  } catch {
    return null
  }
}

function cleanHopHeaders(headers) {
  const connectionHeaders = (headers.get('connection') || '').split(',').map((name) => name.trim())
  for (const name of connectionHeaders) {
    if (name) headers.delete(name)
  }
  for (const name of HOP_HEADERS) headers.delete(name)
}

function platformClientIp(value) {
  if (!value || typeof value !== 'string') return null
  if (/^(?:0|[1-9]\d{0,2})(?:\.(?:0|[1-9]\d{0,2})){3}$/.test(value)) {
    return value.split('.').every((octet) => Number(octet) <= 255) ? value : null
  }
  if (!value.includes(':') || !/^[0-9a-f:.]+$/i.test(value)) return null
  try {
    const url = new URL(`https://[${value}]/`)
    return url.hostname.startsWith('[') && url.hostname.endsWith(']') ? value : null
  } catch {
    return null
  }
}

function outboundHeaders(request, proxyKey) {
  const clientIp = platformClientIp(request.headers.get('cf-connecting-ip'))
  const headers = new Headers(request.headers)
  cleanHopHeaders(headers)
  for (const name of [...headers.keys()]) {
    if (
      FORWARDING_HEADERS.has(name) ||
      /^(?:x-forwarded-|x-original-|x-envoy-|x-appengine-|x-hris-|cf-)/.test(name)
    ) {
      headers.delete(name)
    }
  }
  headers.set('x-hris-proxy-key', proxyKey)
  if (clientIp) headers.set('x-hris-client-ip', clientIp)
  headers.set('cache-control', 'no-store')
  headers.set('pragma', 'no-cache')
  return headers
}

function responseHeaders(upstream) {
  const headers = new Headers()
  upstream.headers.forEach((value, name) => {
    if (name !== 'set-cookie') headers.append(name, value)
  })
  // Workers keeps separate cookies through getAll; Node uses the standard getSetCookie API.
  const cookies =
    typeof upstream.headers.getSetCookie === 'function'
      ? upstream.headers.getSetCookie()
      : upstream.headers.getAll('set-cookie')
  for (const cookie of cookies) headers.append('set-cookie', cookie)
  cleanHopHeaders(headers)
  headers.set('cache-control', 'no-store, private')
  headers.set('cdn-cache-control', 'no-store')
  headers.set('cloudflare-cdn-cache-control', 'no-store')
  headers.set('pragma', 'no-cache')
  headers.delete('age')
  headers.delete('expires')
  return headers
}

export default {
  async fetch(request, env) {
    const url = new URL(request.url)
    const proxyRoute =
      url.pathname === '/api' || url.pathname.startsWith('/api/') || url.pathname === '/up'
    if (!proxyRoute) return env.ASSETS.fetch(request)

    const backendOrigin = configuredOrigin(env.HRIS_API_ORIGIN, true)
    const frontendOrigin = configuredOrigin(env.HRIS_FRONTEND_ORIGIN)
    const proxyKey = env.HRIS_PROXY_KEY
    if (
      !backendOrigin ||
      !frontendOrigin ||
      typeof proxyKey !== 'string' ||
      !/^[a-f0-9]{64}$/.test(proxyKey)
    )
      return error(503, 'The HRIS API proxy is not configured.')
    if (url.origin !== frontendOrigin) return error(403, 'This frontend origin is not allowed.')
    if (url.pathname.split('/').some((segment) => segment.startsWith('.'))) {
      return error(404, 'The request path was not found.')
    }
    if (/%(?:2e|2f|5c|00|25)/i.test(url.pathname) || url.pathname.includes('\\')) {
      return error(400, 'The request path is not allowed.')
    }
    if (!METHODS.has(request.method)) return error(405, 'The request method is not allowed.')
    if (request.headers.has('upgrade')) return error(400, 'Use the configured Reverb connection.')

    const origin = request.headers.get('origin')
    const fetchSite = request.headers.get('sec-fetch-site')
    if (
      (origin && origin !== frontendOrigin) ||
      (fetchSite && !['same-origin', 'none'].includes(fetchSite)) ||
      (!SAFE_METHODS.has(request.method) && origin !== frontendOrigin)
    ) {
      return error(403, 'Cross-origin API requests are not allowed.')
    }

    const target = new URL(backendOrigin)
    target.pathname = url.pathname
    target.search = url.search
    const proxied = new Request(target, request)
    const outbound = new Request(proxied, {
      headers: outboundHeaders(request, proxyKey),
      redirect: 'manual',
      cache: 'no-store',
    })
    try {
      const upstream = await fetch(outbound, { cf: { cacheTtl: 0, cacheEverything: false } })
      return new Response(upstream.body, {
        status: upstream.status,
        statusText: upstream.statusText,
        headers: responseHeaders(upstream),
      })
    } catch {
      return error(502, 'The HRIS backend is temporarily unavailable. Please retry.')
    }
  },
}
