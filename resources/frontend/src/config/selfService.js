import { resources } from './resources.js'

function own(source, endpoint, title) {
  return {
    ...resources[source],
    key: endpoint,
    title,
    endpoint: `/ess/${endpoint}`,
    detailEndpoint: resources[source]?.endpoint,
    description:
      'Your personal records. Only information linked to your employee profile is shown.',
    ess: true,
    createPermission: undefined,
    updatePermission: undefined,
    actions: (resources[source]?.actions || []).filter(
      (action) => action.download || action.key === 'mark-viewed',
    ),
  }
}

export const selfService = {
  'time-logs': own('time-logs', 'time-logs', 'My time logs'),
  'daily-attendances': own('daily-attendances', 'daily-attendances', 'My attendance'),
  'schedule-assignments': own(
    'employee-schedule-assignments',
    'schedule-assignments',
    'My schedule',
  ),
  'leave-balances': own('leave-balances', 'leave-balances', 'My leave credits'),
  'cto-balances': own('cto-balances', 'cto-balances', 'My CTO credits'),
  'leave-credit-transactions': own(
    'leave-credit-transactions',
    'leave-credit-transactions',
    'My leave credit history',
  ),
  'cto-credit-transactions': own(
    'cto-credit-transactions',
    'cto-credit-transactions',
    'My CTO credit history',
  ),
  payslips: own('payslips', 'payslips', 'My payslips'),
}
