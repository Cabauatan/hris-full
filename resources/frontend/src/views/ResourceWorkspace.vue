<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Paginator from 'primevue/paginator'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import Skeleton from 'primevue/skeleton'
import FieldForm from '@/components/FieldForm.vue'
import RecordDetails from '@/components/RecordDetails.vue'
import http, { apiError, downloadFile } from '@/api/http'
import { useAuthStore } from '@/stores/auth'
import { resources } from '@/config/resources'
import { selfService } from '@/config/selfService'
import { recordId, recordInScope } from '@/utils/workspace'
import { columnValue, displayLabel, isInternalIdentifier, recordLabel } from '@/utils/records'
import {
  buildPayload,
  formatValue,
  initialValues,
  multipartPayload,
  statusSeverity,
} from '@/utils/forms'

const route = useRoute()
const auth = useAuthStore()
const toast = useToast()
const resource = computed(() => (route.meta.ess ? selfService : resources)[route.params.module])
const rows = ref([])
const total = ref(0)
const page = ref(1)
const perPage = ref(15)
const loading = ref(false)
const error = ref('')
const search = ref('')
const filters = ref({})
const filterOpen = ref(false)
const selected = ref(null)
const detailOpen = ref(false)
const detailLoading = ref(false)
const detailError = ref('')
const relatedRun = ref(null)
const formOpen = ref(false)
const formMode = ref('create')
const action = ref(null)
const draft = ref({})
const saving = ref(false)
const formError = ref('')
const formErrors = ref({})
let active = true
let listVersion = 0
let detailVersion = 0
onBeforeUnmount(() => {
  active = false
  listVersion += 1
  detailVersion += 1
})
const displayRows = computed(() =>
  resource.value.presentRecord
    ? rows.value.map((row) => ({ ...row, ...resource.value.presentRecord(row) }))
    : rows.value,
)
const detailRecord = computed(() =>
  selected.value && resource.value.presentRecord
    ? resource.value.presentRecord(selected.value)
    : selected.value,
)
const visibleRows = computed(() =>
  search.value
    ? displayRows.value.filter((row) =>
        resource.value.columns.some((column) =>
          String(columnValue(row, column) ?? '')
            .toLowerCase()
            .includes(search.value.toLowerCase()),
        ),
      )
    : displayRows.value,
)
const filterFields = computed(() =>
  [
    ...new Set([
      ...resource.value.columns.map((item) => item.key),
      ...resource.value.fields.map((item) => item.key),
    ]),
  ]
    .filter(
      (key) =>
        [
          'employee_id',
          'leave_type_id',
          'payroll_run_id',
          'payroll_period_id',
          'status',
          'is_active',
          'attendance_date',
        ].includes(key) && !(resource.value.ess && key === 'employee_id'),
    )
    .map((key) => ({
      key,
      label: displayLabel(key),
      type:
        key === 'is_active'
          ? 'select'
          : key === 'attendance_date'
            ? 'date'
            : key.endsWith('_id')
              ? 'lookup'
              : 'text',
      options:
        key === 'is_active'
          ? [
              { label: 'Active', value: true },
              { label: 'Inactive', value: false },
            ]
          : undefined,
      nullable: true,
      lookup: key.endsWith('_id') ? relationLookup(key) : undefined,
    })),
)
function relationLookup(key) {
  if (key === 'employee_id' && !auth.can('employees.view') && !auth.can('employees.view-team')) {
    return {
      options: auth.employee ? [auth.employee] : [],
      labelFields: ['employee_number', 'first_name', 'last_name'],
    }
  }
  const existing = resource.value.fields.find((field) => field.key === key)?.lookup
  if (existing) return existing
  const directories = {
    employee_id: {
      endpoint: '/employees',
      labelFields: ['employee_number', 'first_name', 'last_name'],
    },
    leave_type_id: { endpoint: '/leave-types', labelFields: ['name', 'code'] },
    payroll_run_id: { endpoint: '/payroll-runs', labelFields: ['run_number'] },
    payroll_period_id: {
      endpoint: '/payroll-periods',
      labelFields: ['code', 'start_date', 'end_date'],
    },
  }
  return directories[key]
}
const tableColumns = computed(() =>
  resource.value.columns.filter((column) => !['id', 'uuid'].includes(column.key)),
)
const formFields = computed(() =>
  (action.value?.fields || resource.value.fields).map((field) => {
    if (field.type !== 'lookup' || !isInternalIdentifier(field.key)) return field
    const selectedLabel =
      formMode.value === 'edit' && selected.value ? columnValue(selected.value, field.key) : null
    return {
      ...field,
      lookup: {
        ...field.lookup,
        selectedLabel: selectedLabel === 'Unavailable' ? undefined : selectedLabel,
      },
    }
  }),
)
const formTitle = computed(
  () =>
    action.value?.label ||
    `${formMode.value === 'edit' ? 'Edit' : 'Add'} ${resource.value.singular}`,
)
const collectionActions = computed(() =>
  (resource.value.actions || []).filter((item) => item.collection && auth.can(item.permission)),
)
function announcementState(record) {
  if (!record.published_at) return 'draft'
  return Date.parse(record.published_at) > Date.now() ? 'scheduled' : 'published'
}
function canEdit(record) {
  if (!record || !resource.value.updatePermission || !auth.can(resource.value.updatePermission))
    return false
  if (resource.value.key === 'system-settings') return record.is_editable
  if (resource.value.key === 'announcements') return announcementState(record) !== 'published'
  if (resource.value.key === 'approval-delegations')
    return auth.can('employees.view') || record.delegator_user_id === auth.user?.id
  return true
}
function matchesCondition(condition, record) {
  if (condition === 'editable') return record.is_editable !== false
  if (condition === 'active') return record.is_active === true
  if (condition === 'inactive') return record.is_active === false
  if (condition === 'draft' || condition === 'scheduled' || condition === 'published')
    return announcementState(record) === condition
  if (condition === 'editableRun')
    return relatedRun.value && ['draft', 'processing'].includes(relatedRun.value.status)
  if (condition === 'finalizedRun') return relatedRun.value?.status === 'finalized'
  if (condition === 'publishedSlip') return !!record.published_at
  if (condition === 'unpublishedSlip') return !!record.generated_at && !record.published_at
  if (condition === 'ownPublishedSlip')
    return !!record.published_at && record.employee_id === auth.employee?.id
  if (condition === 'unviewedSlip') return !record.viewed_at
  return false
}
function allowedAction(item) {
  const record = selected.value
  if (!record || item.collection || !auth.can(item.permission)) return false
  if (item.statuses && !item.statuses.includes(record.status)) return false
  const conditions = Array.isArray(item.conditions)
    ? item.conditions
    : item.conditions
      ? [item.conditions]
      : []
  return conditions.every((condition) => matchesCondition(condition, record))
}
const detailActions = computed(() => (resource.value.actions || []).filter(allowedAction))
async function loadRecords(reset = false) {
  const version = ++listVersion
  if (reset) page.value = 1
  loading.value = true
  error.value = ''
  try {
    const activeFilters = Object.fromEntries(
      Object.entries(filters.value)
        .filter(([, value]) => value !== '' && value !== null && value !== undefined)
        .map(([key, value]) => [key, typeof value === 'boolean' ? Number(value) : value]),
    )
    const { data } = await http.get(resource.value.endpoint, {
      params: { page: page.value, per_page: perPage.value, ...activeFilters },
    })
    if (active && version === listVersion) {
      rows.value = data.data
      total.value = data.meta?.total ?? rows.value.length
    }
  } catch (failure) {
    if (active && version === listVersion) {
      error.value = apiError(failure).message
      rows.value = []
      total.value = 0
    }
  } finally {
    if (active && version === listVersion) loading.value = false
  }
}
async function openRecord(record) {
  const version = ++detailVersion
  selected.value = null
  relatedRun.value = null
  detailError.value = ''
  detailOpen.value = true
  detailLoading.value = true
  try {
    const result = (
      await http.get(`${resource.value.detailEndpoint || resource.value.endpoint}/${record.id}`)
    ).data.data
    if (!active || version !== detailVersion) return
    if (!recordInScope(result, route.meta.ess === true, auth.employee?.id)) {
      detailError.value = 'This record is outside your personal workspace.'
      return
    }
    selected.value = result
    if (resource.value.key === 'payroll-entries') {
      const run = (await http.get(`/payroll-runs/${selected.value.payroll_run_id}`)).data.data
      if (active && version === detailVersion) relatedRun.value = run
    }
  } catch (failure) {
    if (active && version === detailVersion) detailError.value = apiError(failure).message
  } finally {
    if (active && version === detailVersion) detailLoading.value = false
  }
}
function openForm(mode = 'create', item = null) {
  if (saving.value) return
  action.value = item
  formMode.value = mode
  formError.value = ''
  formErrors.value = {}
  const record = mode === 'edit' && selected.value ? selected.value : {}
  draft.value = initialValues(item?.fields || resource.value.fields, record, mode)
  if (resource.value.key === 'system-settings' && mode === 'edit') {
    draft.value.type = record.type
    if (record.type === 'json' && record.value !== null)
      draft.value.value = JSON.stringify(record.value, null, 2)
  }
  if (
    resource.value.key === 'approval-delegations' &&
    mode === 'create' &&
    !auth.can('employees.view')
  )
    draft.value.delegator_user_id = auth.user.id
  if (item?.key === 'replace-items')
    draft.value.items = (selected.value.items || []).map(
      ({ code, name, type, amount, quantity, rate, remarks }) => ({
        code,
        name,
        type,
        amount,
        quantity,
        rate,
        remarks,
      }),
    )
  formOpen.value = true
}
async function execute(item) {
  if (item.download) {
    try {
      await downloadFile(item.path.replace('{id}', selected.value.id))
      toast.add({ severity: 'success', summary: 'Download started', life: 3500 })
    } catch (failure) {
      detailError.value = apiError(failure).message
    }
    return
  }
  openForm('action', item)
}
async function submit() {
  if (saving.value) return
  const submittedPath = route.fullPath
  const submittedDetailVersion = detailVersion
  saving.value = true
  formError.value = ''
  formErrors.value = {}
  try {
    const mode = formMode.value
    const item = action.value
    const recordId = selected.value?.id
    const payload = buildPayload(formFields.value, draft.value, mode)
    if (payload.file?.size > 10 * 1024 * 1024)
      throw new Error('The document must be 10 MB or smaller.')
    if (item) {
      const path = item.path.replace('{id}', recordId ?? '')
      await http.request({ method: item.method || 'post', url: path, data: payload })
    } else if (resource.value.key === 'employee-documents') {
      const path = `${resource.value.endpoint}${mode === 'edit' ? `/${recordId}` : ''}`
      await http.post(path, multipartPayload(payload, mode))
    } else {
      await http.request({
        method: mode === 'edit' ? 'put' : 'post',
        url: `${resource.value.endpoint}${mode === 'edit' ? `/${recordId}` : ''}`,
        data: payload,
      })
    }
    if (!active || route.fullPath !== submittedPath || detailVersion !== submittedDetailVersion)
      return
    formOpen.value = false
    toast.add({
      severity: 'success',
      summary: item ? 'Action completed' : mode === 'edit' ? 'Record updated' : 'Record created',
      life: 3500,
    })
    await loadRecords()
    if (
      detailOpen.value &&
      recordId &&
      active &&
      route.fullPath === submittedPath &&
      detailVersion === submittedDetailVersion
    )
      await openRecord({ id: recordId })
  } catch (failure) {
    if (active && route.fullPath === submittedPath && detailVersion === submittedDetailVersion) {
      const detail = apiError(failure)
      formError.value = failure.response ? detail.message : failure.message
      formErrors.value = detail.errors
    }
  } finally {
    if (active) saving.value = false
  }
}
function changePage(event) {
  page.value = event.page + 1
  perPage.value = event.rows
  search.value = ''
  loadRecords()
}
function clearFilters() {
  filters.value = {}
  loadRecords(true)
}
onMounted(() => loadRecords())
watch(
  () => route.query.record,
  (value) => {
    detailVersion += 1
    selected.value = null
    relatedRun.value = null
    detailOpen.value = false
    formOpen.value = false
    if (value === undefined) return
    const id = recordId(value)
    if (id) openRecord({ id })
    else {
      detailOpen.value = true
      detailLoading.value = false
      detailError.value = 'The record link is invalid.'
    }
  },
  { immediate: true },
)
watch(detailOpen, (visible) => {
  if (!visible) {
    detailVersion += 1
    detailLoading.value = false
  }
})
</script>

