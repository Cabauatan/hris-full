import test from 'node:test'
import assert from 'node:assert/strict'
import * as vue from 'vue'
import { deferred, mountComponent, response, settle } from './helpers/component-harness.mjs'

function field(endpoint = '/employees', options = {}) {
  return {
    key: 'employee_id',
    lookup: { endpoint, labelFields: ['employee_number', 'first_name'], ...options },
  }
}

async function mount(
  context,
  { lookupField = field(), get, modelValue = '', authenticated = true } = {},
) {
  const calls = []
  const auth = vue.reactive({ user: { id: 70 }, authenticated })
  const view = await mountComponent(
    'components/LookupPicker.vue',
    {
      '@/api/http': {
        default: {
          get: async (path, options) => {
            calls.push({ path, options })
            return get ? get(path, options) : response([], { last_page: 1 })
          },
        },
      },
      '@/stores/auth': { useAuthStore: () => auth },
    },
    { field: lookupField, modelValue, disabled: false },
  )
  context.after(() => view.app.unmount())
  return { ...view, calls, auth }
}

test('switching a lookup endpoint loads the new directory and ignores the pending old records', async (context) => {
  const old = deferred()
  const view = await mount(context, {
    get: (path) =>
      path === '/employees'
        ? old.promise
        : response([{ id: 9, name: 'Vacation leave' }], { last_page: 1 }),
  })

  view.props.field = {
    key: 'leave_type_id',
    lookup: { endpoint: '/leave-types', labelFields: ['name'] },
  }
  await settle()
  old.resolve(response([{ id: 7, first_name: 'Private employee' }], { last_page: 1 }))
  await settle()

  assert.deepEqual(
    view.calls.map(({ path }) => path),
    ['/employees', '/leave-types'],
  )
  assert.deepEqual(view.state.options.value, [{ value: 9, label: 'Vacation leave' }])
  assert.equal(view.calls[0].options.signal.aborted, true)
  assert.equal(view.state.loading.value, false)
})

test('changing filters on the same lookup endpoint clears rows and fetches its new page one', async (context) => {
  const view = await mount(context, {
    lookupField: field('/employees', { params: { is_active: 1 } }),
    get: (path, { params }) =>
      response(
        [
          {
            id: params.is_active ? 7 : 8,
            first_name: params.is_active ? 'Active employee' : 'Inactive employee',
          },
        ],
        { last_page: 1 },
      ),
  })
  assert.equal(view.state.rows.value[0].id, 7)

  view.props.field.lookup.params.is_active = 0
  await settle()

  assert.deepEqual(
    view.calls.map(({ options }) => options.params),
    [
      { per_page: 100, page: 1, is_active: 1 },
      { per_page: 100, page: 1, is_active: 0 },
    ],
  )
  assert.deepEqual(view.state.options.value, [{ value: 8, label: 'Inactive employee' }])
})

test('account changes clear private options and an old-account fetch cannot restore them', async (context) => {
  const old = deferred()
  let request = 0
  const view = await mount(context, {
    get: () =>
      ++request === 1
        ? response([{ id: 7, first_name: 'Old account private employee' }], { last_page: 2 })
        : old.promise,
  })
  const pending = view.state.load()
  assert.equal(view.state.rows.value.length, 1)

  view.auth.authenticated = false
  view.auth.user = null
  assert.deepEqual(view.state.rows.value, [])
  old.resolve(response([{ id: 8, first_name: 'Late private employee' }], { last_page: 2 }))
  await pending
  await settle()

  assert.deepEqual(view.state.options.value, [])
  assert.equal(view.state.loading.value, false)
  assert.equal(view.calls[1].options.signal.aborted, true)
  assert.equal(view.calls.length, 2)
})

test('a new signed-in account loads fresh lookup data instead of retaining the prior directory', async (context) => {
  let request = 0
  const view = await mount(context, {
    get: () =>
      response(
        [
          {
            id: ++request,
            first_name: request === 1 ? 'Old account employee' : 'New account employee',
          },
        ],
        { last_page: 1 },
      ),
  })

  view.auth.user = { id: 71 }
  await settle()

  assert.deepEqual(view.state.options.value, [{ value: 2, label: 'New account employee' }])
  assert.equal(view.calls.length, 2)
})

test('unmount cancels a pending lookup and prevents a late result from populating rows', async (context) => {
  const transfer = deferred()
  const view = await mount(context, { get: () => transfer.promise })

  view.app.unmount()
  transfer.resolve(response([{ id: 7, first_name: 'Late employee' }], { last_page: 1 }))
  await settle()

  assert.equal(view.calls[0].options.signal.aborted, true)
  assert.deepEqual(view.state.rows.value, [])
})

