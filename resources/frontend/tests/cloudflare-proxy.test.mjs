import test, { afterEach, mock } from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import worker from '../public/_worker.js'

const FRONTEND = 'https://hris.pages.dev'
const BACKEND = 'https://hris-api.onrender.com'
const PROXY_KEY = 'ab'.repeat(32)

function environment(overrides = {}) {
  return {
    HRIS_API_ORIGIN: BACKEND,
    HRIS_FRONTEND_ORIGIN: FRONTEND,
    HRIS_PROXY_KEY: PROXY_KEY,
    ASSETS: { fetch: () => assert.fail('An API request must not fall back to static HTML.') },
    ...overrides,
  }
}

function request(path, options = {}) {
  return new Request(`${FRONTEND}${path}`, options)
}

afterEach(() => mock.restoreAll())

test('same-origin CSRF and login preserve separate secure session cookies without domain rewriting', async () => {
  const xsrf = 'XSRF-TOKEN=fixture-xsrf; Path=/; Secure; SameSite=Lax'
  const session =
    'hris_session=fixture-session; Expires=Thu, 22 Oct 2026 12:00:00 GMT; Path=/; Secure; HttpOnly; SameSite=Lax'
  const calls = []
  mock.method(globalThis, 'fetch', async (outbound) => {
    calls.push(outbound)
    const headers = new Headers({ 'content-type': 'application/json' })
    headers.append('set-cookie', xsrf)
    headers.append('set-cookie', session)
    return new Response('{"message":"Authenticated"}', { status: 200, headers })
  })

  const response = await worker.fetch(
    request('/api/v1/auth/login', {
      method: 'POST',
      headers: {
        Origin: FRONTEND,
        'Content-Type': 'application/json',
        Cookie: 'XSRF-TOKEN=fixture-xsrf; hris_session=fixture-session',
        'X-XSRF-TOKEN': 'fixture-xsrf',
        'Sec-Fetch-Site': 'same-origin',
      },
      body: '{"employee_number":"EMP-TEST"}',
    }),
    environment(),
  )

  assert.deepEqual(response.headers.getSetCookie(), [xsrf, session])
  assert.equal(response.status, 200)
  assert.deepEqual(await response.json(), { message: 'Authenticated' })
  assert.equal(calls.length, 1)
  assert.equal(calls[0].url, `${BACKEND}/api/v1/auth/login`)
  assert.equal(calls[0].method, 'POST')
  assert.equal(calls[0].headers.get('origin'), FRONTEND)
  assert.equal(
    calls[0].headers.get('cookie'),
    'XSRF-TOKEN=fixture-xsrf; hris_session=fixture-session',
  )
  assert.equal(calls[0].headers.get('x-xsrf-token'), 'fixture-xsrf')
  assert.equal(await calls[0].text(), '{"employee_number":"EMP-TEST"}')
})

test('query parameters cannot change the upstream and report downloads retain status, bytes and filename', async () => {
  const payload = new Uint8Array([0, 3, 255, 44, 10])
  let received
  mock.method(globalThis, 'fetch', async (outbound) => {
    received = outbound
    return new Response(payload, {
      status: 206,
      statusText: 'Partial Content',
      headers: {
        'content-type': 'application/octet-stream',
        'content-disposition': 'attachment; filename="fixture.csv"',
        'content-range': 'bytes 0-4/20',
        'x-request-id': 'fixture-request',
      },
    })
  })

  const response = await worker.fetch(
    request('/api/v1/reports/export?month=2026-10&target=https%3A%2F%2F127.0.0.1'),
    environment(),
  )

  assert.equal(
    received.url,
    `${BACKEND}/api/v1/reports/export?month=2026-10&target=https%3A%2F%2F127.0.0.1`,
  )
  assert.equal(response.status, 206)
  assert.equal(response.statusText, 'Partial Content')
  assert.equal(response.headers.get('content-disposition'), 'attachment; filename="fixture.csv"')
  assert.equal(response.headers.get('content-range'), 'bytes 0-4/20')
  assert.equal(response.headers.get('x-request-id'), 'fixture-request')
  assert.deepEqual(new Uint8Array(await response.arrayBuffer()), payload)
})

