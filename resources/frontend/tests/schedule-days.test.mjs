import test from 'node:test'
import assert from 'node:assert/strict'
import { scheduleDayDetails } from '../src/utils/schedules.js'
import { mountWorkspace, response } from './helpers/component-harness.mjs'

function scheduleDay(overrides = {}) {
  return {
    id: 41,
    work_schedule_id: 12,
    day_of_week: 1,
    is_working_day: true,
    shift_id: 9,
    work_schedule: { id: 12, code: 'REGULAR', name: 'Regular schedule' },
    shift: {
      id: 9,
      code: 'DAY',
      name: 'Day shift',
      start_time: '08:00:00',
      end_time: '17:00:00',
      is_overnight: false,
    },
    ...overrides,
  }
}

test('schedule day details use schedule and shift names, ISO weekdays and readable hours', () => {
  const record = scheduleDay()
  const details = scheduleDayDetails(record)
  assert.equal(details.work_schedule, 'Regular schedule (REGULAR)')
  assert.equal(details.shift, 'Day shift (DAY)')
  assert.equal(details.shift_hours, '8:00 AM – 5:00 PM')
  assert.equal(details.day_type, 'Working day')
  for (const [index, weekday] of [
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
    'Sunday',
  ].entries()) {
    assert.equal(scheduleDayDetails({ ...record, day_of_week: index + 1 }).weekday, weekday)
  }
  assert.equal(
    Object.keys(details).some((key) => key === 'id' || key.endsWith('_id')),
    false,
  )
  assert.equal(record.work_schedule_id, 12)
})

test('rest day details do not suggest work hours even if a stale shift is supplied', () => {
  const details = scheduleDayDetails(scheduleDay({ is_working_day: false, day_of_week: 7 }))
  assert.equal(details.weekday, 'Sunday')
  assert.equal(details.day_type, 'Rest day')
  assert.equal(details.shift, 'No shift (rest day)')
  assert.equal(details.shift_hours, 'Not applicable')
})

test('overnight shifts show the next day and missing relations never fall back to numeric IDs', () => {
  const record = scheduleDay()
  record.shift = { ...record.shift, start_time: '22:00', end_time: '06:00', is_overnight: true }
  assert.equal(scheduleDayDetails(record).shift_hours, '10:00 PM – 6:00 AM (next day)')
  const missing = scheduleDayDetails(
    scheduleDay({ work_schedule: null, shift: null, day_of_week: null }),
  )
  assert.equal(missing.work_schedule, 'Schedule unavailable')
  assert.equal(missing.shift, 'Not assigned')
  assert.equal(missing.weekday, '—')
  assert.equal(missing.shift_hours, '—')
})

test('schedule workspace searches readable labels and edits the original IDs without sending display fields', async (context) => {
  const record = scheduleDay()
  const view = await mountWorkspace('ResourceWorkspace', {
    module: 'work-schedule-days',
    http: { get: async (path) => response(path.endsWith('/41') ? record : [record], { total: 1 }) },
  })
  context.after(() => view.app.unmount())

  for (const search of ['Monday', 'Regular schedule', 'Day shift', '8:00 AM']) {
    view.state.search.value = search
    assert.equal(view.state.visibleRows.value.length, 1)
    assert.equal(view.state.visibleRows.value[0].id, 41)
  }
  view.state.search.value = 'Sunday'
  assert.equal(view.state.visibleRows.value.length, 0)
  await view.state.openRecord({ id: 41 })
  assert.equal(view.state.detailRecord.value.weekday, 'Monday')
  assert.equal(view.state.detailRecord.value.id, undefined)
  assert.equal(view.state.detailRecord.value.shift_id, undefined)
  view.state.openForm('edit')
  assert.equal(view.state.draft.value.work_schedule_id, 12)
  assert.equal(view.state.draft.value.shift_id, 9)
  assert.equal(view.state.draft.value.day_of_week, 1)
  await view.state.submit()
  const write = view.calls.find((call) => call.method === 'request')
  assert.deepEqual(write.args[0], {
    method: 'put',
    url: '/work-schedule-days/41',
    data: { work_schedule_id: 12, day_of_week: 1, is_working_day: true, shift_id: 9 },
  })
})

test('other administration modules retain their original detail records', async (context) => {
  const record = { id: 4, name: 'Regular schedule', code: 'REGULAR', is_active: true }
  const view = await mountWorkspace('ResourceWorkspace', {
    module: 'work-schedules',
    http: { get: async (path) => response(path.endsWith('/4') ? record : [record]) },
  })
  context.after(() => view.app.unmount())
  await view.state.openRecord(record)
  assert.deepEqual(view.state.detailRecord.value, record)
  assert.deepEqual(view.state.visibleRows.value, [record])
})