test('lookup paging keeps filter constraints and balance eligibility with an unloaded selected record placeholder', async (context) => {
  const view = await mount(context, {
    modelValue: 99,
    lookupField: field('/leave-types', {
      labelFields: ['name', 'code'],
      filters: { is_active: 1 },
      requiresBalance: true,
    }),
    get: (path, { params }) =>
      response(
        params.page === 1
          ? [
              { id: 7, name: 'Vacation', code: 'VL', requires_balance: true },
              { id: 8, name: 'Special', requires_balance: false },
            ]
          : [{ id: 9, name: 'Sick', code: 'SL', requires_balance: true }],
        { last_page: 2 },
      ),
  })

  await view.state.load()

  assert.equal(view.state.options.value[0].value, 99)
  assert.ok(!view.state.options.value[0].label.includes('99'))
  assert.deepEqual(view.state.options.value.slice(1), [
    { value: 7, label: 'Vacation · VL' },
    { value: 9, label: 'Sick · SL' },
  ])
  assert.deepEqual(
    view.calls.map(({ options }) => options.params),
    [
      { per_page: 100, page: 1, is_active: 1 },
      { per_page: 100, page: 2, is_active: 1 },
    ],
  )
})

test('lookup failure preserves a readable selection and retry restores directory options', async (context) => {
  let fail = true
  const view = await mount(context, {
    modelValue: 7,
    lookupField: field('/employees', { selectedLabel: 'Ana Cruz · EMP-0091' }),
    get: async () => {
      if (fail) throw new Error('Directory is unavailable')
      return response([{ id: 7, first_name: 'Employee' }], { last_page: 1 })
    },
  })
  assert.equal(view.state.unavailable.value, true)
  assert.deepEqual(view.state.options.value, [{ value: 7, label: 'Ana Cruz · EMP-0091' }])
  assert.equal(view.props.modelValue, 7)
  fail = false

  await view.state.load(true)

  assert.equal(view.state.unavailable.value, false)
  assert.deepEqual(view.state.options.value, [{ value: 7, label: 'Employee' }])
})

test('a preauthorized own-record lookup uses names without loading the wider directory', async (context) => {
  const view = await mount(context, {
    modelValue: 7,
    lookupField: field('/employees', {
      options: [{ value: 7, label: 'Ana Cruz · EMP-0091' }],
    }),
  })

  await view.state.load(true)

  assert.equal(view.calls.length, 0)
  assert.deepEqual(view.state.options.value, [{ value: 7, label: 'Ana Cruz · EMP-0091' }])
})

test('a selected named record remains readable before the directory finishes loading', async (context) => {
  const pending = deferred()
  const view = await mount(context, {
    modelValue: 7921,
    lookupField: field('/employees', { selectedLabel: 'Ana Cruz · EMP-0091' }),
    get: () => pending.promise,
  })

  assert.deepEqual(view.state.options.value, [{ value: 7921, label: 'Ana Cruz · EMP-0091' }])
  assert.equal(view.props.modelValue, 7921)
  pending.resolve(
    response([{ id: 7921, first_name: 'Ana', employee_number: 'EMP-0091' }], { last_page: 1 }),
  )
  await settle()
  assert.deepEqual(view.state.options.value, [{ value: 7921, label: 'EMP-0091 · Ana' }])
})

test('unlabelled directory entries use neutral labels while preserving numeric option values', async (context) => {
  const view = await mount(context, {
    get: () => response([{ id: 9384 }], { last_page: 1 }),
  })

  assert.equal(view.state.options.value[0].value, 9384)
  assert.ok(!view.state.options.value[0].label.includes('9384'))
})

test('changing a selected label updates its display without refetching or cancelling the directory', async (context) => {
  const view = await mount(context, {
    modelValue: 7921,
    lookupField: field('/employees', { selectedLabel: 'Ana Cruz · EMP-0091' }),
    get: () => response([], { last_page: 1 }),
  })

  view.props.field.lookup.selectedLabel = 'Ana Reyes · EMP-0091'
  await settle()

  assert.equal(view.calls.length, 1)
  assert.equal(view.calls[0].options.signal.aborted, false)
  assert.deepEqual(view.state.options.value, [{ value: 7921, label: 'Ana Reyes · EMP-0091' }])
  assert.deepEqual(view.events.at(-1), [
    'selection-change',
    { value: 7921, label: 'Ana Reyes · EMP-0091' },
  ])
})

test('lookup makes no directory request when the account is unauthenticated', async (context) => {
  const view = await mount(context, { authenticated: false })

  await view.state.load(true)

  assert.equal(view.calls.length, 0)
  assert.deepEqual(view.state.rows.value, [])
})