test('multipart applicant documents stream unchanged with their original boundary and cookies', async () => {
  const form = new FormData()
  form.set('cover_letter', 'Ready to apply')
  form.set('resume', new Blob([new Uint8Array([0, 255, 42])]), 'fixture.pdf')
  const incoming = request('/api/v1/applicant/applications', {
    method: 'POST',
    headers: { origin: FRONTEND, cookie: 'applicant_session=fixture' },
    body: form,
  })
  const expected = new Uint8Array(await incoming.clone().arrayBuffer())
  let received
  mock.method(globalThis, 'fetch', async (outbound) => {
    received = outbound
    return new Response('{"created":true}', { status: 201 })
  })

  const response = await worker.fetch(incoming, environment())

  assert.equal(response.status, 201)
  assert.equal(received.headers.get('content-type'), incoming.headers.get('content-type'))
  assert.equal(received.headers.get('cookie'), 'applicant_session=fixture')
  assert.deepEqual(new Uint8Array(await received.arrayBuffer()), expected)
})

test('client Host, forwarding, rewrite, IP and hop headers cannot influence the upstream', async () => {
  const spoofed = {
    host: 'attacker.example',
    forwarded: 'host=attacker.example;proto=http;for=127.0.0.1',
    via: 'fixture',
    'x-forwarded-host': 'attacker.example',
    'x-forwarded-proto': 'http',
    'x-forwarded-for': '127.0.0.1',
    'x-original-host': 'attacker.example',
    'x-original-url': '/.env',
    'x-rewrite-url': '/storage/private',
    'x-real-ip': '127.0.0.1',
    'x-client-ip': '127.0.0.1',
    'client-ip': '127.0.0.1',
    'true-client-ip': '127.0.0.1',
    'fastly-client-ip': '127.0.0.1',
    'cf-connecting-ip': '127.0.0.1',
    'cf-visitor': '{"scheme":"http"}',
    'x-envoy-external-address': '127.0.0.1',
    'x-appengine-user-ip': '127.0.0.1',
    connection: 'keep-alive, x-secret-hop',
    'x-secret-hop': 'fixture',
    'keep-alive': 'timeout=5',
    'proxy-authorization': 'fixture',
    te: 'trailers',
  }
  let received
  mock.method(globalThis, 'fetch', async (outbound) => {
    received = outbound
    return new Response(null, { status: 204 })
  })

  await worker.fetch(request('/api/v1/auth/me', { headers: spoofed }), environment())

  assert.equal(received.url, `${BACKEND}/api/v1/auth/me`)
  for (const name of Object.keys(spoofed)) assert.equal(received.headers.has(name), false, name)
})

test('only the runtime key and validated platform IP replace all client supplied HRIS headers', async () => {
  let received
  mock.method(globalThis, 'fetch', async (outbound) => {
    received = outbound
    return new Response(null, { status: 204 })
  })

  await worker.fetch(
    request('/api/v1/applicant/auth/csrf-cookie', {
      headers: {
        'x-hris-proxy-key': 'client-supplied-key',
        'x-hris-client-ip': '127.0.0.1',
        'x-hris-admin': 'true',
        'x-forwarded-for': '127.0.0.1',
        'x-real-ip': '127.0.0.1',
        'cf-connecting-ip': '198.51.100.21',
      },
    }),
    environment(),
  )

  assert.equal(received.headers.get('x-hris-proxy-key'), PROXY_KEY)
  assert.equal(received.headers.get('x-hris-client-ip'), '198.51.100.21')
  assert.equal(received.headers.has('x-hris-admin'), false)
  assert.equal(received.headers.has('x-forwarded-for'), false)
  assert.equal(received.headers.has('x-real-ip'), false)
  assert.equal(received.headers.has('cf-connecting-ip'), false)
})

