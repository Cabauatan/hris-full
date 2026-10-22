import test from 'node:test'
import assert from 'node:assert/strict'
import {
  deferred,
  httpFailure,
  mountWorkspace,
  response,
  settle,
} from './helpers/component-harness.mjs'

async function mount(context, options = {}) {
  const view = await mountWorkspace('WorkflowWorkspace', options)
  context.after(() => view.app.unmount())
  return view
}

function durationDraft(overrides = {}) {
  return {
    start_at: '2026-10-05T08:00:00',
    end_at: '2026-10-05T09:30:45',
    reason: 'Scheduled personal appointment',
    ...overrides,
  }
}

function submitted(view) {
  return view.calls.filter(({ method }) => method === 'post')
}

for (const module of ['cto', 'overtime']) {
  test(`${module} submission sends clock range and omits client-controlled duration and identity`, async (context) => {
    const view = await mount(context, { module, ess: true })
    await view.state.openCreate()
    view.state.createDraft.value = durationDraft({
      requested_minutes: 999999,
      approved_minutes: 999999,
      employee_id: 900,
      status: 'approved',
    })

    await view.state.submitCreate()

    assert.deepEqual(submitted(view), [
      { method: 'post', args: [`/${module}-requests`, durationDraft()] },
    ])
    assert.equal(view.state.createVisible.value, false)
    assert.match(view.state.success.value, /submitted for review/)
    assert.equal(view.calls.at(-1).args[0], `/ess/${module}-requests`)
  })
}

test('duration validation blocks reversed ranges and sub-minute requests without posting', async (context) => {
  const view = await mount(context)
  await view.state.openCreate()
  view.state.createDraft.value = durationDraft({ end_at: '2026-10-05T07:59:00' })

  await view.state.submitCreate()

  assert.equal(submitted(view).length, 0)
  assert.match(view.state.createErrors.value.end_at[0], /complete minute/)
  view.state.createDraft.value.end_at = '2026-10-05T08:00:59'
  await view.state.submitCreate()
  assert.equal(submitted(view).length, 0)
  assert.match(view.state.createErrors.value.end_at[0], /complete minute/)
})

test('missing employee profile or create permission prevents opening and posting a request', async (context) => {
  for (const options of [{ employee: null }, { permissions: ['cto.view'] }]) {
    const view = await mount(context, options)
    await view.state.openCreate()
    view.state.createDraft.value = durationDraft()

    await view.state.submitCreate()

    assert.equal(view.state.canCreate.value, false)
    assert.equal(view.state.createVisible.value, false)
    assert.equal(submitted(view).length, 0)
  }
})

test('failed request submission preserves draft and server field messages for an explicit retry', async (context) => {
  let fail = true
  const view = await mount(context, {
    http: {
      post: async () => {
        if (fail)
          throw httpFailure(422, 'The given data was invalid.', {
            end_at: ['The requested period overlaps another approved request.'],
          })
        return response({ id: 101 })
      },
    },
  })
  await view.state.openCreate()
  view.state.createDraft.value = durationDraft()

  await view.state.submitCreate()

  assert.equal(view.state.createVisible.value, true)
  assert.equal(view.state.createBusy.value, false)
  assert.deepEqual(view.state.createDraft.value, durationDraft())
  assert.deepEqual(view.state.createErrors.value, {
    end_at: ['The requested period overlaps another approved request.'],
  })
  assert.equal(view.state.success.value, '')
  fail = false
  await view.state.submitCreate()
  assert.equal(submitted(view).length, 2)
  assert.equal(view.state.createVisible.value, false)
  assert.deepEqual(view.state.createErrors.value, {})
})

test('request submission is single-flight until the pending response completes', async (context) => {
  const transfer = deferred()
  const view = await mount(context, { http: { post: () => transfer.promise } })
  await view.state.openCreate()
  view.state.createDraft.value = durationDraft()

  const first = view.state.submitCreate()
  await view.state.submitCreate()

  assert.equal(view.state.createBusy.value, true)
  assert.equal(submitted(view).length, 1)
  transfer.resolve(response({ id: 101 }))
  await first
  assert.equal(view.state.createBusy.value, false)
})

function leaveType(overrides = {}) {
  return {
    id: 4,
    code: 'VL',
    name: 'Vacation leave',
    is_active: true,
    requires_balance: true,
    requires_document: false,
    allows_partial_day: true,
    ...overrides,
  }
}

function leaveDraft(overrides = {}) {
  return {
    leave_type_id: 4,
    start_date: '2026-10-05',
    end_date: '2026-10-05',
    requested_units: '0.50',
    day_portion: 'morning',
    reason: 'Personal appointment',
    document_path: '',
    ...overrides,
  }
}

