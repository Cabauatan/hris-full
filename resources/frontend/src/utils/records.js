export function isInternalIdentifier(key) {
  return /^(?:id|uuid)$|_(?:ids?|uuids?)$/i.test(key)
}

export function displayLabel(key, label) {
  const readable = (label || key).replace(/\b(?:ids?|uuids?)\b/gi, '').trim()
  return readable
    .replace(/_(?:ids?|uuids?)$/i, '')
    .replace(/_/g, ' ')
    .replace(/\s+/g, ' ')
    .replace(/\b\w/g, (letter) => letter.toUpperCase())
}

function text(value) {
  return typeof value === 'string' || typeof value === 'number' ? String(value).trim() : ''
}

export function lookupLabel(record, fields = []) {
  if (!record || typeof record !== 'object') return ''
  const fullName = ['first_name', 'middle_name', 'last_name', 'suffix']
    .map((key) => text(record[key]))
    .filter(Boolean)
    .join(' ')
  if (fullName) return [text(record.employee_number), fullName].filter(Boolean).join(' · ')
  if (record.run_number !== undefined && record.run_number !== null) {
    return [`Run ${record.run_number}`, record.display_labels?.payroll_period_id]
      .filter(Boolean)
      .join(' · ')
  }
  const configured = fields
    .filter((key) => !isInternalIdentifier(key))
    .map((key) => text(record[key]))
    .filter(Boolean)
  if (configured.length) return [...new Set(configured)].join(' · ')
  const name = text(record.name || record.title || record.subject)
  return [text(record.employee_number || record.code), name].filter(Boolean).join(' · ')
}

const relationAliases = {
  supervisor_employee_id: 'supervisor',
  acted_by_user_id: 'actor',
  reviewed_by_user_id: 'reviewer',
  approved_by_user_id: 'approver',
  rejected_by_user_id: 'rejector',
  cancelled_by_user_id: 'canceller',
  created_by_user_id: 'creator',
  updated_by_user_id: 'updater',
  published_by_user_id: 'publisher',
  delegator_user_id: 'delegator',
  delegate_user_id: 'delegate',
}

export function columnValue(record, columnOrKey) {
  const key = typeof columnOrKey === 'string' ? columnOrKey : columnOrKey.key
  if (!record) return null
  if (!isInternalIdentifier(key)) return displayRecord(record[key])
  if (/^(?:id|uuid)$/i.test(key)) return null
  const label = record.display_labels?.[key]
  if (typeof label === 'string' && label.trim()) return label
  if (Array.isArray(label)) return label.filter((item) => typeof item === 'string').join(' · ')
  const base = key.replace(/_(?:ids?|uuids?)$/i, '')
  const related = record[relationAliases[key] || base]
  const relatedLabel = typeof related === 'string' ? related : lookupLabel(related)
  if (relatedLabel && !/^\d+$/.test(relatedLabel)) return relatedLabel
  const companion = record[`${base}_name`] || record[`${base}_code`]
  if (text(companion)) return text(companion)
  return record[key] == null || record[key] === '' ? null : 'Unavailable'
}

export function displayRecord(record) {
  if (Array.isArray(record)) return record.map(displayRecord)
  if (record === null || typeof record !== 'object') return record
  const result = {}
  for (const [key, value] of Object.entries(record)) {
    if (key === 'display_labels' || /^(?:id|uuid)$/i.test(key)) continue
    if (isInternalIdentifier(key)) {
      const base = key.replace(/_(?:ids?|uuids?)$/i, '')
      if (Object.hasOwn(record, `${base}_name`)) continue
      if (Object.hasOwn(record, base) && !text(record.display_labels?.[key])) continue
      const readable = columnValue(record, key)
      if (readable !== null) result[base] = readable
    } else {
      if (!Object.hasOwn(result, key)) result[key] = displayRecord(value)
    }
  }
  return result
}

export function recordLabel(record, fallback = 'Record') {
  const ownLabel = lookupLabel(record)
  if (ownLabel) return ownLabel
  const employee = record?.display_labels?.employee_id
  const date =
    record?.attendance_date || record?.start_date || record?.start_at || record?.created_at
  return [employee, text(date)].filter(Boolean).join(' · ') || fallback
}