for (const ip of [
  '203.0.113.9',
  '2001:db8::1234',
  '2001:0DB8:0000:0000:0000:0000:0000:0001',
  '::ffff:192.0.2.1',
]) {
  test(`platform IP ${ip} reaches the backend unchanged with the runtime proxy key`, async () => {
    let received
    mock.method(globalThis, 'fetch', async (outbound) => {
      received = outbound
      return new Response(null, { status: 204 })
    })

    await worker.fetch(
      request('/api/v1/applicant/auth/csrf-cookie', { headers: { 'cf-connecting-ip': ip } }),
      environment(),
    )

    assert.equal(received.headers.get('x-hris-client-ip'), ip)
    assert.equal(received.headers.get('x-hris-proxy-key'), PROXY_KEY)
  })
}

for (const ip of [
  undefined,
  '',
  'unknown',
  '203.0.113.9, 198.51.100.1',
  '203.0.113.256',
  '010.1.2.3',
  '1.2.3',
  '2001:db8:::1',
  '[2001:db8::1]',
  'fe80::1%eth0',
  '2001:db8::1:443:99999',
]) {
  test(`invalid or missing platform IP ${JSON.stringify(ip)} never falls back to client forwarding headers`, async () => {
    const headers = {
      'x-hris-client-ip': '127.0.0.1',
      'x-forwarded-for': '198.51.100.1',
      'x-real-ip': '198.51.100.2',
      'true-client-ip': '198.51.100.3',
    }
    if (ip !== undefined) headers['cf-connecting-ip'] = ip
    let received
    mock.method(globalThis, 'fetch', async (outbound) => {
      received = outbound
      return new Response(null, { status: 204 })
    })

    await worker.fetch(request('/api/v1/applicant/auth/csrf-cookie', { headers }), environment())

    assert.equal(received.headers.has('x-hris-client-ip'), false)
    assert.equal(received.headers.get('x-hris-proxy-key'), PROXY_KEY)
    assert.equal(received.headers.has('x-forwarded-for'), false)
    assert.equal(received.headers.has('x-real-ip'), false)
    assert.equal(received.headers.has('true-client-ip'), false)
  })
}

for (const key of [
  undefined,
  '',
  'ab'.repeat(31),
  'ab'.repeat(33),
  'AB'.repeat(32),
  'g'.repeat(64),
  'fixture',
  123,
]) {
  test(`missing or malformed runtime proxy key ${JSON.stringify(key)} returns 503 without an upstream request`, async () => {
    const outbound = mock.method(globalThis, 'fetch', () =>
      assert.fail('Unauthenticated proxy request.'),
    )

    const response = await worker.fetch(
      request('/api/v1/auth/me', { headers: { 'x-hris-proxy-key': PROXY_KEY } }),
      environment({ HRIS_PROXY_KEY: key }),
    )

    assert.equal(response.status, 503)
    assert.equal(outbound.mock.callCount(), 0)
    assert.equal((await response.text()).includes(PROXY_KEY), false)
  })
}

test('authenticated API responses bypass browser, Cloudflare and CDN caches', async () => {
  let received
  let options
  mock.method(globalThis, 'fetch', async (outbound, init) => {
    received = outbound
    options = init
    return new Response('{"fixture":true}', {
      headers: {
        'cache-control': 'public, max-age=3600',
        'cdn-cache-control': 'max-age=3600',
        'cloudflare-cdn-cache-control': 'max-age=3600',
        expires: 'Thu, 22 Oct 2026 12:00:00 GMT',
        age: '600',
      },
    })
  })

  const response = await worker.fetch(request('/api/v1/auth/me'), environment())

  assert.equal(received.cache, 'no-store')
  assert.equal(received.headers.get('cache-control'), 'no-store')
  assert.deepEqual(options, { cf: { cacheTtl: 0, cacheEverything: false } })
  assert.equal(response.headers.get('cache-control'), 'no-store, private')
  assert.equal(response.headers.get('cdn-cache-control'), 'no-store')
  assert.equal(response.headers.get('cloudflare-cdn-cache-control'), 'no-store')
  assert.equal(response.headers.has('age'), false)
  assert.equal(response.headers.has('expires'), false)
})

