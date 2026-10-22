<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import Button from 'primevue/button'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import Dialog from 'primevue/dialog'
import Message from 'primevue/message'
import Paginator from 'primevue/paginator'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import http, { apiError } from '@/api/http'
import { useAuthStore } from '@/stores/auth'
import FieldForm from '@/components/FieldForm.vue'
import RecordDetails from '@/components/RecordDetails.vue'
import { workflows, profileFields } from '@/config/workflows'
import { initialValues, buildPayload, formatValue } from '@/utils/forms'
import { recordId, recordInScope } from '@/utils/workspace'
import { columnValue, recordLabel } from '@/utils/records'

const route = useRoute()
const auth = useAuthStore()
const workflow = computed(() => workflows[route.params.module])
const isEss = computed(() => route.meta.ess === true)
const employee = computed(() => auth.employee ?? auth.user?.employee ?? null)
const hasEmployee = computed(() => Boolean(employee.value?.id))
const canCreate = computed(() =>
  Boolean(
    workflow.value && hasEmployee.value && auth.can(`${workflow.value.permissionPrefix}.create`),
  ),
)

const rows = ref([])
const loading = ref(false)
const page = ref(1)
const perPage = ref(15)
const total = ref(0)
const status = ref(null)
const pageError = ref('')
const success = ref('')
let listVersion = 0
let detailVersion = 0
let createVersion = 0
onBeforeUnmount(() => {
  listVersion += 1
  detailVersion += 1
  createVersion += 1
})

const statusOptions = computed(() => [
  { label: 'All statuses', value: null },
  { label: 'Pending', value: 'pending' },
  { label: 'Approved', value: 'approved' },
  { label: 'Rejected', value: 'rejected' },
  { label: 'Cancelled', value: 'cancelled' },
  ...(workflow.value?.key === 'profile-changes' ? [{ label: 'Applied', value: 'applied' }] : []),
])
const listColumns = computed(() =>
  (workflow.value?.columns ?? []).filter(
    (column) =>
      !['id', 'uuid'].includes(column.key) && (!isEss.value || column.key !== 'employee_id'),
  ),
)
const detailColumns = computed(() =>
  [...(workflow.value?.columns ?? []), ...(workflow.value?.detailColumns ?? [])].filter(
    (column, index, all) => all.findIndex((item) => item.key === column.key) === index,
  ),
)

const createVisible = ref(false)
const createBusy = ref(false)
const createLoading = ref(false)
const createError = ref('')
const createErrors = ref({})
const createDraft = ref({})
const profileSnapshot = ref({})
const leaveTypes = ref([])
const selectedLeaveType = computed(() =>
  leaveTypes.value.find((item) => Number(item.id) === Number(createDraft.value.leave_type_id)),
)
const createFields = computed(() =>
  (workflow.value?.fields ?? []).map((field) => {
    if (workflow.value?.key === 'leave' && field.key === 'leave_type_id') {
      return {
        ...field,
        type: 'select',
        lookup: undefined,
        options: leaveTypes.value.map((type) => ({
          label: `${type.name} (${type.code})`,
          value: type.id,
        })),
      }
    }
    if (
      workflow.value?.key === 'leave' &&
      field.key === 'day_portion' &&
      !selectedLeaveType.value?.allows_partial_day
    ) {
      return { ...field, options: [{ label: 'Full day', value: 'full_day' }] }
    }
    return field
  }),
)

const detailVisible = ref(false)
const detailLoading = ref(false)
const detailError = ref('')
const detail = ref(null)
const actionVisible = ref(false)
const activeAction = ref('')
const actionBusy = ref(false)
const actionError = ref('')
const actionErrors = ref({})
const actionDraft = ref({})

