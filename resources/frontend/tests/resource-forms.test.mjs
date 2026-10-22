import test from 'node:test'
import assert from 'node:assert/strict'
import { resources } from '../src/config/resources.js'
import { deferred, httpFailure, mountWorkspace, response } from './helpers/component-harness.mjs'

async function mount(context, options = {}) {
  const view = await mountWorkspace('ResourceWorkspace', { module: 'announcements', ...options })
  context.after(() => view.app.unmount())
  return view
}

function writes(view) {
  return view.calls.filter(({ method }) => method !== 'get')
}

test('failed admin create keeps draft and server field errors and explicit retry closes the form', async (context) => {
  let fail = true
  const view = await mount(context, {
    http: {
      request: async () => {
        if (fail)
          throw httpFailure(422, 'The given data was invalid.', {
            title: ['The title may not be greater than 200 characters.'],
          })
        return response({ id: 101 })
      },
    },
  })
  view.state.openForm()
  view.state.draft.value.title = 'Draft announcement'
  view.state.draft.value.content = 'Employee update'
  view.state.draft.value.employee_id = 900
  view.state.draft.value.published_at = '2026-01-01T00:00:00'

  await view.state.submit()

  assert.equal(view.state.formOpen.value, true)
  assert.equal(view.state.saving.value, false)
  assert.equal(view.state.draft.value.title, 'Draft announcement')
  assert.deepEqual(view.state.formErrors.value, {
    title: ['The title may not be greater than 200 characters.'],
  })
  assert.deepEqual(view.messages, [])
  assert.deepEqual(writes(view)[0].args[0], {
    method: 'post',
    url: '/announcements',
    data: {
      title: 'Draft announcement',
      content: 'Employee update',
      priority: 'normal',
      show_on_dashboard: true,
    },
  })
  fail = false
  await view.state.submit()
  assert.equal(view.state.formOpen.value, false)
  assert.equal(view.messages.at(-1).summary, 'Record created')
  assert.deepEqual(view.state.formErrors.value, {})
})

test('typed JSON setting error is caught in the open form without sending a mutation', async (context) => {
  const view = await mount(context, { module: 'system-settings' })
  view.state.selected.value = {
    id: 11,
    group: 'attendance',
    key: 'work_rules',
    type: 'json',
    value: { overtime: false, grace_minutes: 0 },
    is_editable: true,
  }
  view.state.openForm('edit')
  assert.equal(view.state.draft.value.value, '{\n  "overtime": false,\n  "grace_minutes": 0\n}')
  view.state.draft.value.value = '{ invalid'

  await view.state.submit()

  assert.equal(writes(view).length, 0)
  assert.equal(view.state.formOpen.value, true)
  assert.equal(view.state.formError.value, 'Value must contain valid JSON.')
  assert.equal(view.state.saving.value, false)
})

test('typed setting edit excludes create-only schema and sends null as an explicit clear', async (context) => {
  const view = await mount(context, { module: 'system-settings' })
  view.state.selected.value = {
    id: 11,
    group: 'attendance',
    key: 'work_rules',
    type: 'json',
    value: { overtime: false },
    is_public: false,
    is_editable: true,
  }
  view.state.openForm('edit')
  view.state.draft.value.value = null
  view.state.draft.value.group = 'changed-group'
  view.state.draft.value.key = 'changed-key'
  view.state.draft.value.type = 'string'

  await view.state.submit()

  assert.deepEqual(writes(view)[0].args[0], {
    method: 'put',
    url: '/system-settings/11',
    data: { value: null },
  })
})

test('document upload enforces 10 MB in the component before posting multipart data', async (context) => {
  const view = await mount(context, { module: 'employee-documents' })
  view.state.openForm()
  view.state.draft.value.file = { name: 'oversized.pdf', size: 10 * 1024 * 1024 + 1 }

  await view.state.submit()

  assert.equal(writes(view).length, 0)
  assert.equal(view.state.formError.value, 'The document must be 10 MB or smaller.')
  assert.equal(view.state.formOpen.value, true)
  assert.equal(view.state.saving.value, false)
})

