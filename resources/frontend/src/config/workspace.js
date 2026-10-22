export const calendarPermissions = Object.freeze([
  'employees.view',
  'employees.view-team',
  'holidays.view',
  'schedules.view',
  'schedules.assign',
  'leave.view',
  'cto.view',
  'overtime.view',
  'official-business.view',
  'travel-orders.view',
])

export function canViewCalendar(auth) {
  return (
    auth.authenticated === true && (Boolean(auth.employee?.id) || auth.canAny(calendarPermissions))
  )
}
