import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { babelParse, compileScript, parse } from '@vue/compiler-sfc'
import * as vue from 'vue'
import * as forms from '../src/utils/forms.js'
import * as reports from '../src/utils/reports.js'
import * as records from '../src/utils/records.js'

const renderer = vue.createRenderer({
  createElement: () => ({}),
  createText: () => ({}),
  createComment: () => ({}),
  insert() {},
  remove() {},
  setText() {},
  setElementText() {},
  patchProp() {},
  parentNode: () => null,
  nextSibling: () => null,
})
const descriptors = [
  {
    key: 'attendance',
    label: 'Daily attendance',
    description: 'Workdays',
    kind: 'attendance',
    date_basis: 'attendance_date',
    statuses: ['present', 'absent'],
    year_filter: false,
    columns: [{ key: 'employee_number', label: 'Employee', type: 'text' }],
    can_export: true,
  },
  {
    key: 'leave-balances',
    label: 'Leave balances',
    description: 'Credits',
    kind: 'balance',
    date_basis: null,
    statuses: [],
    year_filter: true,
    columns: [{ key: 'available_days', label: 'Available days', type: 'decimal' }],
    can_export: true,
  },
  {
    key: 'payroll',
    label: 'Payroll register',
    description: 'Payroll',
    kind: 'payroll',
    date_basis: 'period',
    statuses: ['draft', 'finalized'],
    year_filter: false,
    columns: [{ key: 'net_pay', label: 'Net pay', type: 'decimal' }],
    can_export: true,
  },
]
const defaults = { scope: 'visible', start: '2026-10-01', end: '2026-10-31', year: 2026 }
function response(data) {
  return { data: { data } }
}
function preview(key = 'attendance', filters = {}, overrides = {}) {
  const report = descriptors.find((item) => item.key === key)
  return {
    report,
    filters: { ...reports.reportFilters(report, defaults), ...filters },
    available_scopes: ['visible', 'mine'],
    generated_at: '2026-10-05T08:00:00+08:00',
    summary: { records: 42, metrics: [] },
    rows: [{ employee_number: 'EMP-7' }],
    pagination: { current_page: 1, per_page: 20, last_page: 3, total: 42 },
    export: { format: 'csv', max_rows: 10000, available: true },
    ...overrides,
  }
}
function deferred() {
  let resolve
  let reject
  const promise = new Promise((yes, no) => {
    resolve = yes
    reject = no
  })
  return { promise, resolve, reject }
}
async function settle() {
  for (let index = 0; index < 5; index += 1) {
    await Promise.resolve()
    await vue.nextTick()
  }
}
async function mountReports({ get, download } = {}) {
  const calls = []
  const downloads = []
  const auth = vue.reactive({ user: { id: 1 }, authenticated: true })
  const api = {
    get: async (path, options) => {
      calls.push({ path, ...options })
      if (get) return get(path, options, auth)
      if (path === '/reports')
        return response({
          reports: descriptors,
          default_filters: defaults,
          available_scopes: ['visible', 'mine'],
          export: { format: 'csv', max_rows: 10000 },
        })
      return response(preview(path.split('/').at(-1), options.params))
    },
  }
  const dependencies = new Proxy(
    {
      vue,
      '@/api/http': {
        default: api,
        apiError: (failure) => ({ message: failure.message, errors: failure.errors || {} }),
        downloadFile: async (...args) => {
          downloads.push(args)
          return download ? download(...args) : true
        },
      },
      '@/stores/auth': { useAuthStore: () => auth },
      '@/utils/forms': forms,
      '@/utils/reports': reports,
      '@/utils/records': records,
    },
    { get: (target, key) => target[key] || { default: {} } },
  )
  const { descriptor } = parse(
    await readFile(new URL('../src/views/ReportsView.vue', import.meta.url), 'utf8'),
  )
  const compiled = compileScript(descriptor, { id: 'reports' })
  const edits = []
  for (const node of babelParse(compiled.content, { sourceType: 'module' }).program.body) {
    if (node.type === 'ImportDeclaration')
      edits.push({
        start: node.start,
        end: node.end,
        replacement: node.specifiers
          .map(
            (specifier) =>
              `const ${specifier.local.name} = dependencies[${JSON.stringify(node.source.value)}][${JSON.stringify(specifier.type === 'ImportDefaultSpecifier' ? 'default' : specifier.imported.name)}];`,
          )
          .join('\n'),
      })
    else if (node.type === 'ExportDefaultDeclaration')
      edits.push({ start: node.start, end: node.declaration.start, replacement: 'return ' })
  }
  let source = compiled.content
  for (const edit of edits.sort((left, right) => right.start - left.start))
    source = source.slice(0, edit.start) + edit.replacement + source.slice(edit.end)
  const component = new Function('dependencies', source)(dependencies)
  let state
  const app = renderer.createApp({
    setup() {
      state = component.setup({}, { expose() {} })
      return () => null
    },
  })
  app.mount({})
  await settle()
  return { app, state, auth, calls, downloads }
}