test('leave type selector loads all active pages and keeps half-day credit as decimal text', async (context) => {
  const view = await mount(context, {
    module: 'leave',
    http: {
      get: async (path, { params } = {}) => {
        if (path === '/leave-types')
          return response(
            params.page === 1
              ? [leaveType(), leaveType({ id: 5, is_active: false })]
              : [leaveType({ id: 6 })],
            { last_page: 2 },
          )
        return response([], { total: 0 })
      },
    },
  })
  await view.state.openCreate()
  view.state.createDraft.value = leaveDraft()
  await settle()

  await view.state.submitCreate()

  assert.deepEqual(
    view.state.leaveTypes.value.map(({ id }) => id),
    [4, 6],
  )
  assert.deepEqual(
    view.calls.filter(({ args }) => args[0] === '/leave-types').map(({ args }) => args[1].params),
    [
      { page: 1, per_page: 100, is_active: 1 },
      { page: 2, per_page: 100, is_active: 1 },
    ],
  )
  assert.deepEqual(submitted(view)[0].args, [
    '/leave-requests',
    leaveDraft({ document_path: null }),
  ])
})

test('leave enforces balance calendar year and supporting-document requirements before posting', async (context) => {
  const view = await mount(context, {
    module: 'leave',
    http: {
      get: async (path) =>
        path === '/leave-types'
          ? response([leaveType({ requires_document: true })], { last_page: 1 })
          : response([], { total: 0 }),
    },
  })
  await view.state.openCreate()
  view.state.createDraft.value = leaveDraft({ start_date: '2026-12-31', end_date: '2027-01-01' })
  await settle()

  await view.state.submitCreate()

  assert.equal(submitted(view).length, 0)
  assert.match(view.state.createErrors.value.end_date[0], /one calendar year/)
  assert.match(view.state.createErrors.value.document_path[0], /supporting document/)
})

test('selecting a full-day-only leave type resets an earlier half-day selection', async (context) => {
  const view = await mount(context, {
    module: 'leave',
    http: {
      get: async (path) =>
        path === '/leave-types'
          ? response([leaveType(), leaveType({ id: 6, allows_partial_day: false })], {
              last_page: 1,
            })
          : response([], { total: 0 }),
    },
  })
  await view.state.openCreate()
  view.state.createDraft.value = leaveDraft()
  await settle()

  view.state.createDraft.value.leave_type_id = 6
  await settle()

  assert.equal(view.state.createDraft.value.day_portion, 'full_day')
  assert.deepEqual(view.state.createFields.value.find(({ key }) => key === 'day_portion').options, [
    { label: 'Full day', value: 'full_day' },
  ])
})

test('attendance correction requires at least one corrected time and preserves valid overnight clocks', async (context) => {
  const view = await mount(context, { module: 'attendance-corrections' })
  await view.state.openCreate()
  view.state.createDraft.value = {
    attendance_date: '2026-10-05',
    reason: 'Missing overnight punch',
  }

  await view.state.submitCreate()

  assert.equal(submitted(view).length, 0)
  assert.match(view.state.createErrors.value.requested_time_in[0], /time in or time out/)
  view.state.createDraft.value.requested_time_in = '2026-10-05T22:00:00'
  view.state.createDraft.value.requested_time_out = '2026-10-06T06:00:00'
  await view.state.submitCreate()
  assert.deepEqual(submitted(view)[0].args, [
    '/attendance-correction-requests',
    {
      attendance_date: '2026-10-05',
      reason: 'Missing overnight punch',
      requested_time_in: '2026-10-05T22:00:00',
      requested_time_out: '2026-10-06T06:00:00',
    },
  ])
})

function profile() {
  return {
    first_name: 'Jamie',
    middle_name: 'Jay',
    last_name: 'Reyes',
    country: 'Philippines',
    personal_email: 'jamie@example.test',
    employee_number: 'EMP-7',
  }
}

async function mountProfile(context, http = {}) {
  return mount(context, {
    module: 'profile-changes',
    http: {
      get: async (path) =>
        path === '/ess/profile' ? response(profile()) : response([], { total: 0 }),
      ...http,
    },
  })
}

test('profile change sends only changed personal fields with normalized null clearing', async (context) => {
  const view = await mountProfile(context)
  await view.state.openCreate()
  Object.assign(view.state.createDraft.value, {
    first_name: ' Jamie ',
    middle_name: '',
    personal_email: ' new@example.test ',
    employee_number: 'HACKED',
    status: 'applied',
    reason: 'Updated contact information',
  })

  await view.state.submitCreate()

  assert.deepEqual(submitted(view)[0].args, [
    '/employee-profile-change-requests',
    {
      changes: { middle_name: null, personal_email: 'new@example.test' },
      reason: 'Updated contact information',
      document_path: null,
    },
  ])
})

test('unchanged normalized personal profile cannot create an empty change request', async (context) => {
  const view = await mountProfile(context)
  await view.state.openCreate()
  view.state.createDraft.value.first_name = ' Jamie '
  view.state.createDraft.value.reason = 'Only changing the reason'

  await view.state.submitCreate()

  assert.equal(submitted(view).length, 0)
  assert.match(view.state.createError.value, /Change at least one personal information field/)
  assert.equal(view.state.createVisible.value, true)
})

