import test from 'node:test'
import assert from 'node:assert/strict'
import { reportDraft, reportFilters } from '../src/utils/reports.js'
import { reportPermissions } from '../src/config/reports.js'
import { navigationGroups } from '../src/config/navigation.js'
import { formatValue } from '../src/utils/forms.js'

test('report navigation follows each existing domain permission and excludes unrelated access', () => {
  const auth = (permissions) => ({
    employee: null,
    can: (permission) => permissions.includes(permission),
    canAny: (choices) => choices.some((permission) => permissions.includes(permission)),
  })

  for (const permission of reportPermissions)
    assert.ok(navigationGroups(auth([permission]))[0].links.some((link) => link.to === '/reports'))
  assert.ok(
    !navigationGroups(auth(['announcements.view']))[0].links.some((link) => link.to === '/reports'),
  )
})

test('report queries whitelist descriptor filters and exclude page state and private arbitrary fields', () => {
  const values = {
    scope: 'mine',
    employee_id: 7,
    start: '2026-10-01',
    end: '2026-10-31',
    status: 'pending',
    year: 2026,
    payroll_run_id: 9,
    page: 2,
    per_page: 100,
    reason: 'Private note',
    sort: 'password',
    user_id: 1,
  }

  assert.deepEqual(
    reportFilters({ key: 'leave', date_basis: 'start_date', statuses: ['pending'] }, values),
    {
      scope: 'mine',
      employee_id: '7',
      start: '2026-10-01',
      end: '2026-10-31',
      status: 'pending',
    },
  )
  assert.deepEqual(
    reportFilters({ key: 'payroll', date_basis: 'period', statuses: ['draft'] }, values),
    {
      scope: 'mine',
      employee_id: '7',
      start: '2026-10-01',
      end: '2026-10-31',
      status: 'pending',
      payroll_run_id: '9',
    },
  )
})

test('balance snapshots carry year only where supported and reset dated report drafts', () => {
  const defaults = { scope: 'visible', start: '2026-10-01', end: '2026-10-31', year: 2026 }

  assert.deepEqual(reportFilters({ key: 'leave-balances', year_filter: true }, defaults), {
    scope: 'visible',
    employee_id: null,
    year: '2026',
  })
  assert.deepEqual(reportFilters({ key: 'cto-balances' }, defaults), {
    scope: 'visible',
    employee_id: null,
  })
  assert.deepEqual(reportDraft({ key: 'leave-balances', year_filter: true }, defaults), {
    scope: 'visible',
    employee_id: '',
    start: '',
    end: '',
    status: '',
    year: 2026,
    payroll_run_id: '',
  })
})

test('report financial values retain decimal strings without rounding through JavaScript numbers', () => {
  assert.equal(formatValue('9000000000000000.99', 'decimal'), '9000000000000000.99')
  assert.equal(formatValue('0.00', 'decimal'), '0.00')
  assert.equal(formatValue(null, 'decimal'), '—')
})
