const weekdays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']

function scheduleName(record, fallback) {
  if (!record) return fallback
  if (record.name && record.code) return `${record.name} (${record.code})`
  return record.name || record.code || fallback
}

function clockTime(value) {
  const match = /^(\d{2}):(\d{2})(?::\d{2})?$/.exec(value || '')
  if (!match || Number(match[1]) > 23 || Number(match[2]) > 59) return null
  const hour = Number(match[1])
  return `${hour % 12 || 12}:${match[2]} ${hour < 12 ? 'AM' : 'PM'}`
}

export function scheduleDayDetails(record) {
  const working = record.is_working_day === true
  const start = clockTime(record.shift?.start_time)
  const end = clockTime(record.shift?.end_time)
  return {
    work_schedule: scheduleName(record.work_schedule, 'Schedule unavailable'),
    weekday: weekdays[Number(record.day_of_week) - 1] || '—',
    day_type: working ? 'Working day' : 'Rest day',
    shift: working ? scheduleName(record.shift, 'Not assigned') : 'No shift (rest day)',
    shift_hours: !working
      ? 'Not applicable'
      : start && end
        ? `${start} – ${end}${record.shift.is_overnight ? ' (next day)' : ''}`
        : '—',
    created_at: record.created_at,
    updated_at: record.updated_at,
  }
}
