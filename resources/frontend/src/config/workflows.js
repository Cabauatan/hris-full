const datetimeRange = [
  { key: 'start_at', label: 'Start date and time', type: 'datetime', required: true },
  { key: 'end_at', label: 'End date and time', type: 'datetime', required: true },
]

const reason = { key: 'reason', label: 'Reason', type: 'textarea', required: true }
const document = {
  key: 'document_path',
  label: 'Supporting document reference',
  type: 'text',
  nullable: true,
  maxLength: 255,
  help: 'Enter the reference of an existing supporting document, when required.',
}

const dateTimeColumns = [
  { key: 'start_at', label: 'Start', type: 'datetime' },
  { key: 'end_at', label: 'End', type: 'datetime' },
]

const commonColumns = [
  { key: 'employee_id', label: 'Employee', type: 'relation' },
  { key: 'status', label: 'Status', type: 'status' },
]

const commonDetails = [
  { key: 'created_at', label: 'Submitted', type: 'datetime' },
  { key: 'reviewed_at', label: 'Reviewed', type: 'datetime' },
  { key: 'review_remarks', label: 'Review remarks' },
  { key: 'cancelled_at', label: 'Cancelled', type: 'datetime' },
  { key: 'cancellation_reason', label: 'Cancellation reason' },
]

export const profileFields = [
  { key: 'first_name', label: 'First name', type: 'text', required: true, maxLength: 100 },
  { key: 'middle_name', label: 'Middle name', type: 'text', nullable: true, maxLength: 100 },
  { key: 'last_name', label: 'Last name', type: 'text', required: true, maxLength: 100 },
  { key: 'suffix', label: 'Suffix', type: 'text', nullable: true, maxLength: 20 },
  { key: 'birth_date', label: 'Birth date', type: 'date', nullable: true },
  { key: 'gender', label: 'Gender', type: 'text', nullable: true, maxLength: 30 },
  { key: 'civil_status', label: 'Civil status', type: 'text', nullable: true, maxLength: 30 },
  { key: 'personal_email', label: 'Personal email', type: 'email', nullable: true, maxLength: 255 },
  { key: 'mobile_number', label: 'Mobile number', type: 'text', nullable: true, maxLength: 50 },
  { key: 'address_line_1', label: 'Address line 1', type: 'text', nullable: true, maxLength: 255 },
  { key: 'address_line_2', label: 'Address line 2', type: 'text', nullable: true, maxLength: 255 },
  { key: 'city', label: 'City', type: 'text', nullable: true, maxLength: 100 },
  { key: 'province', label: 'Province', type: 'text', nullable: true, maxLength: 100 },
  { key: 'postal_code', label: 'Postal code', type: 'text', nullable: true, maxLength: 20 },
  { key: 'country', label: 'Country', type: 'text', required: true, maxLength: 100 },
]