test('profile server errors map changes fields back to editable personal inputs', async (context) => {
  const view = await mountProfile(context, {
    post: async () => {
      throw httpFailure(422, 'The given data was invalid.', {
        'changes.personal_email': ['The personal email must be a valid email address.'],
        reason: ['The reason must be a string.'],
      })
    },
  })
  await view.state.openCreate()
  view.state.createDraft.value.personal_email = 'bad-address'

  await view.state.submitCreate()

  assert.deepEqual(view.state.createErrors.value, {
    personal_email: ['The personal email must be a valid email address.'],
    reason: ['The reason must be a string.'],
  })
  assert.equal(view.state.createDraft.value.personal_email, 'bad-address')
  assert.equal(view.state.createVisible.value, true)
})

function pendingRequest(overrides = {}) {
  return {
    id: 101,
    employee_id: 7,
    status: 'pending',
    requested_minutes: 90,
    abilities: { approve: true, reject: true, cancel: true },
    ...overrides,
  }
}

async function mountReview(context, options = {}) {
  return mount(context, {
    record: 101,
    ...options,
    http: {
      get: async (path) =>
        path.endsWith('/101') ? response(pendingRequest()) : response([], { total: 0 }),
      ...options.http,
    },
  })
}

test('CTO approval rejects fractional or excessive minutes and sends only valid approved amount and remarks', async (context) => {
  const view = await mountReview(context)
  view.state.openAction('approve')
  assert.equal(view.state.actionDraft.value.approved_minutes, 90)
  view.state.actionDraft.value.approved_minutes = 60.5

  await view.state.submitAction()

  assert.equal(submitted(view).length, 0)
  assert.match(view.state.actionErrors.value.approved_minutes[0], /whole number/)
  view.state.actionDraft.value.approved_minutes = 91
  await view.state.submitAction()
  assert.equal(submitted(view).length, 0)
  assert.match(view.state.actionErrors.value.approved_minutes[0], /between 1 and 90/)
  view.state.actionDraft.value = {
    approved_minutes: 60,
    remarks: 'Partial approval',
    status: 'approved',
  }
  await view.state.submitAction()
  assert.deepEqual(submitted(view)[0].args, [
    '/cto-requests/101/approve',
    { approved_minutes: 60, remarks: 'Partial approval' },
  ])
  assert.equal(view.state.actionVisible.value, false)
})

test('reject and cancel retain distinct optional remarks and reason payloads', async (context) => {
  const view = await mountReview(context)
  view.state.openAction('reject')
  view.state.actionDraft.value = {
    remarks: 'Coverage unavailable',
    reason: 'Not a rejection field',
  }

  await view.state.submitAction()

  assert.deepEqual(submitted(view)[0].args, [
    '/cto-requests/101/reject',
    { remarks: 'Coverage unavailable' },
  ])
  view.state.openAction('cancel')
  view.state.actionDraft.value = {
    reason: 'Appointment moved',
    remarks: 'Not a cancellation field',
  }
  await view.state.submitAction()
  assert.deepEqual(submitted(view)[1].args, [
    '/cto-requests/101/cancel',
    { reason: 'Appointment moved' },
  ])
})

test('review conflict refreshes authoritative detail and removes stale available actions', async (context) => {
  let current = pendingRequest()
  const view = await mountReview(context, {
    http: {
      get: async (path) => (path.endsWith('/101') ? response(current) : response([], { total: 0 })),
      post: async () => {
        current = pendingRequest({
          status: 'approved',
          abilities: { approve: false, reject: false, cancel: false },
        })
        throw httpFailure(409, 'This request has already been reviewed.')
      },
    },
  })
  view.state.openAction('approve')

  await view.state.submitAction()

  assert.equal(view.state.detail.value.status, 'approved')
  assert.deepEqual(view.state.allowedActions.value, [])
  assert.equal(view.state.actionBusy.value, false)
  assert.equal(view.state.actionError.value, 'This request has already been reviewed.')
  await view.state.submitAction()
  assert.equal(submitted(view).length, 1)
})

test('pending request actions follow server abilities and applied profile has no remaining actions', async (context) => {
  const view = await mount(context, { module: 'profile-changes' })
  view.state.detail.value = pendingRequest({
    abilities: { approve: false, reject: false, cancel: true },
  })
  view.state.openAction('approve')
  assert.equal(view.state.actionVisible.value, false)
  assert.deepEqual(view.state.allowedActions.value, ['cancel'])
  view.state.detail.value = pendingRequest({ status: 'approved', abilities: { apply: true } })
  assert.deepEqual(view.state.allowedActions.value, ['apply'])
  view.state.openAction('apply')

  await view.state.submitAction()

  assert.deepEqual(submitted(view)[0].args, ['/employee-profile-change-requests/101/apply', {}])
  view.state.detail.value = pendingRequest({ status: 'applied', abilities: { apply: true } })
  assert.deepEqual(view.state.allowedActions.value, [])
})
