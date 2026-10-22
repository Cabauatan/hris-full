function optional(value) {
  return value === '' || value === undefined || value === null ? null : String(value)
}

export function reportFilters(report, values = {}) {
  const filters = {
    scope: values.scope || 'visible',
    employee_id: optional(values.employee_id),
  }
  if (report?.date_basis) {
    filters.start = optional(values.start)
    filters.end = optional(values.end)
  }
  if (report?.statuses?.length) filters.status = optional(values.status)
  if (report?.year_filter) filters.year = optional(values.year)
  if (report?.key === 'payroll') filters.payroll_run_id = optional(values.payroll_run_id)
  return filters
}

export function reportDraft(report, defaults = {}) {
  return {
    scope: defaults.scope || 'visible',
    employee_id: defaults.employee_id ?? '',
    start: report?.date_basis ? defaults.start || '' : '',
    end: report?.date_basis ? defaults.end || '' : '',
    status: defaults.status || '',
    year: report?.year_filter ? defaults.year || '' : '',
    payroll_run_id: report?.key === 'payroll' ? (defaults.payroll_run_id ?? '') : '',
  }
}
