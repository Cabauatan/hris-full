import test from 'node:test'
import assert from 'node:assert/strict'
import { recordId, recordInScope, requestTotals, sourceLink } from '../src/utils/workspace.js'
import { calendarPermissions, canViewCalendar } from '../src/config/workspace.js'
import { navigationGroups } from '../src/config/navigation.js'

function auth(permissions = [], employee = null) {
  return {
    authenticated: true,
    employee,
    can: (permission) => permissions.includes(permission),
    canAny: (choices) => choices.some((permission) => permissions.includes(permission)),
  }
}

test('record links accept only positive safe integer identifiers', () => {
  assert.equal(recordId(42), '42')
  assert.equal(recordId('9007199254740991'), '9007199254740991')
  for (const value of [0, -1, 1.5, '01', '1e3', '../2', ['2'], {}, null, '', '9007199254740992'])
    assert.equal(recordId(value), null)
})

test('workflow drilldowns use the authorized module route and explicit record query', () => {
  const employee = auth(['leave.view'], { id: 7 })
  assert.deepEqual(sourceLink({ module: 'leave', id: 81 }, employee, 'mine'), {
    path: '/my/requests/leave',
    query: { record: '81' },
  })
  assert.deepEqual(sourceLink({ module: 'leave-requests', id: 81 }, employee), {
    path: '/requests/leave',
    query: { record: '81' },
  })
  assert.equal(sourceLink({ module: 'cto', id: 81 }, employee), null)
  assert.equal(sourceLink({ module: 'https://external.test', id: 81 }, employee), null)
})

test('calendar schedule and holiday links resolve actual resource and ESS keys', () => {
  const employee = auth(['schedules.view', 'holidays.view'], { id: 7 })
  assert.deepEqual(
    sourceLink({ module: 'employee-schedule-assignments', id: 1 }, employee, 'mine'),
    {
      path: '/my/schedule-assignments',
      query: { record: '1' },
    },
  )
  assert.equal(
    sourceLink({ module: 'employee-schedule-assignments', id: 1 }, employee).path,
    '/manage/employee-schedule-assignments',
  )
  assert.equal(sourceLink({ module: 'holidays', id: 1 }, employee, 'mine').path, '/manage/holidays')
  assert.equal(sourceLink({ module: 'holidays', id: 1 }, auth()), null)
})

test('personal record details remain personal even when normal show permits team access', () => {
  assert.equal(recordInScope({ employee_id: 7 }, true, '7'), true)
  assert.equal(recordInScope({ employee_id: 8 }, true, 7), false)
  assert.equal(recordInScope({ employee_id: 8 }, false, 7), true)
  assert.equal(recordInScope({ employee_id: null }, true, null), false)
})

test('dashboard request totals aggregate full status counts across permitted modules', () => {
  assert.deepEqual(
    requestTotals([
      { total: 12, by_status: { pending: 9, approved: 2, rejected: 1 } },
      { total: 5, by_status: { pending: 1, approved: 1, cancelled: 2, applied: 1 } },
    ]),
    { total: 17, pending: 10, approved: 3, rejected: 1, cancelled: 2, applied: 1 },
  )
  assert.equal(requestTotals([]).total, 0)
})

test('calendar navigation permits active linked employees or authorized module viewers', () => {
  const noAccess = navigationGroups(auth())[0].links
  assert.ok(noAccess.some((item) => item.to === '/dashboard'))
  assert.ok(!noAccess.some((item) => item.to === '/calendar'))
  for (const permission of calendarPermissions)
    assert.ok(navigationGroups(auth([permission]))[0].links.some((item) => item.to === '/calendar'))
  assert.ok(navigationGroups(auth([], { id: 7 }))[0].links.some((item) => item.to === '/calendar'))
  assert.equal(canViewCalendar(auth([], {})), false)
  assert.equal(
    canViewCalendar({ ...auth(['employees.view'], { id: 7 }), authenticated: false }),
    false,
  )
  assert.ok(
    !navigationGroups({ ...auth([], { id: 7 }), authenticated: false })[0].links.some(
      (item) => item.to === '/calendar',
    ),
  )
})