<template>
  <div class="resource-workspace">
    <div class="page-heading">
      <div>
        <span class="eyebrow">{{ resource.ess ? 'SELF-SERVICE' : resource.group }}</span>
        <h1>{{ resource.title }}</h1>
        <p>{{ resource.description }}</p>
      </div>
      <div class="actions">
        <Button
          v-for="item in collectionActions"
          :key="item.key"
          :label="item.label"
          :icon="item.icon || 'pi pi-sliders-h'"
          outlined
          @click="openForm('action', item)"
        /><Button
          v-if="resource.createPermission && auth.can(resource.createPermission)"
          :label="`Add ${resource.singular}`"
          icon="pi pi-plus"
          @click="openForm()"
        />
      </div>
    </div>
    <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>
    <section class="panel record-panel">
      <div class="table-toolbar">
        <div class="search-control">
          <i class="pi pi-search" /><InputText
            v-model="search"
            placeholder="Search this page"
            aria-label="Search records on this page"
          />
        </div>
        <div class="actions">
          <Button
            v-if="filterFields.length"
            label="Filters"
            icon="pi pi-filter"
            severity="secondary"
            outlined
            :aria-expanded="filterOpen"
            @click="filterOpen = !filterOpen"
          /><Button
            label="Refresh"
            icon="pi pi-refresh"
            severity="secondary"
            text
            :loading="loading"
            @click="loadRecords()"
          />
        </div>
      </div>
      <form v-if="filterOpen" class="filter-panel" @submit.prevent="loadRecords(true)">
        <FieldForm v-model="filters" :fields="filterFields" />
        <div class="actions">
          <Button type="submit" label="Apply filters" size="small" /><Button
            type="button"
            label="Clear"
            text
            size="small"
            @click="clearFilters"
          />
        </div>
      </form>
      <DataTable
        :value="visibleRows"
        :loading="loading"
        data-key="id"
        scrollable
        striped-rows
        @row-dblclick="openRecord($event.data)"
        ><Column
          v-for="column in tableColumns"
          :key="column.key"
          :header="column.label"
          :field="column.key"
          :body-class="
            column.type === 'json' ||
            ['description', 'remarks', 'reason', 'notes', 'value'].includes(column.key)
              ? 'table-text-cell'
              : undefined
          "
          ><template #body="{ data }"
            ><Tag
              v-if="column.type === 'status' || column.key === 'status'"
              :value="formatValue(columnValue(data, column))"
              :severity="statusSeverity(data[column.key])"
            /><span
              v-else-if="column.type === 'boolean'"
              class="boolean-cell"
              :class="{ positive: data[column.key] }"
              ><i :class="data[column.key] ? 'pi pi-check-circle' : 'pi pi-minus-circle'" />{{
                formatValue(columnValue(data, column), column.type)
              }}</span
            ><span v-else>{{ formatValue(columnValue(data, column), column.type) }}</span></template
          ></Column
        ><Column header="Details" header-class="table-actions-cell" body-class="table-actions-cell"
          ><template #body="{ data }"
            ><Button
              icon="pi pi-arrow-up-right"
              label="View"
              text
              size="small"
              :aria-label="`View ${recordLabel(data, resource.singular)}`"
              @click="openRecord(data)" /></template></Column
        ><template #empty
          ><div class="empty-state">
            <span class="empty-icon"><i :class="resource.icon || 'pi pi-folder-open'" /></span
            ><b>{{ search ? 'No matches on this page' : 'No records yet' }}</b>
            <p>
              {{
                search
                  ? 'Try another search or move to a different page.'
                  : 'Records will appear here when they are available.'
              }}
            </p>
          </div></template
        ></DataTable
      >
      <div class="table-footer">
        <span
          >{{ total }} records
          <template v-if="search">· {{ visibleRows.length }} matches on this page</template></span
        ><Paginator
          :first="(page - 1) * perPage"
          :rows="perPage"
          :total-records="total"
          :rows-per-page-options="[15, 25, 50, 100]"
          @page="changePage"
        />
      </div>
    </section>
    <div v-if="resource.ess && resource.key === 'leave-balances'" class="actions">
      <RouterLink to="/my/leave-credit-transactions"
        ><Button label="View leave credit history" text icon="pi pi-history"
      /></RouterLink>
    </div>
    <div v-if="resource.ess && resource.key === 'cto-balances'" class="actions">
      <RouterLink to="/my/cto-credit-transactions"
        ><Button label="View CTO credit history" text icon="pi pi-history"
      /></RouterLink>
    </div>
    <Dialog
      v-model:visible="detailOpen"
      :header="`${resource.singular} details`"
      modal
      :closable="!formOpen && !saving"
      :close-on-escape="!formOpen && !saving"
      :style="{ width: '58rem' }"
      :breakpoints="{ '960px': '94vw' }"
      ><Skeleton v-if="detailLoading" height="15rem" /><Message
        v-if="detailError"
        severity="error"
        :closable="false"
        >{{ detailError }}</Message
      ><template v-if="selected && !detailLoading"
        ><div class="detail-actions">
          <Button
            v-if="canEdit(selected)"
            label="Edit record"
            icon="pi pi-pencil"
            outlined
            @click="openForm('edit')"
          /><Button
            v-for="item in detailActions"
            :key="item.key"
            :label="item.label"
            :icon="item.icon"
            :severity="item.severity || 'secondary'"
            outlined
            @click="execute(item)"
          />
        </div>
        <RecordDetails :record="detailRecord" :columns="resource.columns" />
        <section v-if="selected.items" class="detail-items">
          <h3>Payroll items</h3>
          <DataTable :value="selected.items"
            ><Column field="code" header="Code" /><Column field="name" header="Name" /><Column
              field="type"
              header="Type" /><Column field="amount" header="Amount"
          /></DataTable></section></template
    ></Dialog>
    <Dialog
      v-model:visible="formOpen"
      :header="formTitle"
      modal
      :closable="!saving"
      :close-on-escape="!saving"
      :style="{ width: '48rem' }"
      :breakpoints="{ '800px': '94vw' }"
      ><form class="form-stack" @submit.prevent="submit">
        <p v-if="action?.description" class="muted">{{ action.description }}</p>
        <FieldForm
          v-model="draft"
          :fields="formFields"
          :mode="formMode"
          :errors="formErrors"
          :disabled="saving"
        /><Message v-if="formError" severity="error" :closable="false">{{ formError }}</Message>
        <div class="dialog-actions">
          <Button
            type="button"
            label="Cancel"
            outlined
            severity="secondary"
            :disabled="saving"
            @click="formOpen = false"
          /><Button type="submit" :label="action ? 'Confirm' : 'Save record'" :loading="saving" />
        </div></form
    ></Dialog>
  </div>
</template>
