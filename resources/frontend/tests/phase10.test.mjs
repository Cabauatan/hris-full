import test from 'node:test'
import assert from 'node:assert/strict'
import {
  buildPayload,
  initialValues,
  multipartPayload,
  safeRedirect,
  toLocalInput,
  formatValue,
} from '../src/utils/forms.js'
import { resources } from '../src/config/resources.js'
import { workflows } from '../src/config/workflows.js'
import { navigationGroups } from '../src/config/navigation.js'
import { selfService } from '../src/config/selfService.js'

for (const module of ['cto', 'overtime']) {
  test(`${module} submission sends its editable fields and excludes server-owned values`, () => {
    const payload = buildPayload(workflows[module].fields, {
      start_at: '2026-10-05T08:00:19',
      end_at: '2026-10-05T09:30:41',
      reason: 'Approved work schedule',
      requested_minutes: 9999,
      approved_minutes: 9999,
      employee_id: 42,
      created_by_user_id: 42,
      reviewed_by_user_id: 42,
      status: 'approved',
      abilities: { approve: true },
    })

    assert.deepEqual(payload, {
      start_at: '2026-10-05T08:00:19',
      end_at: '2026-10-05T09:30:41',
      reason: 'Approved work schedule',
    })
  })
}

test('payroll item replacement whitelists nested fields and preserves decimal precision as strings', () => {
  const action = resources['payroll-entries'].actions.find((item) => item.key === 'replace-items')
  const payload = buildPayload(
    action.fields,
    {
      id: 55,
      gross_pay: '123.00',
      items: [
        {
          id: 7,
          payroll_entry_id: 55,
          code: 'BONUS',
          name: 'Performance bonus',
          type: 'earning',
          amount: '9000000000000.99',
          quantity: 2,
          rate: '3.2500',
          remarks: 'Reviewed',
          created_at: '2026-10-04T12:00:00+08:00',
          employee_id: 42,
        },
      ],
    },
    'action',
  )

  assert.deepEqual(payload, {
    items: [
      {
        code: 'BONUS',
        name: 'Performance bonus',
        type: 'earning',
        amount: '9000000000000.99',
        quantity: '2',
        rate: '3.2500',
        remarks: 'Reviewed',
      },
    ],
  })
})

test('payroll item replacement preserves zero amounts and explicit empty-list clearing', () => {
  const fields = resources['payroll-entries'].actions.find(
    (item) => item.key === 'replace-items',
  ).fields
  const draft = {
    items: [
      {
        code: 'ZERO',
        name: 'Zero line',
        type: 'deduction',
        amount: 0,
        quantity: null,
        rate: null,
        remarks: null,
      },
    ],
  }

  assert.deepEqual(buildPayload(fields, draft, 'action'), {
    items: [
      {
        code: 'ZERO',
        name: 'Zero line',
        type: 'deduction',
        amount: '0',
        quantity: null,
        rate: null,
        remarks: null,
      },
    ],
  })
  assert.deepEqual(buildPayload(fields, { items: [] }, 'action'), { items: [] })
  assert.deepEqual(buildPayload(fields, {}, 'action'), {})
})

for (const module of [
  'employee-employment-histories',
  'employee-compensations',
  'employee-documents',
]) {
  test(`${module} update excludes immutable employee ownership`, () => {
    const fields = resources[module].fields
    const record = { employee_id: 12, remarks: 'Retain record owner', title: 'Certificate' }
    const draft = initialValues(fields, record, 'edit')

    assert.equal(Object.hasOwn(draft, 'employee_id'), false)
    const payload = buildPayload(fields, { ...draft, employee_id: 99 }, 'edit')
    assert.equal(Object.hasOwn(payload, 'employee_id'), false)
    assert.equal(buildPayload(fields, { employee_id: '12' }, 'create').employee_id, 12)
  })
}