test('report page loads authorized catalog and initial preview with server defaults', async (context) => {
  const view = await mountReports()
  context.after(() => view.app.unmount())

  assert.deepEqual(
    view.calls.map((call) => call.path),
    ['/reports', '/reports/attendance'],
  )
  assert.deepEqual(view.calls[1].params, {
    scope: 'visible',
    employee_id: null,
    start: '2026-10-01',
    end: '2026-10-31',
    status: null,
    page: 1,
    per_page: 20,
  })
  assert.equal(view.state.preview.value.rows[0].employee_number, 'EMP-7')
  assert.equal(view.state.hasDraftChanges.value, false)
})

test('export uses displayed applied filters while unsubmitted draft changes remain visible', async (context) => {
  const view = await mountReports()
  context.after(() => view.app.unmount())
  view.state.draft.value.start = '2026-09-01'
  view.state.draft.value.employee_id = '88'

  await view.state.exportReport()

  assert.equal(view.state.hasDraftChanges.value, true)
  assert.equal(view.downloads[0][0], '/reports/attendance/export')
  assert.deepEqual(view.downloads[0][1], {
    scope: 'visible',
    employee_id: null,
    start: '2026-10-01',
    end: '2026-10-31',
    status: null,
  })
  assert.equal(view.state.exportSuccess.value, 'CSV download started.')
})

test('apply updates normalized filters and pagination uses them without submitting later edits', async (context) => {
  const view = await mountReports()
  context.after(() => view.app.unmount())
  view.state.draft.value.employee_id = '7'
  view.state.draft.value.status = 'absent'
  await view.state.applyFilters()
  view.state.draft.value.employee_id = '99'

  await view.state.changePage({ page: 1, rows: 50 })

  assert.equal(view.calls.at(-1).params.employee_id, '7')
  assert.equal(view.calls.at(-1).params.status, 'absent')
  assert.equal(view.calls.at(-1).params.page, 2)
  assert.equal(view.calls.at(-1).params.per_page, 50)
  assert.equal(view.state.draft.value.employee_id, '99')
  assert.equal(view.state.hasDraftChanges.value, true)
})

test('changing to balance snapshot clears date/status filters and late old report responses', async (context) => {
  const old = deferred()
  let held = false
  const view = await mountReports({
    get: (path, options) => {
      if (path === '/reports')
        return response({
          reports: descriptors,
          default_filters: defaults,
          available_scopes: ['visible', 'mine'],
        })
      if (path === '/reports/attendance' && held) return old.promise
      return response(preview(path.split('/').at(-1), options.params))
    },
  })
  context.after(() => view.app.unmount())
  held = true
  const pending = view.state.applyFilters()
  view.state.selectedKey.value = 'leave-balances'

  await view.state.changeReport()
  old.resolve(response(preview('attendance')))
  await pending

  assert.equal(view.state.preview.value.report.key, 'leave-balances')
  assert.deepEqual(view.calls.at(-1).params, {
    scope: 'visible',
    employee_id: null,
    year: '2026',
    page: 1,
    per_page: 20,
  })
  assert.equal(view.state.draft.value.start, '')
  assert.equal(view.state.statusOptions.value.length, 0)
})

test('validation failure preserves applied preview and exposes field error for correction', async (context) => {
  let invalid = false
  const view = await mountReports({
    get: (path, options) => {
      if (path === '/reports')
        return response({
          reports: descriptors,
          default_filters: defaults,
          available_scopes: ['visible'],
        })
      if (invalid)
        throw {
          message: 'Invalid filters',
          errors: { employee_id: ['Employee ID must be positive.'] },
        }
      return response(preview('attendance', options.params))
    },
  })
  context.after(() => view.app.unmount())
  invalid = true
  view.state.draft.value.employee_id = '-1'

  await view.state.applyFilters()

  assert.equal(view.state.error.value, 'Employee ID must be positive.')
  assert.equal(view.state.fieldError('employee_id'), 'Employee ID must be positive.')
  assert.equal(view.state.preview.value.filters.employee_id, null)
  assert.equal(view.state.canExport.value, true)
})

