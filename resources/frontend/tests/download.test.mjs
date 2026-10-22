import test, { afterEach, beforeEach } from 'node:test'
import assert from 'node:assert/strict'
import { AxiosError } from 'axios'
import { apiError, downloadFile, http } from '../src/api/http.js'

let previousWindow
let previousDocument
let previousCreateObjectURL
let previousRevokeObjectURL
let previousAdapter
let anchors
let urls
let revoked
let timeouts

beforeEach(() => {
  previousWindow = globalThis.window
  previousDocument = globalThis.document
  previousCreateObjectURL = URL.createObjectURL
  previousRevokeObjectURL = URL.revokeObjectURL
  previousAdapter = http.defaults.adapter
  anchors = []
  urls = []
  revoked = []
  timeouts = []
  globalThis.window = { setTimeout: (callback, delay) => timeouts.push({ callback, delay }) }
  globalThis.document = {
    createElement: () => {
      const anchor = {
        click() {
          this.clicked = true
        },
        remove() {
          this.removed = true
        },
      }
      anchors.push(anchor)
      return anchor
    },
    body: { append() {} },
  }
  URL.createObjectURL = (blob) => {
    urls.push(blob)
    return 'blob:owned-download'
  }
  URL.revokeObjectURL = (href) => revoked.push(href)
})
afterEach(() => {
  if (previousWindow === undefined) delete globalThis.window
  else globalThis.window = previousWindow
  if (previousDocument === undefined) delete globalThis.document
  else globalThis.document = previousDocument
  URL.createObjectURL = previousCreateObjectURL
  URL.revokeObjectURL = previousRevokeObjectURL
  http.defaults.adapter = previousAdapter
})
function response(config, data, headers = {}, status = 200) {
  return { config, data, headers, status, statusText: String(status), request: {} }
}

test('download preserves bytes, uses sanitized UTF8 filename and releases its object URL', async () => {
  const csv = new Blob(['Employee,Net pay\r\nEMP-1,9000000000000000.99\r\n'], { type: 'text/csv' })
  let received
  http.defaults.adapter = async (config) => {
    received = config
    return response(config, csv, {
      'content-disposition':
        "attachment; filename=report.csv; filename*=UTF-8''..%2FHR%20r%C3%A9port.csv",
    })
  }

  const downloaded = await downloadFile('/reports/payroll/export', {
    scope: 'visible',
    payroll_run_id: 7,
  })

  assert.equal(downloaded, true)
  assert.deepEqual(received.params, { scope: 'visible', payroll_run_id: 7 })
  assert.equal(received.responseType, 'blob')
  assert.equal(urls[0], csv)
  assert.equal(anchors[0].download, 'HR réport.csv')
  assert.equal(anchors[0].clicked, true)
  assert.equal(anchors[0].removed, true)
  assert.equal(timeouts[0].delay, 1000)
  timeouts[0].callback()
  assert.deepEqual(revoked, ['blob:owned-download'])
})

test('export JSON Blob failure retains actionable server validation errors', async () => {
  http.defaults.adapter = async (config) => {
    const data = new Blob(
      [
        JSON.stringify({
          message: 'Narrow your filters.',
          errors: { export: ['Only 10,000 records may be exported.'] },
        }),
      ],
      { type: 'application/json' },
    )
    const result = response(config, data, { 'content-type': 'application/json' }, 422)
    throw new AxiosError(
      'Request failed',
      AxiosError.ERR_BAD_REQUEST,
      config,
      result.request,
      result,
    )
  }

  await assert.rejects(downloadFile('/reports/attendance/export'), (failure) => {
    assert.deepEqual(apiError(failure), {
      status: 422,
      message: 'Narrow your filters.',
      errors: { export: ['Only 10,000 records may be exported.'] },
    })
    return true
  })
  assert.equal(anchors.length, 0)
})

test('malformed or non-object JSON Blob error remains a failure without creating a file', async () => {
  for (const text of ['{invalid', '["unexpected"]']) {
    const blob = new Blob([text], { type: 'application/json' })
    http.defaults.adapter = async (config) => {
      const result = response(config, blob, {}, 500)
      throw new AxiosError(
        'Request failed',
        AxiosError.ERR_BAD_RESPONSE,
        config,
        result.request,
        result,
      )
    }

    await assert.rejects(downloadFile('/reports/attendance/export'), (failure) => {
      assert.equal(failure.response.data, blob)
      return true
    })
  }
  assert.equal(anchors.length, 0)
})

test('a late account guard prevents creating an anchor or object URL for old-account data', async () => {
  let account = 1
  http.defaults.adapter = async (config) => {
    account = 2
    return response(config, new Blob(['old-account-data']))
  }

  const downloaded = await downloadFile(
    '/reports/payroll/export',
    {},
    { shouldDownload: () => account === 1 },
  )

  assert.equal(downloaded, false)
  assert.equal(anchors.length, 0)
  assert.equal(urls.length, 0)
})

test('download passes cancellation signal and never saves an aborted transfer', async () => {
  const controller = new AbortController()
  let received
  http.defaults.adapter = async (config) => {
    received = config.signal
    controller.abort()
    return response(config, new Blob(['private-data']))
  }

  await assert.rejects(
    downloadFile('/reports/payroll/export', {}, { signal: controller.signal }),
    (failure) => failure.code === 'ERR_CANCELED',
  )

  assert.equal(received, controller.signal)
  assert.equal(anchors.length, 0)
})