test('redirects are returned without following them or forwarding a cookie to another origin', async () => {
  const calls = []
  mock.method(globalThis, 'fetch', async (outbound) => {
    calls.push(outbound)
    return new Response(null, {
      status: 302,
      headers: { location: 'https://other.example/landing' },
    })
  })

  const response = await worker.fetch(
    request('/api/v1/auth/me', { headers: { cookie: 'hris_session=fixture' } }),
    environment(),
  )

  assert.equal(calls.length, 1)
  assert.equal(calls[0].redirect, 'manual')
  assert.equal(response.status, 302)
  assert.equal(response.headers.get('location'), 'https://other.example/landing')
})

for (const [label, options] of [
  ['foreign Origin', { headers: { origin: 'https://foreign.example' } }],
  ['null Origin', { headers: { origin: 'null' } }],
  ['sibling Origin', { headers: { origin: 'https://other.hris.pages.dev' } }],
  ['cross-site fetch metadata', { headers: { 'sec-fetch-site': 'cross-site' } }],
  ['same-site sibling fetch', { headers: { 'sec-fetch-site': 'same-site' } }],
  ['mutation without Origin', { method: 'POST', body: 'fixture' }],
]) {
  test(`${label} returns 403 without sending a backend request`, async () => {
    const outbound = mock.method(globalThis, 'fetch', () => assert.fail('Forbidden upstream call.'))

    const response = await worker.fetch(request('/api/v1/auth/login', options), environment())

    assert.equal(response.status, 403)
    assert.equal(outbound.mock.callCount(), 0)
  })
}

for (const value of [
  undefined,
  '',
  'http://hris-api.onrender.com',
  'https://127.0.0.1',
  'https://192.168.100.51',
  'https://[::1]',
  'https://localhost',
  'https://user:password@hris-api.onrender.com',
  'https://hris-api.onrender.com:443',
  'https://hris-api.onrender.com/api',
  'https://hris-api.onrender.com?target=fixture',
  'https://hris-api.onrender.com#fixture',
  'https://hris-api.onrender.com.attacker.example',
  'https://sub.hris-api.onrender.com',
  'https://hris-api.onrender.com\\private',
  ' https://hris-api.onrender.com',
]) {
  test(`invalid backend origin ${JSON.stringify(value)} fails closed with 503`, async () => {
    const outbound = mock.method(globalThis, 'fetch', () => assert.fail('Invalid upstream call.'))

    const response = await worker.fetch(
      request('/api/v1/auth/me'),
      environment({ HRIS_API_ORIGIN: value }),
    )

    assert.equal(response.status, 503)
    assert.equal(outbound.mock.callCount(), 0)
    assert.equal(response.headers.get('cache-control'), 'no-store, private')
  })
}

test('missing frontend origin and a request for a different frontend fail closed', async () => {
  const outbound = mock.method(globalThis, 'fetch', () => assert.fail('Invalid upstream call.'))

  const unconfigured = await worker.fetch(
    request('/api/v1/auth/me'),
    environment({ HRIS_FRONTEND_ORIGIN: '' }),
  )
  const foreign = await worker.fetch(
    new Request('https://preview.hris.pages.dev/api/v1/auth/me'),
    environment(),
  )

  assert.equal(unconfigured.status, 503)
  assert.equal(foreign.status, 403)
  assert.equal(outbound.mock.callCount(), 0)
})

test('private files below the API prefix are never forwarded', async () => {
  const outbound = mock.method(globalThis, 'fetch', () =>
    assert.fail('Private path reached Laravel.'),
  )

  const response = await worker.fetch(request('/api/.env'), environment())

  assert.equal(response.status, 404)
  assert.equal(outbound.mock.callCount(), 0)
})

test('Workers getAll keeps separate cookies when the standard Node API is not available', async () => {
  const cookies = [
    'XSRF-TOKEN=fixture; Secure; SameSite=Lax',
    'hris_session=fixture; Secure; HttpOnly; SameSite=Lax',
  ]
  const upstream = new Response(null, { status: 204 })
  Object.defineProperty(upstream.headers, 'getSetCookie', { value: undefined })
  Object.defineProperty(upstream.headers, 'getAll', {
    value: (name) => {
      assert.equal(name, 'set-cookie')
      return cookies
    },
  })
  mock.method(globalThis, 'fetch', async () => upstream)

  const response = await worker.fetch(request('/api/v1/auth/csrf-cookie'), environment())

  assert.equal(response.status, 204)
  assert.deepEqual(response.headers.getSetCookie(), cookies)
})

