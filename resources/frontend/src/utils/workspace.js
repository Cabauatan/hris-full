import { resources } from '../config/resources.js'
import { workflows } from '../config/workflows.js'
import { selfService } from '../config/selfService.js'

export function recordId(value) {
  if (typeof value !== 'string' && typeof value !== 'number') return null
  const text = String(value)
  if (!/^[1-9]\d*$/.test(text) || !Number.isSafeInteger(Number(text))) return null
  return text
}

export function recordInScope(record, ownOnly, employeeId) {
  return (
    !ownOnly ||
    (recordId(employeeId) !== null && String(record?.employee_id) === String(employeeId))
  )
}

export function sourceLink(source, auth, scope = 'visible') {
  const id = recordId(source?.id)
  if (!id || typeof source?.module !== 'string') return null
  const workflow = Object.values(workflows).find(
    (item) => item.key === source.module || item.endpoint === `/${source.module}`,
  )
  const own = scope === 'mine' && Boolean(auth.employee?.id)
  if (workflow) {
    if (!auth.can(`${workflow.permissionPrefix}.view`)) return null
    return { path: `${own ? '/my/requests' : '/requests'}/${workflow.key}`, query: { record: id } }
  }
  const resource = Object.values(resources).find(
    (item) => item.key === source.module || item.endpoint === `/${source.module}`,
  )
  if (!resource || !auth.canAny(resource.viewPermissions)) return null
  const personal = own
    ? Object.values(selfService).find((item) => item.detailEndpoint === resource.endpoint)
    : null
  return {
    path: personal ? `/my/${personal.key}` : `/manage/${resource.key}`,
    query: { record: id },
  }
}

export function requestTotals(requests = []) {
  return requests.reduce(
    (totals, request) => {
      totals.total += Number(request.total) || 0
      for (const status of ['pending', 'approved', 'rejected', 'cancelled', 'applied'])
        totals[status] += Number(request.by_status?.[status]) || 0
      return totals
    },
    { total: 0, pending: 0, approved: 0, rejected: 0, cancelled: 0, applied: 0 },
  )
}