const settingCases = [
  ['boolean false', 'boolean', false, false],
  ['boolean true', 'boolean', true, true],
  ['integer zero', 'integer', 0, '0'],
  ['integer numeric input', 'integer', '12', '12'],
  ['integer beyond JavaScript precision', 'integer', '9007199254740993', '9007199254740993'],
  [
    'negative integer beyond JavaScript precision',
    'integer',
    '-9007199254740993',
    '-9007199254740993',
  ],
  ['decimal zero', 'decimal', 0, '0'],
  ['decimal precision', 'decimal', '9999999999999.99', '9999999999999.99'],
  ['empty string', 'string', '', ''],
  ['string zero', 'string', '0', '0'],
  ['explicit null', 'boolean', null, null],
  ['JSON null', 'json', null, null],
  ['JSON false', 'json', 'false', false],
  ['JSON zero', 'json', '0', 0],
  ['JSON object', 'json', '{"enabled":false,"threshold":0}', { enabled: false, threshold: 0 }],
]

for (const [name, type, value, expected] of settingCases) {
  test(`system setting update retains ${name} without changing its declared type`, () => {
    const payload = buildPayload(
      resources['system-settings'].fields,
      {
        type,
        value,
        key: 'spoofed.key',
        group: 'spoofed.group',
        is_public: true,
        is_editable: true,
      },
      'edit',
    )

    assert.deepEqual(payload, { value: expected })
  })
}

test('system settings and general JSON fields reject malformed JSON before submission', () => {
  assert.throws(
    () =>
      buildPayload(resources['system-settings'].fields, { type: 'json', value: '{broken' }, 'edit'),
    /Value must contain valid JSON/,
  )
  assert.throws(
    () =>
      buildPayload([{ key: 'metadata', label: 'Metadata', type: 'json' }], { metadata: '{broken' }),
    /Metadata must contain valid JSON/,
  )
})

test('integer system settings reject already-rounded numbers and invalid integer text', () => {
  const fields = resources['system-settings'].fields

  for (const value of [9007199254740993, -9007199254740993, '1.5', '1e3', 'Infinity']) {
    assert.throws(
      () => buildPayload(fields, { type: 'integer', value }, 'edit'),
      /whole number without lost precision/,
    )
  }
})

test('JSON initial values round-trip objects, false and zero', () => {
  const fields = [{ key: 'metadata', label: 'Metadata', type: 'json' }]

  for (const value of [{ enabled: false, count: 0 }, [0, false], false, 0]) {
    const draft = initialValues(fields, { metadata: value })
    assert.deepEqual(buildPayload(fields, draft), { metadata: value })
  }
})

test('document edit uses multipart PUT override and preserves the chosen file', () => {
  const file = new File(['certificate'], 'certificate.txt', { type: 'text/plain' })
  const payload = buildPayload(
    resources['employee-documents'].fields,
    {
      employee_id: 99,
      title: 'Updated certificate',
      document_type: 'employment',
      file,
      expires_at: '',
      is_confidential: false,
    },
    'edit',
  )
  const form = multipartPayload(payload, 'edit')

  assert.equal(form.get('_method'), 'PUT')
  assert.equal(form.get('title'), 'Updated certificate')
  assert.equal(form.has('is_confidential'), false)
  assert.equal(form.get('expires_at'), '')
  assert.equal(form.has('employee_id'), false)
  assert.equal(form.get('file').name, 'certificate.txt')
  assert.equal(form.get('file').size, file.size)
  assert.equal(multipartPayload({ title: 'New certificate' }).has('_method'), false)
  assert.equal(multipartPayload({ is_active: false }).get('is_active'), '0')
})

test('document edit with no replacement file omits the file field', () => {
  const fields = resources['employee-documents'].fields
  const draft = initialValues(
    fields,
    { title: 'Certificate', original_filename: 'original.pdf' },
    'edit',
  )

  assert.equal(draft.file, null)
  assert.equal(Object.hasOwn(buildPayload(fields, draft, 'edit'), 'file'), false)
})

function authWith(permissions = [], employee = null) {
  const allowed = new Set(permissions)
  return {
    employee,
    can: (permission) => allowed.has(permission),
    canAny: (values = []) =>
      values.length === 0 || values.some((permission) => allowed.has(permission)),
  }
}

function linksFor(auth) {
  return navigationGroups(auth).flatMap((group) => group.links.map((link) => link.to))
}

