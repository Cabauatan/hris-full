import test from 'node:test'
import assert from 'node:assert/strict'
import {
  calendarTypes,
  birthdayAgeLabel,
  dateKey,
  addDays,
  addMonths,
  mondayOf,
  calendarRange,
  datesInRange,
  eventOccursOn,
  eventsForDate,
  formatCalendarDate,
  eventTimeLabel,
  eventTitleLabel,
  eventRangeLabel,
  typesQuery,
} from '../src/utils/calendar.js'

test('birthday labels show the age reached on the displayed occurrence without recomputing today age', () => {
  const event = {
    type: 'birthday',
    title: 'Birthday · Test Employee',
    start: '2027-01-02',
    end: '2027-01-03',
    all_day: true,
    details: { age: 31 },
  }
  assert.equal(calendarTypes.birthday.label, 'Birthdays')
  assert.equal(eventTitleLabel(event), 'Birthday · Test Employee · Turns 31')
  assert.equal(birthdayAgeLabel(event), '31 years old')
  assert.equal(eventRangeLabel(event), 'Jan 2, 2027')
  assert.equal(eventOccursOn(event, '2027-01-02'), true)
  assert.equal(eventOccursOn(event, '2027-01-03'), false)
  assert.equal(
    eventTitleLabel({ ...event, details: { age: 1 } }),
    'Birthday · Test Employee · Turns 1',
  )
  assert.equal(birthdayAgeLabel({ ...event, details: { age: 1 } }), '1 year old')
  assert.equal(eventTitleLabel({ ...event, type: 'holiday' }), event.title)
  for (const age of [null, undefined, -1, 31.5, '31']) {
    assert.equal(eventTitleLabel({ ...event, details: { age } }), event.title)
    assert.equal(birthdayAgeLabel({ ...event, details: { age } }), '—')
  }
})

test('calendar dates validate leap days and preserve backend wall dates with offsets', () => {
  assert.equal(dateKey('2028-02-29'), '2028-02-29')
  assert.equal(dateKey('2026-02-29'), '')
  assert.equal(dateKey('2026-13-01'), '')
  assert.equal(dateKey('2026-10-05T00:15:00+08:00'), '2026-10-05')
  assert.equal(dateKey('October 5'), '')
  assert.equal(dateKey(null), '')
})

test('date navigation crosses year boundaries and clamps month ends', () => {
  assert.equal(addDays('2026-12-31', 1), '2027-01-01')
  assert.equal(addDays('2026-01-01', -1), '2025-12-31')
  assert.equal(addMonths('2026-01-31', 1), '2026-02-28')
  assert.equal(addMonths('2028-01-31', 1), '2028-02-29')
  assert.equal(addMonths('2026-01-31', -1), '2025-12-31')
})

test('month grid is exactly 42 days starting Monday even across two years', () => {
  const range = calendarRange('2026-01-15')
  assert.deepEqual(range, { start: '2025-12-29', end: '2026-02-08' })
  assert.equal(datesInRange(range.start, range.end).length, 42)
  assert.equal(mondayOf('2026-01-04'), '2025-12-29')
  assert.equal(mondayOf('2026-01-05'), '2026-01-05')
})

test('week and agenda ranges include whole weeks or the selected calendar month', () => {
  assert.deepEqual(calendarRange('2026-10-04', 'week'), { start: '2026-09-28', end: '2026-10-04' })
  assert.deepEqual(calendarRange('2028-02-20', 'agenda'), {
    start: '2028-02-01',
    end: '2028-02-29',
  })
  assert.equal(datesInRange('2026-10-05', '2026-10-04').length, 0)
  assert.equal(datesInRange('2026-01-01', '2026-03-04').length, 0)
  assert.equal(datesInRange('2026-01-01', '2026-03-03').length, 62)
})

