export function toLocalInput(value) {
  if (!value) return ''
  return String(value).replace(' ', 'T').slice(0, 19)
}

export function initialValues(fields, record = {}, mode = 'create') {
  return Object.fromEntries(
    fields
      .filter(
        (field) =>
          !(mode === 'edit' && field.createOnly) && !(mode === 'create' && field.updateOnly),
      )
      .map((field) => {
        let value = record[field.key]
        if (value === undefined)
          value =
            field.default ??
            (field.type === 'boolean' ? false : field.type === 'payrollItems' ? [] : '')
        if (field.type === 'datetime') value = toLocalInput(value)
        if (field.type === 'file') value = null
        if (field.type === 'json' && value !== null && typeof value === 'object')
          value = JSON.stringify(value, null, 2)
        return [field.key, value]
      }),
  )
}

export function buildPayload(fields, draft, mode = 'create') {
  const payload = {}
  for (const field of fields) {
    if ((mode === 'edit' && field.createOnly) || (mode === 'create' && field.updateOnly)) continue
    let value = draft[field.key]
    if (value === undefined) continue
    if (field.type === 'file' && !value) continue
    if ((value === '' || value === null) && field.nullable && field.type !== 'settingValue')
      value = null
    if (field.type === 'integer' || field.type === 'lookup') {
      if (value !== '' && value !== null) value = Number(value)
    }
    if (field.type === 'decimal' && value !== null && value !== '') value = String(value)
    if (field.type === 'payrollItems' && Array.isArray(value)) {
      const itemKeys = ['code', 'name', 'type', 'quantity', 'rate', 'amount', 'remarks']
      value = value.map((item) =>
        Object.fromEntries(
          itemKeys
            .filter((key) => item[key] !== undefined)
            .map((key) => {
              let itemValue = item[key]
              if (
                ['quantity', 'rate', 'amount'].includes(key) &&
                itemValue !== null &&
                itemValue !== ''
              )
                itemValue = String(itemValue)
              return [key, itemValue]
            }),
        ),
      )
    }
    if (field.type === 'boolean') value = Boolean(value)
    if (field.type === 'json' && typeof value === 'string' && value.trim()) {
      try {
        value = JSON.parse(value)
      } catch {
        throw new Error(`${field.label} must contain valid JSON.`)
      }
    }
    if (field.type === 'settingValue' && value !== null) {
      if (draft.type === 'boolean') value = Boolean(value)
      if (draft.type === 'integer' && value !== '') {
        if (
          (typeof value === 'number' && !Number.isSafeInteger(value)) ||
          !/^-?\d+$/.test(String(value))
        )
          throw new Error('Value must contain a whole number without lost precision.')
        value = String(value)
      }
      if (draft.type === 'decimal') value = String(value)
      if (draft.type === 'json' && typeof value === 'string') {
        try {
          value = JSON.parse(value)
        } catch {
          throw new Error('Value must contain valid JSON.')
        }
      }
    }
    payload[field.key] = value
  }
  return payload
}

export function safeRedirect(value, fallback = '/home') {
  if (typeof value !== 'string') return fallback
  let decoded = value
  for (let depth = 0; depth < 8; depth += 1) {
    if (
      !decoded.startsWith('/') ||
      decoded.startsWith('//') ||
      /[\\\u0000-\u001f\u007f]/.test(decoded) ||
      /^\/(?:auth(?:\/|$)|login(?:[/?#]|$))/i.test(decoded)
    )
      return fallback
    let next
    try {
      next = decodeURIComponent(decoded)
    } catch {
      return fallback
    }
    if (next === decoded) return value
    decoded = next
  }
  return fallback
}

export function formatValue(value, type = 'text') {
  if (value === null || value === undefined || value === '') return '—'
  if (type === 'boolean') return value ? 'Yes' : 'No'
  if (type === 'datetime')
    return String(value)
      .replace('T', ' ')
      .replace(/(?:Z|[+-]\d{2}:\d{2})$/, '')
  if (type === 'date') return String(value).slice(0, 10)
  if (type === 'minutes') return `${value} min`
  if (type === 'money' || type === 'decimal') return String(value)
  if (typeof value === 'object') return JSON.stringify(value, null, 2)
  return String(value)
}

export function labelFor(key) {
  return key.replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())
}

export function statusSeverity(status) {
  if (
    ['approved', 'applied', 'present', 'finalized', 'completed', 'published', 'active'].includes(
      status,
    )
  )
    return 'success'
  if (['pending', 'processing', 'scheduled', 'incomplete', 'important'].includes(status))
    return 'warn'
  if (['rejected', 'absent', 'urgent'].includes(status)) return 'danger'
  return 'secondary'
}

export function multipartPayload(payload, mode = 'create') {
  const form = new FormData()
  for (const [key, value] of Object.entries(payload)) {
    form.append(key, value === null ? '' : typeof value === 'boolean' ? (value ? '1' : '0') : value)
  }
  if (mode === 'edit') form.append('_method', 'PUT')
  return form
}