const allowedActions = computed(() => {
  if (!detail.value) return []
  const abilities = detail.value.abilities ?? {}
  if (detail.value.status === 'pending') {
    return ['approve', 'reject', 'cancel'].filter((action) => abilities[action] === true)
  }
  return workflow.value?.key === 'profile-changes' &&
    detail.value.status === 'approved' &&
    abilities.apply === true
    ? ['apply']
    : []
})
const actionTitle = computed(
  () => `${capitalize(activeAction.value)} ${workflow.value?.singular?.toLowerCase() ?? 'request'}`,
)
const actionFields = computed(() => {
  if (activeAction.value === 'apply') return []
  if (activeAction.value === 'cancel')
    return [
      { key: 'reason', label: 'Cancellation reason (optional)', type: 'textarea', nullable: true },
    ]
  const fields = [{ key: 'remarks', label: 'Remarks (optional)', type: 'textarea', nullable: true }]
  if (activeAction.value !== 'approve') return fields
  if (workflow.value?.key === 'leave') {
    fields.unshift({
      key: 'approved_units',
      label: 'Approved units',
      type: 'decimal',
      required: true,
      min: 0.01,
      max: Number(detail.value?.requested_units ?? 0),
      precision: 2,
    })
  }
  if (['cto', 'overtime'].includes(workflow.value?.key)) {
    const limit = workflow.value.key === 'cto' ? 2147483648 : 4294967295
    fields.unshift({
      key: 'approved_minutes',
      label: 'Approved minutes',
      type: 'integer',
      required: true,
      min: 1,
      max: Math.min(Number(detail.value?.requested_minutes ?? 0), limit),
      precision: 0,
    })
  }
  return fields
})

function capitalize(value) {
  return value ? value.charAt(0).toUpperCase() + value.slice(1) : ''
}

function severity(value) {
  return (
    {
      pending: 'warn',
      approved: 'success',
      applied: 'success',
      rejected: 'danger',
      cancelled: 'secondary',
    }[value] ?? 'info'
  )
}

function scopeKey() {
  return `${route.params.module}:${isEss.value}:${route.fullPath}`
}

async function loadList(targetPage = 1) {
  const version = ++listVersion
  const config = workflow.value
  pageError.value = ''
  if (!config) {
    loading.value = false
    rows.value = []
    total.value = 0
    pageError.value = 'This request workspace could not be found.'
    return
  }
  if (isEss.value && !hasEmployee.value) {
    loading.value = false
    rows.value = []
    total.value = 0
    pageError.value = 'An employee profile is required to view your requests.'
    return
  }
  if (!auth.can(`${config.permissionPrefix}.view`)) {
    loading.value = false
    rows.value = []
    total.value = 0
    pageError.value = 'You do not have access to this request workspace.'
    return
  }
  loading.value = true
  try {
    const response = await http.get(`${isEss.value ? '/ess' : ''}${config.endpoint}`, {
      params: {
        page: targetPage,
        per_page: perPage.value,
        ...(status.value ? { status: status.value } : {}),
      },
    })
    if (version !== listVersion) return
    rows.value = response.data.data ?? []
    page.value = response.data.meta?.current_page ?? targetPage
    total.value = response.data.meta?.total ?? rows.value.length
  } catch (error) {
    if (version !== listVersion) return
    rows.value = []
    total.value = 0
    pageError.value = apiError(error).message
  } finally {
    if (version === listVersion) loading.value = false
  }
}

async function loadLeaveTypes() {
  const collected = []
  let next = 1
  let last = 1
  do {
    const response = await http.get('/leave-types', {
      params: { page: next, per_page: 100, is_active: 1 },
    })
    collected.push(...(response.data.data ?? []))
    last = response.data.meta?.last_page ?? next
    next++
  } while (next <= last)
  return collected.filter((type) => type.is_active)
}

async function openCreate() {
  if (!canCreate.value || createBusy.value) return
  const version = ++createVersion
  const scope = scopeKey()
  const current = () => createVisible.value && version === createVersion && scopeKey() === scope
  const config = workflow.value
  createError.value = ''
  createErrors.value = {}
  createDraft.value = initialValues(config.fields)
  if (config.key === 'leave') {
    leaveTypes.value = []
    createDraft.value.day_portion = 'full_day'
  }
  createVisible.value = true
  createLoading.value = true
  try {
    if (config.key === 'profile-changes') {
      const response = await http.get('/ess/profile')
      if (!current()) return
      profileSnapshot.value = response.data.data
      createDraft.value = initialValues(config.fields, profileSnapshot.value)
    }
    if (config.key === 'leave') {
      const types = await loadLeaveTypes()
      if (!current()) return
      leaveTypes.value = types
      if (!types.length)
        createError.value = 'No active leave types are available. Contact HR to configure one.'
    }
  } catch (error) {
    if (current()) createError.value = apiError(error).message
  } finally {
    if (current()) createLoading.value = false
  }
}