export const workflows = {
  leave: {
    key: 'leave',
    title: 'Leave requests',
    singular: 'Leave request',
    description: 'Request time away, track reviews, and manage authorized approvals.',
    icon: 'pi pi-calendar-minus',
    endpoint: '/leave-requests',
    permissionPrefix: 'leave',
    columns: [
      ...commonColumns,
      { key: 'start_date', label: 'From', type: 'date' },
      { key: 'end_date', label: 'Until', type: 'date' },
      { key: 'requested_units', label: 'Requested units', type: 'decimal' },
      { key: 'approved_units', label: 'Approved units', type: 'decimal' },
    ],
    detailColumns: [
      { key: 'leave_type_id', label: 'Leave type', type: 'relation' },
      { key: 'day_portion', label: 'Day portion' },
      { key: 'reason', label: 'Reason' },
      ...commonDetails,
    ],
    fields: [
      {
        key: 'leave_type_id',
        label: 'Leave type',
        type: 'lookup',
        required: true,
        lookup: { endpoint: '/leave-types', labelFields: ['name', 'code'] },
      },
      { key: 'start_date', label: 'Start date', type: 'date', required: true },
      { key: 'end_date', label: 'End date', type: 'date', required: true },
      {
        key: 'day_portion',
        label: 'Day portion',
        type: 'select',
        required: true,
        default: 'full_day',
        options: [
          { label: 'Full day', value: 'full_day' },
          { label: 'Morning half day', value: 'morning' },
          { label: 'Afternoon half day', value: 'afternoon' },
        ],
      },
      {
        key: 'requested_units',
        label: 'Requested units',
        type: 'decimal',
        required: true,
        min: 0.01,
        max: 999999.99,
        precision: 2,
      },
      reason,
      document,
    ],
  },
  cto: {
    key: 'cto',
    title: 'CTO requests',
    singular: 'CTO request',
    description: 'Use compensatory time credits for an approved period away from work.',
    icon: 'pi pi-clock',
    endpoint: '/cto-requests',
    permissionPrefix: 'cto',
    columns: [
      ...commonColumns,
      ...dateTimeColumns,
      { key: 'requested_minutes', label: 'Requested minutes', type: 'number' },
      { key: 'approved_minutes', label: 'Approved minutes', type: 'number' },
    ],
    detailColumns: [{ key: 'reason', label: 'Reason' }, ...commonDetails],
    fields: [...datetimeRange, reason],
  },
  'attendance-corrections': {
    key: 'attendance-corrections',
    title: 'Attendance corrections',
    singular: 'Attendance correction',
    description: 'Request a correction to your recorded arrival or departure time.',
    icon: 'pi pi-calendar-clock',
    endpoint: '/attendance-correction-requests',
    permissionPrefix: 'attendance-corrections',
    columns: [
      ...commonColumns,
      { key: 'attendance_date', label: 'Attendance date', type: 'date' },
      { key: 'requested_time_in', label: 'Requested time in', type: 'datetime' },
      { key: 'requested_time_out', label: 'Requested time out', type: 'datetime' },
    ],
    detailColumns: [
      { key: 'daily_attendance_id', label: 'Attendance record', type: 'relation' },
      { key: 'reason', label: 'Reason' },
      ...commonDetails,
    ],
    fields: [
      { key: 'attendance_date', label: 'Attendance date', type: 'date', required: true },
      {
        key: 'daily_attendance_id',
        label: 'Attendance record (optional)',
        type: 'lookup',
        nullable: true,
        lookup: { endpoint: '/ess/daily-attendances', labelFields: ['attendance_date', 'status'] },
      },
      { key: 'requested_time_in', label: 'Corrected time in', type: 'datetime', nullable: true },
      { key: 'requested_time_out', label: 'Corrected time out', type: 'datetime', nullable: true },
      reason,
      document,
    ],
  },
  'official-business': {
    key: 'official-business',
    title: 'Official business',
    singular: 'Official business request',
    description: 'Request authorization for duties outside your usual workplace.',
    icon: 'pi pi-briefcase',
    endpoint: '/official-business-requests',
    permissionPrefix: 'official-business',
    columns: [...commonColumns, ...dateTimeColumns, { key: 'destination', label: 'Destination' }],
    detailColumns: [{ key: 'purpose', label: 'Purpose' }, ...commonDetails],
    fields: [
      ...datetimeRange,
      { key: 'destination', label: 'Destination', type: 'text', nullable: true, maxLength: 255 },
      { key: 'purpose', label: 'Purpose', type: 'textarea', required: true },
      document,
    ],
  },
  'travel-orders': {
    key: 'travel-orders',
    title: 'Travel orders',
    singular: 'Travel order',
    description: 'Submit work travel plans and follow their approval status.',
    icon: 'pi pi-map',
    endpoint: '/travel-order-requests',
    permissionPrefix: 'travel-orders',
    columns: [...commonColumns, ...dateTimeColumns, { key: 'destination', label: 'Destination' }],
    detailColumns: [{ key: 'purpose', label: 'Purpose' }, ...commonDetails],
    fields: [
      ...datetimeRange,
      { key: 'destination', label: 'Destination', type: 'text', required: true, maxLength: 255 },
      { key: 'purpose', label: 'Purpose', type: 'textarea', required: true },
      document,
    ],
  },
  overtime: {
    key: 'overtime',
    title: 'Overtime requests',
    singular: 'Overtime request',
    description: 'Request extra work hours and record the authorized duration.',
    icon: 'pi pi-hourglass',
    endpoint: '/overtime-requests',
    permissionPrefix: 'overtime',
    columns: [
      ...commonColumns,
      ...dateTimeColumns,
      { key: 'requested_minutes', label: 'Requested minutes', type: 'number' },
      { key: 'approved_minutes', label: 'Approved minutes', type: 'number' },
    ],
    detailColumns: [{ key: 'reason', label: 'Reason' }, ...commonDetails],
    fields: [...datetimeRange, reason, document],
  },
  'profile-changes': {
    key: 'profile-changes',
    title: 'Profile changes',
    singular: 'Profile change request',
    description: 'Request updates to your personal information for HR review.',
    icon: 'pi pi-user-edit',
    endpoint: '/employee-profile-change-requests',
    permissionPrefix: 'profile-changes',
    columns: [...commonColumns, { key: 'created_at', label: 'Submitted', type: 'datetime' }],
    detailColumns: [
      { key: 'reason', label: 'Reason' },
      ...commonDetails,
      { key: 'applied_at', label: 'Applied', type: 'datetime' },
    ],
    fields: [...profileFields, { ...reason, required: false, nullable: true }, document],
  },
}

export default workflows