for (const path of [
  '/api/%2f..%2f.env',
  '/api/v1/%5cstorage',
  '/api/v1/%252fstorage',
  '/api/v1/%00',
]) {
  test(`ambiguous path ${path} returns 400 without contacting the backend`, async () => {
    const outbound = mock.method(globalThis, 'fetch', () => assert.fail('Ambiguous upstream path.'))

    const response = await worker.fetch(request(path), environment())

    assert.equal(response.status, 400)
    assert.equal(outbound.mock.callCount(), 0)
  })
}

test('unsupported methods and WebSocket upgrades are refused before contacting the API', async () => {
  const outbound = mock.method(globalThis, 'fetch', () => assert.fail('Unsupported upstream call.'))

  const method = await worker.fetch(
    request('/api/v1/auth/me', { method: 'PROPFIND' }),
    environment(),
  )
  const upgrade = await worker.fetch(
    request('/api/v1/auth/me', { headers: { upgrade: 'websocket' } }),
    environment(),
  )

  assert.equal(method.status, 405)
  assert.equal(upgrade.status, 400)
  assert.equal(outbound.mock.callCount(), 0)
})

test('backend network failures return a generic non-cacheable 502 without leaking infrastructure errors', async () => {
  mock.method(globalThis, 'fetch', () => {
    throw new Error('Private infrastructure details')
  })

  const response = await worker.fetch(request('/api/v1/auth/me'), environment())

  assert.equal(response.status, 502)
  assert.equal(response.headers.get('cache-control'), 'no-store, private')
  assert.deepEqual(await response.json(), {
    message: 'The HRIS backend is temporarily unavailable. Please retry.',
  })
})

test('API authorization and validation failures remain failures with their original payloads', async () => {
  for (const status of [401, 403, 419, 422, 429, 500]) {
    mock.method(globalThis, 'fetch', async () => Response.json({ fixture: status }, { status }))

    const response = await worker.fetch(request('/api/v1/auth/me'), environment())

    assert.equal(response.status, status)
    assert.deepEqual(await response.json(), { fixture: status })
    mock.restoreAll()
  }
})

test('static routes and private-looking paths never proxy to Laravel even without runtime API configuration', async () => {
  const assetRequests = []
  const outbound = mock.method(globalThis, 'fetch', () =>
    assert.fail('A static path reached Laravel.'),
  )
  const env = {
    ASSETS: {
      fetch: (incoming) => {
        assetRequests.push(new URL(incoming.url).pathname)
        return new Response('static fixture')
      },
    },
  }

  for (const path of [
    '/dashboard',
    '/auth/login',
    '/assets/app.js',
    '/storage/private.pdf',
    '/apps/.env',
    '/.env',
    '/apiary',
    '/up/private',
  ]) {
    const response = await worker.fetch(request(path), env)
    assert.equal(await response.text(), 'static fixture')
  }

  assert.deepEqual(assetRequests, [
    '/dashboard',
    '/auth/login',
    '/assets/app.js',
    '/storage/private.pdf',
    '/apps/.env',
    '/.env',
    '/apiary',
    '/up/private',
  ])
  assert.equal(outbound.mock.callCount(), 0)
})

test('health and API roots proxy while invocation rules leave frontend pages and assets static', async () => {
  const targets = []
  mock.method(globalThis, 'fetch', async (outbound) => {
    targets.push(outbound.url)
    return new Response(null, { status: 204 })
  })

  await worker.fetch(request('/up'), environment())
  await worker.fetch(request('/api'), environment())
  const routes = JSON.parse(
    await readFile(new URL('../public/_routes.json', import.meta.url), 'utf8'),
  )

  assert.deepEqual(targets, [`${BACKEND}/up`, `${BACKEND}/api`])
  assert.deepEqual(routes, { version: 1, include: ['/api', '/api/*', '/up'], exclude: [] })
})
