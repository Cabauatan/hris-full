import test from 'node:test'
import assert from 'node:assert/strict'
import {
  columnValue,
  displayLabel,
  displayRecord,
  isInternalIdentifier,
  lookupLabel,
  recordLabel,
} from '../src/utils/records.js'
import * as forms from '../src/utils/forms.js'
import { mountComponent, mountWorkspace, response } from './helpers/component-harness.mjs'

function assertNoIdentifiers(value) {
  if (!value || typeof value !== 'object') return
  for (const [key, child] of Object.entries(value)) {
    assert.equal(isInternalIdentifier(key), false, `Display exposed ${key}`)
    assertNoIdentifiers(child)
  }
}

test('internal identifier detection preserves employee numbers and meaningful business fields', () => {
  for (const key of ['id', 'uuid', 'employee_id', 'user_uuid', 'participant_ids'])
    assert.equal(isInternalIdentifier(key), true, key)
  for (const key of ['employee_number', 'code', 'run_number', 'is_paid', 'paid', 'identity'])
    assert.equal(isInternalIdentifier(key), false, key)
  assert.equal(displayLabel('employee_id', 'Employee ID'), 'Employee')
  assert.equal(displayLabel('work_schedule_id', 'Schedule ID'), 'Schedule')
})

test('display details remove identifiers recursively while preserving readable labels and raw data', () => {
  const record = {
    id: 9471,
    uuid: 'internal-request-uuid',
    employee_id: 8732,
    employee_number: 'EMP-0001',
    amount: '9000000000000.99',
    display_labels: { employee_id: 'Marcel Jay Cabauatan · EMP-0001' },
    employee: { id: 8732, first_name: 'Marcel Jay', last_name: 'Cabauatan' },
    old_values: {
      position_id: 6528,
      title: 'Assistant',
      manager: { user_id: 2982, name: 'HR Team' },
    },
    new_values: { position_id: 6719, title: 'Officer' },
    metadata: [{ id: 1735, participant_ids: [7382, 8927], note: 'Reviewed by HR' }],
  }
  const before = structuredClone(record)
  const detail = displayRecord(record)

  assertNoIdentifiers(detail)
  const text = JSON.stringify(detail)
  assert.ok(text.includes('Marcel Jay Cabauatan'))
  assert.ok(text.includes('EMP-0001'))
  assert.ok(text.includes('HR Team'))
  assert.ok(text.includes('Reviewed by HR'))
  assert.ok(text.includes('9000000000000.99'))
  for (const identifier of [
    '9471',
    '8732',
    '6528',
    '6719',
    '2982',
    '1735',
    '7382',
    '8927',
    'internal-request-uuid',
  ])
    assert.ok(!text.includes(identifier), identifier)
  assert.deepEqual(record, before)
})

test('columns read the API display label and never fall back to the numeric foreign key', () => {
  const row = {
    id: 5471,
    employee_id: 7389,
    division_id: 8273,
    display_labels: { employee_id: 'Ana Cruz · EMP-0091', division_id: null },
  }
  assert.equal(columnValue(row, { key: 'employee_id' }), 'Ana Cruz · EMP-0091')
  for (const key of ['id', 'division_id', 'user_id']) {
    const value = String(columnValue(row, key) ?? '')
    assert.ok(!value.includes('5471') && !value.includes('8273'), key)
  }
  assert.equal(columnValue({ net_pay: '9000000000000.99' }, 'net_pay'), '9000000000000.99')
})

test('lookup and record labels expose names or neutral placeholders while retaining business codes', () => {
  const row = { id: 9384, first_name: 'Ana', last_name: 'Cruz', employee_number: 'EMP-0091' }
  const label = lookupLabel(row, ['employee_number', 'first_name', 'last_name'])
  assert.ok(label.includes('EMP-0091') && label.includes('Ana') && label.includes('Cruz'))
  assert.ok(!label.includes('9384'))
  assert.ok(!lookupLabel({ id: 9384 }, []).includes('9384'))
  assert.ok(!recordLabel({ id: 9384 }, 'Request').includes('9384'))
})

