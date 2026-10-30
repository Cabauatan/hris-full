import test from 'node:test'
import assert from 'node:assert/strict'
import { execFile } from 'node:child_process'
import { mkdtemp, readFile, rm } from 'node:fs/promises'
import { tmpdir } from 'node:os'
import { basename, dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { promisify } from 'node:util'
import { buildCloudflareSecurityHeaders } from '../src/utils/securityHeaders.js'

const run = promisify(execFile)
const root = fileURLToPath(new URL('..', import.meta.url))

function headers(settings = {}) {
  const output = buildCloudflareSecurityHeaders(settings)
  assert.equal(output.split('\n')[0], '/*')
  return new Map(
    output
      .split('\n')
      .slice(1)
      .filter(Boolean)
      .map((line) => {
        assert.ok(line.length < 2000, 'Each Pages header line must fit its platform limit.')
        const separator = line.indexOf(':')
        return [line.slice(0, separator).trim(), line.slice(separator + 1).trim()]
      }),
  )
}

test('static pages receive HTTPS, framing, privacy, and camera-safe browser policies', () => {
  const values = headers()
  assert.equal(values.get('Strict-Transport-Security'), 'max-age=31536000')
  assert.equal(values.get('X-Content-Type-Options'), 'nosniff')
  assert.equal(values.get('X-Frame-Options'), 'DENY')
  assert.equal(values.get('Referrer-Policy'), 'no-referrer')
  assert.equal(values.get('Permissions-Policy'), 'camera=(self), microphone=(self), geolocation=()')
  const csp = values.get('Content-Security-Policy')
  for (const directive of [
    "default-src 'self'",
    "script-src 'self'",
    "style-src 'self' 'unsafe-inline'",
    "img-src 'self' data: blob:",
    "font-src 'self' data:",
    "connect-src 'self'",
    "media-src 'self' blob:",
    "worker-src 'self' blob:",
    "object-src 'none'",
    "base-uri 'self'",
    "frame-ancestors 'none'",
    "form-action 'self'",
  ])
    assert.ok(csp.split('; ').includes(directive), directive)
  assert.doesNotMatch(csp, /unsafe-eval|fonts\.google|\*/)
})

test('secure Reverb uses only its exact HTTPS and WSS origins beside same-origin API traffic', () => {
  const csp = headers({
    VITE_API_BASE_URL: '/api/v1',
    VITE_REVERB_HOST: 'hris-api.onrender.com',
    VITE_REVERB_PORT: '443',
    VITE_REVERB_SCHEME: 'https',
  }).get('Content-Security-Policy')

  assert.ok(
    csp
      .split('; ')
      .includes("connect-src 'self' https://hris-api.onrender.com wss://hris-api.onrender.com"),
  )
  assert.doesNotMatch(csp, /https:\s|wss:\s|\*\.onrender/)
})

test('a separately configured API origin permits its private-document images without wildcard hosts', () => {
  const csp = headers({
    VITE_API_BASE_URL: 'https://api.hris.example:8443/api/v1',
    VITE_REVERB_HOST: 'video.hris.example',
    VITE_REVERB_PORT: '443',
    VITE_REVERB_SCHEME: 'https',
  }).get('Content-Security-Policy')

  assert.ok(
    csp
      .split('; ')
      .includes(
        "connect-src 'self' https://api.hris.example:8443 https://video.hris.example wss://video.hris.example",
      ),
  )
  assert.ok(csp.split('; ').includes("img-src 'self' data: blob: https://api.hris.example:8443"))
  assert.doesNotMatch(csp, /api\/v1|onrender|\*/)
})

test('explicit local development origins remain exact and cannot become broad scheme permissions', () => {
  const csp = headers({
    VITE_API_BASE_URL: 'http://127.0.0.1:8002/api/v1',
    VITE_REVERB_HOST: '127.0.0.1',
    VITE_REVERB_PORT: '8082',
    VITE_REVERB_SCHEME: 'http',
  }).get('Content-Security-Policy')

  assert.ok(
    csp
      .split('; ')
      .includes(
        "connect-src 'self' http://127.0.0.1:8002 http://127.0.0.1:8082 ws://127.0.0.1:8082",
      ),
  )
})

test('unrelated environment settings and secrets never enter the generated public headers', () => {
  const output = buildCloudflareSecurityHeaders({
    VITE_API_BASE_URL: '/api/v1',
    APP_KEY: 'private-application-fixture',
    HRIS_PROXY_KEY: 'private-proxy-fixture',
    VITE_UNRELATED_VALUE: 'unrelated-fixture',
  })
  assert.doesNotMatch(output, /private-|unrelated-fixture|APP_KEY|HRIS_PROXY_KEY/)
})

for (const [name, value] of [
  ['VITE_API_BASE_URL', '//foreign.example/api'],
  ['VITE_API_BASE_URL', 'https://user:password@api.hris.example/api'],
  ['VITE_API_BASE_URL', 'https://api.hris.example/api?target=foreign'],
  ['VITE_API_BASE_URL', 'https://api.hris.example/api#fragment'],
  ['VITE_API_BASE_URL', 'https://*.hris.example/api'],
  ['VITE_API_BASE_URL', 'javascript:alert(1)'],
  ['VITE_API_BASE_URL', 'data:text/plain,fixture'],
  ['VITE_API_BASE_URL', '/api\\v1'],
  ['VITE_API_BASE_URL', 'https://api.hris.example/\r\nInjected:fixture'],
  ['VITE_API_BASE_URL', 42],
  ['VITE_REVERB_HOST', '*.hris.example'],
  ['VITE_REVERB_HOST', 'https://video.hris.example'],
  ['VITE_REVERB_HOST', 'video.hris.example/path'],
  ['VITE_REVERB_HOST', 'user@video.hris.example'],
  ['VITE_REVERB_HOST', 'video.hris.example?target=foreign'],
  ['VITE_REVERB_HOST', 'video.hris.example\r\nInjected:fixture'],
  ['VITE_REVERB_HOST', 'video.hris.example;script-src'],
  ['VITE_REVERB_PORT', '0'],
  ['VITE_REVERB_PORT', '65536'],
  ['VITE_REVERB_PORT', '443;script-src'],
  ['VITE_REVERB_PORT', '443\nInjected:fixture'],
  ['VITE_REVERB_SCHEME', 'ftp'],
  ['VITE_REVERB_SCHEME', 'https\nInjected:fixture'],
]) {
  test(`invalid ${name} ${JSON.stringify(value)} cannot enter browser security headers`, () => {
    assert.throws(
      () =>
        buildCloudflareSecurityHeaders({
          VITE_REVERB_HOST: 'video.hris.example',
          VITE_REVERB_PORT: '443',
          VITE_REVERB_SCHEME: 'https',
          [name]: value,
        }),
      new RegExp(`Invalid ${name}`),
    )
  })
}

test(
  'the actual production build emits Pages headers using only controlled public build settings',
  { timeout: 120000 },
  async () => {
    const temporaryRoot = await mkdtemp(join(tmpdir(), 'hris-security-headers-'))
    const output = join(temporaryRoot, 'dist')
    try {
      await run(
        process.execPath,
        [
          join(root, 'node_modules/vite/bin/vite.js'),
          'build',
          '--outDir',
          output,
          '--logLevel',
          'error',
        ],
        {
          cwd: root,
          env: {
            ...process.env,
            VITE_API_BASE_URL: '/api/v1',
            VITE_REVERB_HOST: 'hris-headers-fixture.onrender.com',
            VITE_REVERB_PORT: '443',
            VITE_REVERB_SCHEME: 'https',
          },
          timeout: 100000,
          maxBuffer: 1024 * 1024,
        },
      )
      const emitted = await readFile(join(output, '_headers'), 'utf8')
      assert.match(emitted, /^\/\*\n/)
      assert.match(emitted, /Strict-Transport-Security: max-age=31536000/)
      assert.match(
        emitted,
        /connect-src 'self' https:\/\/hris-headers-fixture\.onrender\.com wss:\/\/hris-headers-fixture\.onrender\.com/,
      )
      assert.match(
        emitted,
        /Permissions-Policy: camera=\(self\), microphone=\(self\), geolocation=\(\)/,
      )
      assert.doesNotMatch(emitted, /127\.0\.0\.1|192\.168\.|HRIS_PROXY_KEY|APP_KEY/)
      assert.match(await readFile(join(output, 'index.html'), 'utf8'), /<div id="app"><\/div>/)
      assert.deepEqual(JSON.parse(await readFile(join(output, '_routes.json'), 'utf8')), {
        version: 1,
        include: ['/api', '/api/*', '/up'],
        exclude: [],
      })
    } finally {
      const resolved = resolve(temporaryRoot)
      if (
        dirname(resolved) !== resolve(tmpdir()) ||
        !basename(resolved).startsWith('hris-security-headers-')
      )
        throw new Error('Unexpected security-header build output path; cleanup refused.')
      await rm(resolved, { recursive: true, force: true })
    }
  },
)
