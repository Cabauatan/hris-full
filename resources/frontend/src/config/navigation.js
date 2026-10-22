import { resources } from './resources.js'
import { workflows } from './workflows.js'
import { canViewCalendar } from './workspace.js'
import { reportPermissions } from './reports.js'

export function navigationGroups(auth) {
  const groups = [
    {
      title: 'Workspace',
      links: [
        { to: '/dashboard', label: 'Dashboard', icon: 'pi pi-chart-bar' },
        { to: '/home', label: 'Home', icon: 'pi pi-home' },
        { to: '/notifications', label: 'Notifications', icon: 'pi pi-bell' },
        ...(canViewCalendar(auth)
          ? [{ to: '/calendar', label: 'Calendar', icon: 'pi pi-calendar' }]
          : []),
        ...(auth.canAny(reportPermissions)
          ? [{ to: '/reports', label: 'Reports', icon: 'pi pi-file-export' }]
          : []),
      ],
    },
  ]
  if (auth.can('recruitment.view'))
    groups.push({
      title: 'Recruitment',
      links: [{ to: '/recruitment', label: 'Applications & assessments', icon: 'pi pi-users' }],
    })
  if (auth.employee) {
    const links = [{ to: '/profile', label: 'My profile', icon: 'pi pi-id-card' }]
    if (auth.can('attendance.view'))
      links.push(
        { to: '/my/daily-attendances', label: 'My attendance', icon: 'pi pi-clock' },
        { to: '/my/time-logs', label: 'My time logs', icon: 'pi pi-history' },
      )
    if (auth.canAny(['schedules.view', 'schedules.assign']))
      links.push({ to: '/my/schedule-assignments', label: 'My schedule', icon: 'pi pi-calendar' })
    if (auth.can('payslips.view'))
      links.push({ to: '/my/payslips', label: 'My payslips', icon: 'pi pi-wallet' })
    if (auth.can('leave.view'))
      links.push({
        to: '/my/leave-balances',
        label: 'My leave credits',
        icon: 'pi pi-calendar-plus',
      })
    if (auth.can('cto.view'))
      links.push({ to: '/my/cto-balances', label: 'My CTO credits', icon: 'pi pi-stopwatch' })
    groups.push({ title: 'Self-service', links })
    const ownRequests = Object.values(workflows)
      .filter((item) => auth.can(`${item.permissionPrefix}.view`))
      .map((item) => ({ to: `/my/requests/${item.key}`, label: item.title, icon: item.icon }))
    if (ownRequests.length) groups.push({ title: 'My requests', links: ownRequests })
  }
  const review = Object.values(workflows)
    .filter(
      (item) =>
        auth.can(`${item.permissionPrefix}.view`) &&
        auth.canAny([`${item.permissionPrefix}.approve`, 'employees.view', 'employees.view-team']),
    )
    .map((item) => ({ to: `/requests/${item.key}`, label: item.title, icon: item.icon }))
  if (review.length) groups.push({ title: 'Requests & review', links: review })
  for (const group of [
    'People',
    'Organization',
    'Attendance',
    'Leave & CTO',
    'Payroll',
    'Administration',
  ]) {
    const links = Object.values(resources)
      .filter((item) => item.group === group && auth.canAny(item.viewPermissions))
      .map((item) => ({ to: `/manage/${item.key}`, label: item.title, icon: item.icon }))
    if (links.length) groups.push({ title: group, links })
  }
  return groups
}
