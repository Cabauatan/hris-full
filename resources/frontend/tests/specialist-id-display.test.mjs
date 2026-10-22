import test from 'node:test'
import assert from 'node:assert/strict'
import * as vue from 'vue'
import * as forms from '../src/utils/forms.js'
import * as reports from '../src/utils/reports.js'
import * as records from '../src/utils/records.js'
import * as calendar from '../src/utils/calendar.js'
import { mountComponent, response, settle } from './helpers/component-harness.mjs'

const payrollReport = {
  key: 'payroll',
  date_basis: 'period',
  statuses: ['finalized'],
  columns: [
    { key: 'id', label: 'Record ID' },
    { key: 'payroll_run_id', label: 'Run ID' },
    { key: 'employee_number', label: 'Employee number' },
    { key: 'net_pay', label: 'Net pay', type: 'decimal' },
  ],
  can_export: true,
}
const defaults = { scope: 'visible', start: '2026-10-01', end: '2026-10-31' }

async function mountReports(auth) {
  const calls = []
  const downloads = []
  const view = await mountComponent('views/ReportsView.vue', {
    '@/stores/auth': { useAuthStore: () => auth },
    '@/utils/forms': forms,
    '@/utils/reports': reports,
    '@/utils/records': records,
    '@/api/http': {
      default: {
        get: async (path, options) => {
          calls.push({ path, options })
          return response(
            path === '/reports'
              ? { reports: [payrollReport], default_filters: defaults }
              : {
                  report: payrollReport,
                  filters: reports.reportFilters(payrollReport, options.params),
                  pagination: { current_page: 1, per_page: 20, last_page: 1, total: 1 },
                  rows: [],
                  export: { available: true },
                },
          )
        },
      },
      apiError: (failure) => ({ message: failure.message, errors: {} }),
      downloadFile: async (...args) => downloads.push(args),
    },
  })
  return { ...view, calls, downloads }
}