function normalizeProfile(field, value) {
  if (field.nullable && (value === '' || value === null || value === undefined)) return null
  return typeof value === 'string' ? value.trim() : value
}

function validateFields(fields, payload) {
  const errors = {}
  for (const field of fields) {
    const value = payload[field.key]
    const empty = value === '' || value === null || value === undefined
    if (field.required && empty) errors[field.key] = [`${field.label} is required.`]
    if (empty) continue
    if (['number', 'integer', 'decimal'].includes(field.type)) {
      if (
        !Number.isFinite(Number(value)) ||
        (field.min !== undefined && Number(value) < field.min) ||
        (field.max !== undefined && Number(value) > field.max)
      ) {
        errors[field.key] = [
          `${field.label} must be between ${field.min ?? 0} and ${field.max ?? 'the allowed maximum'}.`,
        ]
      } else if (field.precision === 0 && !Number.isInteger(Number(value))) {
        errors[field.key] = [`${field.label} must be a whole number.`]
      } else if (field.precision === 2 && !/^\d+(?:\.\d{1,2})?$/.test(String(value))) {
        errors[field.key] = [`${field.label} must have no more than two decimal places.`]
      }
    }
  }
  return errors
}

function createPayload() {
  const config = workflow.value
  const payload = buildPayload(config.fields, createDraft.value)
  const errors = validateFields(config.fields, payload)
  if (payload.start_at && payload.end_at) {
    const minutes = (new Date(payload.end_at) - new Date(payload.start_at)) / 60000
    if (minutes <= 0) errors.end_at = ['The end must be after the start.']
    if (
      ['cto', 'overtime'].includes(config.key) &&
      (Math.floor(minutes) < 1 || minutes > 4294967295)
    ) {
      errors.end_at = [
        'The duration must contain at least one complete minute and stay within the allowed range.',
      ]
    }
  }
  if (config.key === 'leave') {
    if (payload.end_date < payload.start_date)
      errors.end_date = ['The end date cannot be before the start date.']
    const type = selectedLeaveType.value
    if (type?.requires_balance && payload.start_date?.slice(0, 4) !== payload.end_date?.slice(0, 4))
      errors.end_date = ['This leave type must stay within one calendar year.']
    if (type?.requires_document && !payload.document_path)
      errors.document_path = ['A supporting document reference is required for this leave type.']
    if (type && !type.allows_partial_day && payload.day_portion !== 'full_day')
      errors.day_portion = ['This leave type only allows full-day leave.']
    if (payload.requested_units !== null && payload.requested_units !== undefined)
      payload.requested_units = String(payload.requested_units)
  }
  if (config.key === 'attendance-corrections') {
    if (!payload.requested_time_in && !payload.requested_time_out)
      errors.requested_time_in = ['Provide a corrected time in or time out.']
    if (
      payload.requested_time_in &&
      payload.requested_time_out &&
      new Date(payload.requested_time_out) < new Date(payload.requested_time_in)
    )
      errors.requested_time_out = ['Time out cannot be earlier than time in.']
  }
  if (config.key === 'profile-changes') {
    const changes = {}
    for (const field of profileFields) {
      const current = normalizeProfile(field, profileSnapshot.value[field.key])
      const proposed = normalizeProfile(field, payload[field.key])
      if (current !== proposed) changes[field.key] = proposed
    }
    if (!Object.keys(changes).length)
      createError.value = 'Change at least one personal information field before submitting.'
    createErrors.value = errors
    return { changes, reason: payload.reason ?? null, document_path: payload.document_path ?? null }
  }
  createErrors.value = errors
  // Duration fields are intentionally absent from CTO and overtime submission.
  return payload
}

async function submitCreate() {
  if (!canCreate.value || createBusy.value || createLoading.value) return
  const scope = scopeKey()
  createError.value = ''
  const config = workflow.value
  const payload = createPayload()
  if (createError.value || Object.keys(createErrors.value).length) return
  createBusy.value = true
  try {
    await http.post(config.endpoint, payload)
    if (scopeKey() !== scope) return
    createVisible.value = false
    success.value = `${config.singular} submitted for review.`
    await loadList(1)
  } catch (error) {
    if (scopeKey() !== scope) return
    const problem = apiError(error)
    createError.value = problem.message
    createErrors.value =
      config.key === 'profile-changes'
        ? Object.fromEntries(
            Object.entries(problem.errors ?? {}).map(([key, value]) => [
              key.startsWith('changes.') ? key.slice(8) : key,
              value,
            ]),
          )
        : (problem.errors ?? {})
  } finally {
    createBusy.value = false
  }
}

