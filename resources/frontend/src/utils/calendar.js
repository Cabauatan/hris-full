export const calendarTypes = {
  birthday: { label: 'Birthdays', singular: 'Birthday', icon: 'pi pi-gift', tone: 'rose' },
  holiday: { label: 'Holidays', singular: 'Holiday', icon: 'pi pi-sun', tone: 'amber' },
  schedule: { label: 'Schedules', singular: 'Schedule', icon: 'pi pi-clock', tone: 'slate' },
  leave: { label: 'Leave', singular: 'Leave', icon: 'pi pi-calendar-minus', tone: 'emerald' },
  cto: { label: 'CTO', singular: 'CTO', icon: 'pi pi-stopwatch', tone: 'teal' },
  overtime: { label: 'Overtime', singular: 'Overtime', icon: 'pi pi-history', tone: 'violet' },
  official_business: {
    label: 'Official business',
    singular: 'Official business',
    icon: 'pi pi-briefcase',
    tone: 'blue',
  },
  travel_order: {
    label: 'Travel orders',
    singular: 'Travel order',
    icon: 'pi pi-map-marker',
    tone: 'rose',
  },
}

export function eventTitleLabel(event) {
  const age = event?.details?.age
  return event?.type === 'birthday' && Number.isInteger(age) && age >= 0
    ? `${event.title} · Turns ${age}`
    : event?.title || ''
}

export function birthdayAgeLabel(event) {
  const age = event?.details?.age
  return Number.isInteger(age) && age >= 0 ? `${age} ${age === 1 ? 'year' : 'years'} old` : '—'
}

export function dateKey(value) {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}(?:$|T| )/.test(value)) return ''
  const key = value.slice(0, 10)
  const [year, month, day] = key.split('-').map(Number)
  const date = new Date(0)
  date.setUTCFullYear(year, month - 1, day)
  date.setUTCHours(0, 0, 0, 0)
  return date.toISOString().slice(0, 10) === key ? key : ''
}

function utcDate(value) {
  const key = dateKey(value)
  if (!key) throw new Error('Choose a valid calendar date.')
  return new Date(`${key}T00:00:00Z`)
}

export function addDays(value, amount) {
  const date = utcDate(value)
  date.setUTCDate(date.getUTCDate() + amount)
  return date.toISOString().slice(0, 10)
}

export function addMonths(value, amount) {
  const date = utcDate(value)
  const day = date.getUTCDate()
  date.setUTCDate(1)
  date.setUTCMonth(date.getUTCMonth() + amount)
  const lastDay = new Date(date.getTime())
  lastDay.setUTCMonth(lastDay.getUTCMonth() + 1, 0)
  date.setUTCDate(Math.min(day, lastDay.getUTCDate()))
  return date.toISOString().slice(0, 10)
}

export function mondayOf(value) {
  const date = utcDate(value)
  return addDays(value, -((date.getUTCDay() + 6) % 7))
}

export function calendarRange(value, view = 'month') {
  const key = dateKey(value)
  if (view === 'week') {
    const start = mondayOf(key)
    return { start, end: addDays(start, 6) }
  }
  if (view === 'agenda') {
    const start = `${key.slice(0, 7)}-01`
    return { start, end: addDays(addMonths(start, 1), -1) }
  }
  const start = mondayOf(`${key.slice(0, 7)}-01`)
  return { start, end: addDays(start, 41) }
}

export function datesInRange(start, end, limit = 62) {
  if (!dateKey(start) || !dateKey(end) || end < start) return []
  const count = Math.round((utcDate(end) - utcDate(start)) / 86400000) + 1
  if (count > limit) return []
  return Array.from({ length: count }, (_, index) => addDays(start, index))
}

export function eventOccursOn(event, day) {
  const start = dateKey(event?.start)
  const end = dateKey(event?.end)
  if (!dateKey(day) || !start || !end) return false
  if (event.all_day) return start <= day && end > day
  const startTime = String(event.start).replace(' ', 'T').slice(0, 19)
  const endTime = String(event.end).replace(' ', 'T').slice(0, 19)
  return startTime < `${addDays(day, 1)}T00:00:00` && endTime > `${day}T00:00:00`
}

export function eventsForDate(events, day) {
  return events
    .filter((event) => eventOccursOn(event, day))
    .sort((a, b) => {
      if (a.all_day !== b.all_day) return a.all_day ? -1 : 1
      return (
        String(a.start).localeCompare(String(b.start)) ||
        String(a.title).localeCompare(String(b.title))
      )
    })
}

export function formatCalendarDate(value, options = {}) {
  if (!dateKey(value)) return '—'
  return new Intl.DateTimeFormat('en-PH', {
    month: 'short',
    day: 'numeric',
    ...options,
    timeZone: 'UTC',
  }).format(utcDate(value))
}

export function eventTimeLabel(event, day = dateKey(event?.start)) {
  if (event?.all_day) {
    if (event?.details?.is_rest_day) return 'Rest day'
    if (event.type === 'leave') {
      if (event.details?.day_portion === 'morning') return 'Morning half day'
      if (event.details?.day_portion === 'afternoon') return 'Afternoon half day'
      if (event.details?.day_portion && event.details.day_portion !== 'full_day')
        return 'Partial day'
    }
    return 'All day'
  }
  const start = String(event?.start || '').slice(11, 16)
  const end = String(event?.end || '').slice(11, 16)
  if (!start || !end) return ''
  const startDate = dateKey(event.start)
  const endDate = dateKey(event.end)
  if (startDate === endDate) return `${start}–${end}`
  if (day === startDate) return `${start} →`
  if (day === endDate) return `→ ${end}`
  return 'Continues'
}

export function eventRangeLabel(event) {
  if (!event) return ''
  const start = dateKey(event.start)
  const end = dateKey(event.end)
  if (!start || !end) return ''
  const finalDate = event.all_day ? addDays(end, -1) : end
  const fullDate = (value) => formatCalendarDate(value, { year: 'numeric' })
  if (event.all_day)
    return start === finalDate ? fullDate(start) : `${fullDate(start)} – ${fullDate(finalDate)}`
  const startTime = String(event.start).slice(11, 16)
  const endTime = String(event.end).slice(11, 16)
  return start === end
    ? `${fullDate(start)} · ${startTime}–${endTime}`
    : `${fullDate(start)} ${startTime} – ${fullDate(end)} ${endTime}`
}

export function typesQuery(types) {
  return types.length ? [...types] : ''
}