test('server export availability prevents downloading over-limit preview', async (context) => {
  const view = await mountReports()
  context.after(() => view.app.unmount())
  view.state.preview.value.export.available = false
  view.state.preview.value.pagination.total = 10001

  await view.state.exportReport()

  assert.equal(view.state.canExport.value, false)
  assert.equal(view.downloads.length, 0)
  assert.equal(view.state.exportLimit.value, 10000)
})

test('export is single-flight and presents server rate-limit failure', async (context) => {
  const transfer = deferred()
  const view = await mountReports({ download: () => transfer.promise })
  context.after(() => view.app.unmount())
  const first = view.state.exportReport()

  await view.state.exportReport()
  transfer.reject({ message: 'Too many attempts. Please wait before trying again.' })
  await first

  assert.equal(view.downloads.length, 1)
  assert.equal(view.state.exportError.value, 'Too many attempts. Please wait before trying again.')
  assert.equal(view.state.exporting.value, false)
})

test('logout clears private rows synchronously and invalidates any pending file download', async (context) => {
  const transfer = deferred()
  const view = await mountReports({ download: () => transfer.promise })
  context.after(() => view.app.unmount())
  const pending = view.state.exportReport()
  const options = view.downloads[0][2]

  view.auth.authenticated = false

  assert.equal(view.state.preview.value, null)
  assert.equal(view.state.catalog.value, null)
  assert.equal(options.signal.aborted, true)
  assert.equal(options.shouldDownload(), false)
  transfer.resolve(false)
  await pending
  assert.equal(view.state.exportSuccess.value, '')
})

test('account switch cannot display an old account late preview', async (context) => {
  const old = deferred()
  let held = false
  const view = await mountReports({
    get: (path, options, auth) => {
      if (path === '/reports')
        return response({
          reports: descriptors,
          default_filters: defaults,
          available_scopes: ['visible'],
        })
      if (held && auth.user.id === 1) return old.promise
      return response(
        preview('attendance', options.params, {
          rows: [{ employee_number: `ACCOUNT-${auth.user.id}` }],
        }),
      )
    },
  })
  context.after(() => view.app.unmount())
  held = true
  const pending = view.state.refreshPreview()

  view.auth.user = { id: 2 }
  await settle()
  old.resolve(response(preview('attendance', {}, { rows: [{ employee_number: 'ACCOUNT-1' }] })))
  await pending

  assert.equal(view.state.preview.value.rows[0].employee_number, 'ACCOUNT-2')
})

test('leaving report page cancels preview and blocks a late response', async () => {
  const old = deferred()
  let held = false
  const view = await mountReports({
    get: (path, options) => {
      if (path === '/reports')
        return response({
          reports: descriptors,
          default_filters: defaults,
          available_scopes: ['visible'],
        })
      return held ? old.promise : response(preview('attendance', options.params))
    },
  })
  held = true
  const pending = view.state.refreshPreview()
  const signal = view.calls.at(-1).signal

  view.app.unmount()
  old.resolve(response(preview()))
  await pending

  assert.equal(signal.aborted, true)
  assert.equal(view.state.preview.value, null)
})

test('catalog connection error can retry into a usable preview', async (context) => {
  let unavailable = true
  const view = await mountReports({
    get: (path, options) => {
      if (unavailable) throw new Error('Server unavailable')
      if (path === '/reports')
        return response({
          reports: descriptors,
          default_filters: defaults,
          available_scopes: ['visible'],
        })
      return response(preview('attendance', options.params))
    },
  })
  context.after(() => view.app.unmount())
  assert.equal(view.state.error.value, 'Server unavailable')
  unavailable = false

  await view.state.loadCatalog()

  assert.equal(view.state.error.value, '')
  assert.equal(view.state.preview.value.report.key, 'attendance')
})

test('payroll run filter applies only to payroll and reset returns server defaults', async (context) => {
  const view = await mountReports()
  context.after(() => view.app.unmount())
  view.state.selectedKey.value = 'payroll'
  await view.state.changeReport()
  view.state.draft.value.payroll_run_id = '8'
  await view.state.applyFilters()
  assert.equal(view.calls.at(-1).params.payroll_run_id, '8')

  await view.state.resetFilters()

  assert.equal(view.calls.at(-1).params.payroll_run_id, null)
  assert.equal(view.state.hasDraftChanges.value, false)
})