test('document edit without a replacement file uses multipart PUT override and preserves nullable metadata', async (context) => {
  const view = await mount(context, { module: 'employee-documents' })
  view.state.selected.value = {
    id: 17,
    employee_id: 7,
    title: 'Existing certificate',
    document_type: 'certificate',
    issued_at: '2026-01-01',
    expires_at: null,
    remarks: 'Old note',
  }
  view.state.openForm('edit')
  view.state.draft.value.remarks = ''

  await view.state.submit()

  assert.equal(writes(view)[0].method, 'post')
  const [path, data] = writes(view)[0].args
  assert.equal(path, '/employee-documents/17')
  assert.ok(data instanceof FormData)
  assert.equal(data.get('_method'), 'PUT')
  assert.equal(data.has('file'), false)
  assert.equal(data.has('employee_id'), false)
  assert.equal(data.get('remarks'), '')
  assert.equal(data.get('issued_at'), '2026-01-01')
  assert.equal(data.get('expires_at'), '')
})

test('announcement schedule posts only its action fields and record path', async (context) => {
  const view = await mount(context)
  view.state.selected.value = { id: 31, published_at: null, is_active: true }
  const schedule = resources.announcements.actions.find(({ key }) => key === 'schedule')
  assert.equal(view.state.allowedAction(schedule), true)
  view.state.openForm('action', schedule)
  view.state.draft.value = {
    scheduled_at: '2026-10-10T09:00:00',
    expires_at: '',
    title: 'This action cannot change the title',
    is_active: false,
  }

  await view.state.submit()

  assert.deepEqual(writes(view)[0].args[0], {
    method: 'post',
    url: '/announcements/31/schedule',
    data: { scheduled_at: '2026-10-10T09:00:00', expires_at: null },
  })
  assert.equal(view.messages.at(-1).summary, 'Action completed')
})

test('read-only settings and published announcements disable edit regardless of manager permission', async (context) => {
  const settings = await mount(context, { module: 'system-settings' })
  assert.equal(settings.state.canEdit({ id: 1, is_editable: false }), false)
  assert.equal(settings.state.canEdit({ id: 1, is_editable: true }), true)
  settings.auth.permissions = ['settings.view']
  assert.equal(settings.state.canEdit({ id: 1, is_editable: true }), false)

  const announcements = await mount(context)
  assert.equal(announcements.state.canEdit({ id: 2, published_at: '2000-01-01T00:00:00Z' }), false)
  assert.equal(announcements.state.canEdit({ id: 2, published_at: null }), true)
})

test('non-HR delegation creation defaults to the signed-in delegator and forbids editing another user delegation', async (context) => {
  const view = await mount(context, {
    module: 'approval-delegations',
    permissions: ['approval-delegations.view', 'approval-delegations.manage'],
  })
  view.state.openForm()

  assert.equal(view.state.draft.value.delegator_user_id, 70)
  assert.equal(view.state.canEdit({ delegator_user_id: 70 }), true)
  assert.equal(view.state.canEdit({ delegator_user_id: 71 }), false)
  view.auth.permissions.push('employees.view')
  assert.equal(view.state.canEdit({ delegator_user_id: 71 }), true)
})

test('failed list query clears old rows and reports a connection error with no stale total', async (context) => {
  let fail = false
  const view = await mount(context, {
    http: {
      get: async () => {
        if (fail) throw new Error('offline')
        return response([{ id: 11, title: 'Private HR announcement' }], { total: 200 })
      },
    },
  })
  assert.equal(view.state.total.value, 200)
  fail = true

  await view.state.loadRecords()

  assert.deepEqual(view.state.rows.value, [])
  assert.equal(view.state.total.value, 0)
  assert.match(view.state.error.value, /Unable to reach the server/)
  assert.equal(view.state.loading.value, false)
})

test('unmounting a pending admin save prevents late success messages and refresh requests', async () => {
  const mutation = deferred()
  const view = await mountWorkspace('ResourceWorkspace', {
    module: 'announcements',
    http: { request: () => mutation.promise },
  })
  view.state.openForm()
  view.state.draft.value.title = 'Draft announcement'
  view.state.draft.value.content = 'Employee update'
  const requestCount = view.calls.length

  const pending = view.state.submit()
  view.app.unmount()
  mutation.resolve(response({ id: 101 }))
  await pending

  assert.equal(view.calls.length, requestCount + 1)
  assert.deepEqual(view.messages, [])
})