test('report filters show employee and period labels while requests and CSV retain internal filter keys', async (context) => {
  const auth = vue.reactive({
    authenticated: true,
    user: { id: 12 },
    employee: { id: 82, employee_number: 'EMP-0082', first_name: 'Ana', last_name: 'Reyes' },
    can: () => true,
    canAny: () => true,
  })
  const view = await mountReports(auth)
  context.after(() => view.app.unmount())
  view.state.draft.value.employee_id = 82
  view.state.draft.value.payroll_run_id = 55
  view.state.rememberSelection('employee', { value: 82, label: 'EMP-0082 · Ana Reyes' })
  view.state.rememberSelection('payroll', { value: 55, label: 'OCT-2026 · Run 1' })

  await view.state.applyFilters()
  await view.state.exportReport()

  assert.deepEqual(
    view.state.reportColumns.value.map((column) => column.key),
    ['employee_number', 'net_pay'],
  )
  assert.equal(view.calls.at(-1).options.params.employee_id, '82')
  assert.equal(view.calls.at(-1).options.params.payroll_run_id, '55')
  assert.match(view.state.appliedLabel.value, /EMP-0082 · Ana Reyes/)
  assert.match(view.state.appliedLabel.value, /OCT-2026 · Run 1/)
  assert.doesNotMatch(view.state.appliedLabel.value, /Employee #82|run #55/)
  assert.equal(view.downloads[0][1].employee_id, '82')
  assert.equal(view.downloads[0][1].payroll_run_id, '55')

  view.state.draft.value.employee_id = 90
  view.state.rememberSelection('employee', { value: 90, label: 'EMP-0090 · Luis Cruz' })
  assert.match(view.state.appliedLabel.value, /EMP-0082 · Ana Reyes/)
  assert.doesNotMatch(view.state.appliedLabel.value, /Luis Cruz/)
})

test('report own employee selector uses a local profile without granting a directory endpoint and clears labels after logout', async (context) => {
  const auth = vue.reactive({
    authenticated: true,
    user: { id: 12 },
    employee: { id: 82, employee_number: 'EMP-0082', first_name: 'Ana', last_name: 'Reyes' },
    can: () => false,
    canAny: () => false,
  })
  const view = await mountReports(auth)
  context.after(() => view.app.unmount())
  view.state.draft.value.scope = 'mine'
  view.state.draft.value.employee_id = 82
  view.state.rememberSelection('employee', { value: 82, label: 'EMP-0082 · Ana Reyes' })

  assert.equal(view.state.employeeField.value.lookup.endpoint, undefined)
  assert.equal(view.state.employeeField.value.lookup.options[0].id, 82)
  assert.equal(view.state.employeeField.value.lookup.selectedLabel, 'EMP-0082 · Ana Reyes')
  assert.equal(view.state.payrollField.value.lookup.endpoint, undefined)
  assert.equal(
    view.calls.some((call) => call.path === '/employees'),
    false,
  )

  auth.authenticated = false
  await settle()

  assert.deepEqual(view.state.selectionLabels.value, { employee: {}, payroll: {} })
  assert.equal(view.state.preview.value, null)
})

test('calendar details retain schedule and shift names and hide raw IDs from nested details', async (context) => {
  const event = {
    id: 'schedule:998:2026-10-13',
    type: 'schedule',
    title: 'Office day',
    details: {
      work_schedule_id: 998,
      work_schedule_name: 'Monday to Friday',
      shift_id: 442,
      shift_name: 'Office 8 AM',
      is_rest_day: false,
      imported: { id: 887, uuid: 'internal-record-token', code: 'OFFICE' },
    },
  }
  const view = await mountComponent('views/CalendarView.vue', {
    'vue-router': { useRoute: () => ({ query: {} }) },
    '@/stores/auth': { useAuthStore: () => ({ employee: null }) },
    '@/utils/forms': forms,
    '@/utils/calendar': calendar,
    '@/utils/records': records,
    '@/api/http': {
      default: {
        get: async () =>
          response({ today: '2026-10-13', scope: 'mine', allowed_types: [], events: [] }),
      },
      apiError: () => ({ message: 'Failed' }),
    },
  })
  context.after(() => view.app.unmount())

  view.state.selectedEvent.value = event

  const detail = JSON.stringify(view.state.selectedDetails.value)
  assert.match(detail, /Monday to Friday/)
  assert.match(detail, /Office 8 AM/)
  assert.match(detail, /OFFICE/)
  assert.doesNotMatch(detail, /work_schedule_id|shift_id|998|442|887|internal-record-token/)
  assert.equal(event.details.work_schedule_id, 998)
})

test('asset ticket subject choices still submit the original numeric ticket key and employee business number', async (context) => {
  const previousWindow = globalThis.window
  globalThis.window = { addEventListener() {}, removeEventListener() {} }
  let view
  context.after(() => {
    view?.app.unmount()
    globalThis.window = previousWindow
  })
  const calls = []
  const auth = { can: (permission) => ['inventory.manage', 'tickets.view'].includes(permission) }
  view = await mountComponent('views/AssetDetailView.vue', {
    'vue-router': { useRoute: () => ({ params: { id: 34 } }), RouterLink: {} },
    '@/stores/auth': { useAuthStore: () => auth },
    '@/api/http': {
      default: {
        get: async (path) => ({
          data: {
            data:
              path === '/api/tickets'
                ? { tickets: [{ id: 91, subject: 'Printer supplies', status: 'open' }] }
                : {
                    asset: {
                      type: 'consumable',
                      version: 3,
                      quantity: 5,
                      unit_cost: null,
                      history: [],
                      tickets: [],
                    },
                  },
          },
        }),
        post: async (path, payload) => {
          calls.push({ path, payload })
        },
      },
      downloadFile: async () => {},
    },
  })
  assert.equal(view.state.canLinkTickets.value, false)
  assert.equal(view.state.tickets.value[0].subject, 'Printer supplies')
  view.state.stock.value.ticket_id = '91'
  view.state.stock.value.employee_code = 'EMP-0082'
  view.state.stock.value.recipient = 'Ana Reyes'
  view.state.stock.value.note = 'Printer setup'

  await view.state.recordStock()

  assert.equal(calls[0].path, '/api/inventory/34/stock')
  assert.equal(calls[0].payload.ticket_id, 91)
  assert.equal(calls[0].payload.employee_code, 'EMP-0082')
})