test('all-day event ends are exclusive and multi-day requests appear on each occupied date', () => {
  const event = { start: '2026-10-05', end: '2026-10-08', all_day: true }
  assert.equal(eventOccursOn(event, '2026-10-04'), false)
  assert.equal(eventOccursOn(event, '2026-10-05'), true)
  assert.equal(eventOccursOn(event, '2026-10-07'), true)
  assert.equal(eventOccursOn(event, '2026-10-08'), false)
  assert.equal(eventRangeLabel(event), 'Oct 5, 2026 – Oct 7, 2026')
})

test('date-based half-day leave is labelled by its approved day portion without invented clock times', () => {
  const leave = { type: 'leave', start: '2026-10-05', end: '2026-10-06', all_day: true }
  assert.equal(
    eventTimeLabel({ ...leave, details: { day_portion: 'morning' } }),
    'Morning half day',
  )
  assert.equal(
    eventTimeLabel({ ...leave, details: { day_portion: 'afternoon' } }),
    'Afternoon half day',
  )
  assert.equal(eventTimeLabel({ ...leave, details: { day_portion: 'full_day' } }), 'All day')
  assert.equal(
    eventTimeLabel({ ...leave, type: 'schedule', details: { is_rest_day: true } }),
    'Rest day',
  )
  assert.equal(eventOccursOn(leave, '2026-10-05'), true)
  assert.equal(eventOccursOn(leave, '2026-10-06'), false)
})

test('overnight events cross wall-clock dates without timezone conversion', () => {
  const event = {
    start: '2026-10-05T22:00:00+08:00',
    end: '2026-10-06T06:00:00+08:00',
    all_day: false,
  }
  assert.equal(eventOccursOn(event, '2026-10-05'), true)
  assert.equal(eventOccursOn(event, '2026-10-06'), true)
  assert.equal(eventOccursOn(event, '2026-10-07'), false)
  assert.equal(eventTimeLabel(event, '2026-10-05'), '22:00 →')
  assert.equal(eventTimeLabel(event, '2026-10-06'), '→ 06:00')
})

test('an event ending exactly midnight does not occupy the next day', () => {
  const event = {
    start: '2026-10-05T18:00:00+08:00',
    end: '2026-10-06T00:00:00+08:00',
    all_day: false,
  }
  assert.equal(eventOccursOn(event, '2026-10-05'), true)
  assert.equal(eventOccursOn(event, '2026-10-06'), false)
  assert.equal(eventOccursOn({ ...event, start: '2026-10-06T00:00:00+08:00' }, '2026-10-06'), false)
  assert.equal(eventOccursOn(null, '2026-10-06'), false)
})

test('daily event ordering puts all-day first and preserves event object identities', () => {
  const events = [
    {
      id: 'late',
      title: 'Later',
      start: '2026-10-05T13:00:00+08:00',
      end: '2026-10-05T14:00:00+08:00',
      all_day: false,
    },
    { id: 'holiday', title: 'Holiday', start: '2026-10-05', end: '2026-10-06', all_day: true },
    {
      id: 'early',
      title: 'Earlier',
      start: '2026-10-05T08:00:00+08:00',
      end: '2026-10-05T09:00:00+08:00',
      all_day: false,
    },
  ]
  const result = eventsForDate(events, '2026-10-05')
  assert.deepEqual(
    result.map((event) => event.id),
    ['holiday', 'early', 'late'],
  )
  assert.equal(result[0], events[1])
  assert.equal(events[0].id, 'late')
})

test('empty type filter has an explicit query value instead of omitted Axios array', () => {
  assert.equal(typesQuery([]), '')
  const chosen = ['holiday', 'leave']
  assert.deepEqual(typesQuery(chosen), ['holiday', 'leave'])
  assert.notEqual(typesQuery(chosen), chosen)
})

test('calendar date labels preserve exact app dates independent of process timezone', () => {
  const previous = process.env.TZ
  try {
    process.env.TZ = 'America/Los_Angeles'
    assert.equal(formatCalendarDate('2026-10-05'), 'Oct 5')
    assert.equal(formatCalendarDate('2026-10-05T00:15:00+08:00'), 'Oct 5')
    assert.equal(formatCalendarDate('invalid'), '—')
  } finally {
    if (previous === undefined) delete process.env.TZ
    else process.env.TZ = previous
  }
})