async function loadDetail(id) {
  const version = ++detailVersion
  const config = workflow.value
  detailLoading.value = true
  detailError.value = ''
  detail.value = null
  try {
    const response = await http.get(`${config.endpoint}/${id}`)
    if (version === detailVersion) {
      if (!recordInScope(response.data.data, isEss.value, employee.value?.id)) {
        detailError.value = 'This request is outside your personal workspace.'
        return
      }
      detail.value = response.data.data
    }
  } catch (error) {
    if (version === detailVersion) detailError.value = apiError(error).message
  } finally {
    if (version === detailVersion) detailLoading.value = false
  }
}

function openDetail(record) {
  detailVisible.value = true
  loadDetail(record.id)
}

function openAction(action) {
  if (actionBusy.value || !allowedActions.value.includes(action)) return
  activeAction.value = action
  actionError.value = ''
  actionErrors.value = {}
  actionDraft.value = initialValues(actionFields.value)
  if (action === 'approve' && workflow.value.key === 'leave')
    actionDraft.value.approved_units = Number(detail.value.requested_units)
  if (action === 'approve' && ['cto', 'overtime'].includes(workflow.value.key))
    actionDraft.value.approved_minutes = actionFields.value.find(
      (field) => field.key === 'approved_minutes',
    ).max
  actionVisible.value = true
}

async function submitAction() {
  if (!allowedActions.value.includes(activeAction.value) || actionBusy.value) return
  const scope = scopeKey()
  const actionDetailVersion = detailVersion
  const payload = buildPayload(actionFields.value, actionDraft.value)
  actionErrors.value = validateFields(actionFields.value, payload)
  if (Object.keys(actionErrors.value).length) return
  if (payload.approved_units !== undefined) payload.approved_units = String(payload.approved_units)
  const config = workflow.value
  const id = detail.value.id
  const action = activeAction.value
  actionBusy.value = true
  actionError.value = ''
  try {
    await http.post(`${config.endpoint}/${id}/${action}`, payload)
    if (scopeKey() !== scope || detailVersion !== actionDetailVersion) return
    actionVisible.value = false
    const completedAction = {
      approve: 'approved',
      reject: 'rejected',
      cancel: 'cancelled',
      apply: 'applied',
    }[action]
    success.value = `${config.singular} ${completedAction}.`
    await Promise.all([loadDetail(id), loadList(page.value)])
  } catch (error) {
    if (scopeKey() !== scope || detailVersion !== actionDetailVersion) return
    const problem = apiError(error)
    actionError.value = problem.message
    actionErrors.value = problem.errors ?? {}
    if ([403, 409].includes(problem.status)) await loadDetail(id)
  } finally {
    actionBusy.value = false
  }
}

function changePage(event) {
  perPage.value = event.rows
  loadList(event.page + 1)
}

watch(
  () => createDraft.value.leave_type_id,
  () => {
    if (workflow.value?.key === 'leave' && !selectedLeaveType.value?.allows_partial_day)
      createDraft.value.day_portion = 'full_day'
  },
)

watch(
  () => [route.params.module, route.meta.ess],
  () => {
    ++detailVersion
    detail.value = null
    detailVisible.value = false
    actionVisible.value = false
    createVisible.value = false
    detailLoading.value = false
    createLoading.value = false
    success.value = ''
    status.value = null
    page.value = 1
    rows.value = []
    total.value = 0
    loadList(1)
  },
  { immediate: true },
)

watch(
  () => route.query.record,
  (value) => {
    detailVersion += 1
    detail.value = null
    detailVisible.value = false
    actionVisible.value = false
    createVisible.value = false
    createLoading.value = false
    if (value === undefined) return
    const id = recordId(value)
    if (id) openDetail({ id })
    else {
      detailVisible.value = true
      detailLoading.value = false
      detailError.value = 'The record link is invalid.'
    }
  },
  { immediate: true },
)
watch(detailVisible, (visible) => {
  if (!visible) {
    detailVersion += 1
    detailLoading.value = false
    actionVisible.value = false
  }
})
watch(
  createVisible,
  (visible) => {
    if (!visible) {
      createVersion += 1
      createLoading.value = false
    }
  },
  { flush: 'sync' },
)
</script>

