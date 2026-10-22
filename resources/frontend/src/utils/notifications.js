import { sourceLink } from './workspace.js'
import { workflows } from '../config/workflows.js'
import { canViewCalendar } from '../config/workspace.js'

const notificationModules = new Set([
  'announcements',
  'payslips',
  ...Object.values(workflows).flatMap((item) => [item.key, item.endpoint.slice(1)]),
])

export function notificationLink(item, auth) {
  if (
    item?.category === 'birthday' &&
    item.event === 'birthday' &&
    item.source?.module === 'calendar' &&
    item.source.scope === 'visible'
  )
    return canViewCalendar(auth)
      ? { path: '/calendar', query: { scope: 'visible', types: 'birthday' } }
      : null
  if (!['mine', 'visible'].includes(item?.source?.scope)) return null
  if (!notificationModules.has(item.source.module)) return null
  if (item.source.scope === 'mine' && !auth.employee?.id) return null
  return sourceLink(item.source, auth, item.source.scope)
}