test('navigation exposes self-service only when an employee profile exists', () => {
  const permissions = [
    'cto.view',
    'cto.approve',
    'attendance.view',
    'payslips.view',
    'schedules.view',
  ]
  const noEmployee = linksFor(authWith(permissions))
  const linkedEmployee = linksFor(authWith(permissions, { id: 12 }))

  assert.equal(
    noEmployee.some((path) => path.startsWith('/my/') || path === '/profile'),
    false,
  )
  assert.equal(noEmployee.includes('/requests/cto'), true)
  assert.equal(linkedEmployee.includes('/profile'), true)
  assert.equal(linkedEmployee.includes('/my/requests/cto'), true)
  assert.equal(linkedEmployee.includes('/my/daily-attendances'), true)
  assert.equal(linkedEmployee.includes('/my/payslips'), true)
})

test('navigation requires module permissions and does not equate ownership with review access', () => {
  const employee = { id: 12 }
  const ownOnly = linksFor(authWith(['cto.view'], employee))
  const approveWithoutView = linksFor(authWith(['cto.approve'], employee))
  const restricted = linksFor(authWith([], employee))

  assert.equal(ownOnly.includes('/my/requests/cto'), true)
  assert.equal(ownOnly.includes('/requests/cto'), false)
  assert.equal(approveWithoutView.includes('/requests/cto'), false)
  assert.equal(
    restricted.some((path) => path.startsWith('/manage/')),
    false,
  )
  assert.equal(restricted.includes('/my/payslips'), false)
  assert.equal(linksFor(authWith(['organization.view'])).includes('/manage/divisions'), true)
})

test('self-service resource contracts retain ESS endpoints and remove administrative write actions', () => {
  for (const entry of Object.values(selfService)) {
    assert.equal(entry.ess, true)
    assert.equal(entry.endpoint.startsWith('/ess/'), true)
    assert.equal(entry.createPermission, undefined)
    assert.equal(entry.updatePermission, undefined)
    assert.equal(
      entry.actions.every((action) => action.download || action.key === 'mark-viewed'),
      true,
    )
  }
  assert.equal(selfService.payslips.endpoint, '/ess/payslips')
  assert.equal(
    selfService.payslips.actions.some((action) => action.key === 'publish'),
    false,
  )
})

const unsafeRedirects = [
  ['absolute URL', 'https://evil.test'],
  ['protocol-relative URL', '//evil.test'],
  ['backslash', '/\\evil.test'],
  ['control character', '/hidden\u0000path'],
  ['login loop', '/auth/login'],
  ['encoded protocol-relative URL', '/%2f%2fevil.test'],
  ['encoded backslash', '/%5cevil.test'],
  ['encoded control character', '/%00hidden'],
  ['encoded authentication path', '/%61uth/login'],
  ['nested encoding', '/%252f%252fevil.test'],
  ['malformed encoding', '/%E0%A4%A'],
  ['non-string query', ['/home']],
]

for (const [name, redirect] of unsafeRedirects) {
  test(`safeRedirect refuses ${name}`, () => {
    assert.equal(safeRedirect(redirect), '/home')
    assert.equal(safeRedirect(redirect, '/profile'), '/profile')
  })
}

test('safeRedirect retains valid internal destinations and query strings', () => {
  assert.equal(
    safeRedirect('/my/requests/cto?status=pending#details'),
    '/my/requests/cto?status=pending#details',
  )
  assert.equal(
    safeRedirect('/manage/employees?search=Ana%20Cruz'),
    '/manage/employees?search=Ana%20Cruz',
  )
  assert.equal(safeRedirect('/home'), '/home')
})

test('datetime form values preserve wall-clock time and seconds without timezone conversion', () => {
  const fields = [{ key: 'start_at', label: 'Start', type: 'datetime' }]
  const draft = initialValues(fields, { start_at: '2026-10-05T08:09:37+08:00' })

  assert.equal(draft.start_at, '2026-10-05T08:09:37')
  assert.deepEqual(buildPayload(fields, draft), { start_at: '2026-10-05T08:09:37' })
  assert.equal(toLocalInput('2026-10-05 08:09:37'), '2026-10-05T08:09:37')
  assert.equal(toLocalInput('2026-10-05T08:09:37.123Z'), '2026-10-05T08:09:37')
  assert.equal(toLocalInput(null), '')
  assert.equal(formatValue('2026-10-05T08:09:37+08:00', 'datetime'), '2026-10-05 08:09:37')
})