<template>
  <section>
    <div class="page-heading">
      <div>
        <p class="eyebrow">{{ isEss ? 'My requests' : 'Request workspace' }}</p>
        <h1>{{ workflow?.title ?? 'Requests' }}</h1>
        <p class="muted">{{ workflow?.description }}</p>
      </div>
      <Button v-if="canCreate" label="New request" icon="pi pi-plus" @click="openCreate" />
    </div>

    <Message v-if="success" severity="success" :closable="true" @close="success = ''">{{
      success
    }}</Message>
    <Message v-if="pageError" severity="error" :closable="false">{{ pageError }}</Message>
    <Message
      v-if="workflow && auth.can(`${workflow.permissionPrefix}.create`) && !hasEmployee"
      severity="info"
      :closable="false"
    >
      Link an employee profile to your account before submitting a request.
    </Message>

    <div v-if="workflow" class="panel">
      <div class="actions">
        <Select
          v-model="status"
          :options="statusOptions"
          option-label="label"
          option-value="value"
          aria-label="Filter requests by status"
          placeholder="All statuses"
          @change="loadList(1)"
        />
        <Button
          label="Refresh"
          icon="pi pi-refresh"
          severity="secondary"
          outlined
          :loading="loading"
          @click="loadList(page)"
        />
        <span class="muted">{{ total }} {{ total === 1 ? 'request' : 'requests' }}</span>
      </div>
      <DataTable
        :value="rows"
        :loading="loading"
        data-key="id"
        striped-rows
        responsive-layout="scroll"
      >
        <template #empty
          ><div class="empty-state">
            No {{ status ? `${status} ` : '' }}requests to display.
          </div></template
        >
        <Column
          v-for="column in listColumns"
          :key="column.key"
          :field="column.key"
          :header="column.label"
          :body-class="
            ['reason', 'remarks', 'destination'].includes(column.key)
              ? 'table-text-cell'
              : undefined
          "
        >
          <template #body="{ data }">
            <Tag
              v-if="column.type === 'status'"
              :value="capitalize(data[column.key])"
              :severity="severity(data[column.key])"
            />
            <span v-else>{{ formatValue(columnValue(data, column), column.type) }}</span>
          </template>
        </Column>
        <Column header="Details" header-class="table-actions-cell" body-class="table-actions-cell">
          <template #body="{ data }"
            ><Button
              label="View"
              icon="pi pi-arrow-up-right"
              text
              size="small"
              :aria-label="`View ${recordLabel(data, workflow.singular)}`"
              @click="openDetail(data)"
          /></template>
        </Column>
      </DataTable>
      <Paginator
        :first="(page - 1) * perPage"
        :rows="perPage"
        :total-records="total"
        :rows-per-page-options="[15, 30, 50, 100]"
        @page="changePage"
      />
    </div>

    <Dialog
      v-model:visible="createVisible"
      :header="`New ${workflow?.singular?.toLowerCase() ?? 'request'}`"
      modal
      :style="{ width: 'min(52rem, 95vw)' }"
      :closable="!createBusy"
      :close-on-escape="!createBusy"
    >
      <Message v-if="createError" severity="error" :closable="false">{{ createError }}</Message>
      <p v-if="createLoading" class="muted">
        <i class="pi pi-spin pi-spinner" aria-hidden="true" /> Loading your form…
      </p>
      <form v-else class="form-stack" @submit.prevent="submitCreate">
        <p v-if="workflow?.key === 'profile-changes'" class="muted">
          Update the information below. Only fields you change will be sent for review.
        </p>
        <p v-if="['cto', 'overtime'].includes(workflow?.key)" class="muted">
          The requested duration will be calculated from your start and end times.
        </p>
        <FieldForm
          v-model="createDraft"
          :fields="createFields"
          :errors="createErrors"
          :disabled="createBusy"
        />
        <div class="actions">
          <Button
            type="button"
            label="Close"
            severity="secondary"
            outlined
            :disabled="createBusy"
            @click="createVisible = false"
          />
          <Button
            type="submit"
            label="Submit request"
            icon="pi pi-send"
            :loading="createBusy"
            :disabled="!canCreate || (workflow?.key === 'leave' && !leaveTypes.length)"
          />
        </div>
      </form>
    </Dialog>

    <Dialog
      v-model:visible="detailVisible"
      :header="workflow?.singular ?? 'Request'"
      modal
      :closable="!actionVisible && !actionBusy"
      :close-on-escape="!actionVisible && !actionBusy"
      :style="{ width: 'min(60rem, 95vw)' }"
    >
      <Message v-if="detailError" severity="error" :closable="false">{{ detailError }}</Message>
      <p v-if="detailLoading" class="muted">
        <i class="pi pi-spin pi-spinner" aria-hidden="true" /> Loading request…
      </p>
      <div v-if="detail" class="form-stack">
        <div class="actions">
          <Tag :value="capitalize(detail.status)" :severity="severity(detail.status)" />
          <Button
            v-for="action in allowedActions"
            :key="action"
            :label="capitalize(action)"
            :severity="
              action === 'reject' ? 'danger' : action === 'cancel' ? 'secondary' : 'success'
            "
            :outlined="action === 'cancel'"
            @click="openAction(action)"
          />
        </div>
        <RecordDetails :record="detail" :columns="detailColumns" />
        <template v-if="workflow?.key === 'profile-changes'">
          <h3>Requested changes</h3>
          <DataTable :value="detail.items ?? []" size="small">
            <template #empty>No change items are available.</template>
            <Column field="field_name" header="Field"
              ><template #body="{ data }">{{
                profileFields.find((field) => field.key === data.field_name)?.label ??
                data.field_name
              }}</template></Column
            >
            <Column field="old_value" header="Current at submission" body-class="table-text-cell"
              ><template #body="{ data }">{{ formatValue(data.old_value) }}</template></Column
            >
            <Column field="new_value" header="Requested" body-class="table-text-cell"
              ><template #body="{ data }">{{ formatValue(data.new_value) }}</template></Column
            >
          </DataTable>
        </template>
        <template v-else>
          <h3>Approval history</h3>
          <DataTable :value="detail.approval_actions ?? []" size="small">
            <template #empty>No approval actions have been recorded.</template>
            <Column field="action" header="Action"
              ><template #body="{ data }">{{ capitalize(data.action) }}</template></Column
            >
            <Column field="acted_by_user_id" header="Reviewed by"
              ><template #body="{ data }">{{
                formatValue(columnValue(data, 'acted_by_user_id'))
              }}</template></Column
            >
            <Column field="remarks" header="Remarks" body-class="table-text-cell"
              ><template #body="{ data }">{{ formatValue(data.remarks) }}</template></Column
            >
            <Column field="acted_at" header="When"
              ><template #body="{ data }">{{
                formatValue(data.acted_at, 'datetime')
              }}</template></Column
            >
          </DataTable>
        </template>
      </div>
    </Dialog>

    <Dialog
      v-model:visible="actionVisible"
      :header="actionTitle"
      modal
      :style="{ width: 'min(36rem, 95vw)' }"
      :closable="!actionBusy"
      :close-on-escape="!actionBusy"
    >
      <form class="form-stack" @submit.prevent="submitAction">
        <Message v-if="actionError" severity="error" :closable="false">{{ actionError }}</Message>
        <p v-if="activeAction === 'apply'">
          Apply the approved changes to this employee's profile?
        </p>
        <p v-else-if="activeAction === 'cancel'">
          Cancel this pending request? You can submit a new request if your plans change.
        </p>
        <FieldForm
          v-if="actionFields.length"
          v-model="actionDraft"
          :fields="actionFields"
          :errors="actionErrors"
          :disabled="actionBusy"
        />
        <div class="actions">
          <Button
            type="button"
            label="Close"
            severity="secondary"
            outlined
            :disabled="actionBusy"
            @click="actionVisible = false"
          />
          <Button
            type="submit"
            :label="capitalize(activeAction)"
            :severity="
              activeAction === 'reject'
                ? 'danger'
                : activeAction === 'cancel'
                  ? 'secondary'
                  : 'success'
            "
            :loading="actionBusy"
            :disabled="!allowedActions.includes(activeAction)"
          />
        </div>
      </form>
    </Dialog>
  </section>
</template>