test('RecordDetails renders safe computed fields for nested relations and metadata', async (context) => {
  const view = await mountComponent(
    'components/RecordDetails.vue',
    { '@/utils/forms': forms },
    {
      record: {
        id: 8419,
        employee_id: 8435,
        display_labels: { employee_id: 'Ana Cruz · EMP-0091' },
        metadata: { actor: { id: 7432, name: 'HR Officer' }, source_id: 6542 },
        employee_number: 'EMP-0091',
      },
      columns: [{ key: 'employee_id', label: 'Employee ID' }],
    },
  )
  context.after(() => view.app.unmount())
  const fields = view.state.fields.value
  const text = JSON.stringify(fields)
  for (const identifier of ['8419', '8435', '7432', '6542']) assert.ok(!text.includes(identifier))
  assert.ok(text.includes('Ana Cruz'))
  assert.ok(text.includes('HR Officer'))
  assert.ok(text.includes('EMP-0091'))
  assert.equal(
    fields.some((field) => /\bID\b/.test(field.label)),
    false,
  )
})

test('resource search finds a readable employee label and attendance details omit raw identifiers', async (context) => {
  const record = {
    id: 4821,
    employee_id: 8271,
    attendance_date: '2026-10-13',
    status: 'present',
    display_labels: { employee_id: 'Ana Cruz · EMP-0091' },
  }
  const view = await mountWorkspace('ResourceWorkspace', {
    module: 'daily-attendances',
    http: {
      get: async (path) => response(path.endsWith('/4821') ? record : [record], { total: 1 }),
    },
  })
  context.after(() => view.app.unmount())
  view.state.search.value = 'Ana Cruz'
  assert.equal(view.state.visibleRows.value.length, 1)
  assert.equal(view.state.visibleRows.value[0].id, 4821)
  view.state.search.value = '8271'
  assert.equal(view.state.visibleRows.value.length, 0)
  await view.state.openRecord(record)
  assertNoIdentifiers(displayRecord(view.state.detailRecord.value))
  assert.equal(view.state.selected.value.employee_id, 8271)
})

test('editing a named foreign-key record retains original numeric IDs and excludes display metadata', async (context) => {
  const record = {
    id: 7421,
    division_id: 5173,
    code: 'HR',
    name: 'Human Resources',
    description: 'People services',
    is_active: true,
    display_labels: { division_id: 'Corporate Services' },
  }
  const view = await mountWorkspace('ResourceWorkspace', {
    module: 'departments',
    http: { get: async (path) => response(path.endsWith('/7421') ? record : [record]) },
  })
  context.after(() => view.app.unmount())
  await view.state.openRecord(record)
  assertNoIdentifiers(displayRecord(view.state.detailRecord.value))
  view.state.openForm('edit')
  assert.equal(view.state.draft.value.division_id, 5173)
  view.state.draft.value.name = 'Human Resources and Administration'
  await view.state.submit()
  const write = view.calls.find((call) => call.method === 'request').args[0]
  assert.equal(write.url, '/departments/7421')
  assert.equal(write.data.division_id, 5173)
  assert.equal(write.data.name, 'Human Resources and Administration')
  assert.equal(Object.hasOwn(write.data, 'display_labels'), false)
  assert.equal(Object.hasOwn(write.data, 'division'), false)
})

test('an employee without directory permission can filter using their own name while preserving the API employee key', async (context) => {
  const view = await mountWorkspace('ResourceWorkspace', {
    module: 'daily-attendances',
    permissions: ['attendance.view'],
    employee: { id: 8271, employee_number: 'EMP-0091', first_name: 'Ana', last_name: 'Cruz' },
  })
  context.after(() => view.app.unmount())
  view.auth.authenticated = true
  const field = view.state.filterFields.value.find((item) => item.key === 'employee_id')
  assert.equal(field.type, 'lookup')
  assert.equal(field.label, 'Employee')
  const directoryCalls = []
  const picker = await mountComponent(
    'components/LookupPicker.vue',
    {
      '@/stores/auth': { useAuthStore: () => view.auth },
      '@/api/http': {
        default: {
          get: async (path) => {
            directoryCalls.push(path)
            throw new Error('Own employee filter must not request a wider directory')
          },
        },
      },
    },
    { field, modelValue: '' },
  )
  context.after(() => picker.app.unmount())

  assert.deepEqual(picker.state.options.value, [{ value: 8271, label: 'EMP-0091 · Ana Cruz' }])
  view.state.filters.value.employee_id = picker.state.options.value[0].value
  await view.state.loadRecords(true)
  const query = view.calls.filter((call) => call.method === 'get').at(-1)
  assert.equal(query.args[0], '/daily-attendances')
  assert.equal(query.args[1].params.employee_id, 8271)
  assert.deepEqual(directoryCalls, [])
  assert.equal(
    view.calls.some((call) => call.args[0] === '/employees'),
    false,
  )
})
