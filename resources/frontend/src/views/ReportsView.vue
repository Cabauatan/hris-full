<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import Button from 'primevue/button'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Paginator from 'primevue/paginator'
import Select from 'primevue/select'
import Skeleton from 'primevue/skeleton'
import Tag from 'primevue/tag'
import LookupPicker from '@/components/LookupPicker.vue'
import http, { apiError, downloadFile } from '@/api/http'
import { useAuthStore } from '@/stores/auth'
import { formatValue, labelFor, statusSeverity } from '@/utils/forms'
import { reportDraft, reportFilters } from '@/utils/reports'
import { columnValue, isInternalIdentifier, lookupLabel } from '@/utils/records'

const auth = useAuthStore()
const catalog = ref(null)
const selectedKey = ref('')
const preview = ref(null)
const draft = ref({})
const catalogLoading = ref(true)
const loading = ref(false)
const exporting = ref(false)
const error = ref('')
const exportError = ref('')
const exportSuccess = ref('')
const fieldErrors = ref({})
const selectionLabels = ref({ employee: {}, payroll: {} })
let active = true
let catalogVersion = 0
let previewVersion = 0
let exportVersion = 0
let catalogController
let previewController
let exportController

const reports = computed(() => catalog.value?.reports || [])
const selectedReport = computed(() =>
  reports.value.find((report) => report.key === selectedKey.value),
)
const reportColumns = computed(() =>
  (preview.value?.report?.columns || []).filter((column) => !isInternalIdentifier(column.key)),
)
const ownEmployeeLabel = computed(() =>
  auth.employee ? lookupLabel(auth.employee, ['employee_number', 'first_name', 'last_name']) : '',
)
const employeeField = computed(() => {
  const ownOnly = draft.value.scope === 'mine'
  const canReadDirectory = auth.canAny?.(['employees.view', 'employees.view-team']) || false
  return {
    key: 'employee_id',
    label: 'Employee',
    nullable: true,
    lookup: {
      ...(canReadDirectory && !ownOnly
        ? { endpoint: '/employees' }
        : { options: auth.employee ? [auth.employee] : [] }),
      labelFields: ['employee_number', 'first_name', 'last_name'],
      placeholder: ownOnly ? 'My employee profile' : 'All accessible employees',
      selectedLabel:
        selectionLabels.value.employee[String(draft.value.employee_id)] ||
        (String(draft.value.employee_id) === String(auth.employee?.id)
          ? ownEmployeeLabel.value
          : ''),
    },
  }
})
const payrollField = computed(() => ({
  key: 'payroll_run_id',
  label: 'Payroll run',
  nullable: true,
  lookup: {
    ...(auth.can?.('payroll.view') ? { endpoint: '/payroll-runs' } : { options: [] }),
    labelFields: ['run_number'],
    placeholder: 'All accessible payroll runs',
    selectedLabel: selectionLabels.value.payroll[String(draft.value.payroll_run_id)] || '',
  },
}))
const scopeOptions = computed(() =>
  (preview.value?.available_scopes || catalog.value?.available_scopes || []).map((value) => ({
    value,
    label: value === 'mine' ? 'My records' : 'Accessible records',
  })),
)
const statusOptions = computed(() =>
  (selectedReport.value?.statuses || []).map((value) => ({ value, label: labelFor(value) })),
)
const hasDraftChanges = computed(
  () =>
    preview.value &&
    JSON.stringify(reportFilters(selectedReport.value, draft.value)) !==
      JSON.stringify(reportFilters(preview.value.report, preview.value.filters)),
)
const exportLimit = computed(
  () => preview.value?.export?.max_rows || catalog.value?.export?.max_rows || 10000,
)
const canExport = computed(
  () =>
    Boolean(preview.value?.report?.can_export && preview.value?.export?.available) &&
    !loading.value &&
    !exporting.value,
)
const appliedLabel = computed(() => {
  if (!preview.value) return ''
  const filters = preview.value.filters
  const labels = [filters.scope === 'mine' ? 'My records' : 'Accessible records']
  if (filters.start && filters.end) labels.push(`${filters.start} – ${filters.end}`)
  if (filters.year) labels.push(`Year ${filters.year}`)
  if (filters.status) labels.push(labelFor(filters.status))
  if (filters.employee_id)
    labels.push(
      selectionLabels.value.employee[String(filters.employee_id)] ||
        (String(filters.employee_id) === String(auth.employee?.id)
          ? ownEmployeeLabel.value
          : 'Selected employee'),
    )
  if (filters.payroll_run_id)
    labels.push(
      selectionLabels.value.payroll[String(filters.payroll_run_id)] || 'Selected payroll run',
    )
  return labels.join(' · ')
})

function rememberSelection(kind, selection) {
  if (selection?.value === null || selection?.value === undefined || !selection.label) return
  selectionLabels.value[kind][String(selection.value)] = selection.label
}

function accountKey() {
  return auth.authenticated ? String(auth.user?.id || '') : ''
}
function sameAccount(account) {
  return active && account !== '' && account === accountKey()
}
function failureMessage(failure) {
  const detail = apiError(failure)
  return Object.values(detail.errors).flat()[0] || detail.message
}
function fieldError(key) {
  const messages = fieldErrors.value[key]
  return Array.isArray(messages) ? messages[0] : messages || ''
}
function cancelExport() {
  exportVersion += 1
  exportController?.abort()
  exporting.value = false
}
function clearState() {
  catalogVersion += 1
  previewVersion += 1
  catalogController?.abort()
  previewController?.abort()
  cancelExport()
  catalog.value = null
  selectedKey.value = ''
  preview.value = null
  draft.value = {}
  catalogLoading.value = false
  loading.value = false
  error.value = ''
  exportError.value = ''
  exportSuccess.value = ''
  fieldErrors.value = {}
  selectionLabels.value = { employee: {}, payroll: {} }
}
async function loadCatalog() {
  const account = accountKey()
  if (!sameAccount(account)) return
  const version = ++catalogVersion
  catalogController?.abort()
  catalogController = new AbortController()
  catalogLoading.value = true
  error.value = ''
  try {
    const { data } = await http.get('/reports', { signal: catalogController.signal })
    if (!sameAccount(account) || version !== catalogVersion) return
    catalog.value = data.data
    selectedKey.value = reports.value[0]?.key || ''
    if (selectedReport.value) await changeReport()
  } catch (failure) {
    if (!sameAccount(account) || version !== catalogVersion || failure.code === 'ERR_CANCELED')
      return
    error.value = failureMessage(failure)
  } finally {
    if (sameAccount(account) && version === catalogVersion) catalogLoading.value = false
  }
}
async function changeReport() {
  previewVersion += 1
  previewController?.abort()
  cancelExport()
  preview.value = null
  exportError.value = ''
  exportSuccess.value = ''
  draft.value = reportDraft(selectedReport.value, catalog.value?.default_filters)
  if (selectedReport.value) await loadPreview(reportFilters(selectedReport.value, draft.value))
}
async function loadPreview(filters, page = 1, perPage = 20, updateDraft = true) {
  const report = selectedReport.value
  const account = accountKey()
  if (!report || !sameAccount(account)) return
  const version = ++previewVersion
  previewController?.abort()
  previewController = new AbortController()
  cancelExport()
  loading.value = true
  error.value = ''
  exportError.value = ''
  exportSuccess.value = ''
  fieldErrors.value = {}
  try {
    const { data } = await http.get(`/reports/${encodeURIComponent(report.key)}`, {
      params: { ...reportFilters(report, filters), page, per_page: perPage },
      signal: previewController.signal,
    })
    if (!sameAccount(account) || version !== previewVersion || selectedKey.value !== report.key)
      return
    preview.value = data.data
    if (updateDraft) draft.value = reportDraft(data.data.report, data.data.filters)
  } catch (failure) {
    if (!sameAccount(account) || version !== previewVersion || failure.code === 'ERR_CANCELED')
      return
    fieldErrors.value = apiError(failure).errors
    error.value = failureMessage(failure)
  } finally {
    if (sameAccount(account) && version === previewVersion) loading.value = false
  }
}
function applyFilters() {
  return loadPreview(reportFilters(selectedReport.value, draft.value))
}
function resetFilters() {
  draft.value = reportDraft(selectedReport.value, catalog.value?.default_filters)
  return applyFilters()
}
function changePage(event) {
  if (!preview.value || loading.value) return
  return loadPreview(preview.value.filters, event.page + 1, event.rows, false)
}
function refreshPreview() {
  if (!preview.value) return applyFilters()
  return loadPreview(
    preview.value.filters,
    preview.value.pagination.current_page,
    preview.value.pagination.per_page,
    false,
  )
}
async function exportReport() {
  if (!canExport.value) return
  const account = accountKey()
  const report = preview.value.report
  const version = ++exportVersion
  exportController?.abort()
  exportController = new AbortController()
  const signal = exportController.signal
  exporting.value = true
  exportError.value = ''
  exportSuccess.value = ''
  const current = () =>
    sameAccount(account) && version === exportVersion && selectedKey.value === report.key
  try {
    const downloaded = await downloadFile(
      `/reports/${encodeURIComponent(report.key)}/export`,
      reportFilters(report, preview.value.filters),
      {
        signal,
        shouldDownload: current,
      },
    )
    if (current() && downloaded !== false) exportSuccess.value = 'CSV download started.'
  } catch (failure) {
    if (current() && failure.code !== 'ERR_CANCELED') exportError.value = failureMessage(failure)
  } finally {
    if (current()) exporting.value = false
  }
}

watch(
  accountKey,
  () => {
    clearState()
    if (accountKey()) loadCatalog()
  },
  { flush: 'sync' },
)
onMounted(() => loadCatalog())
onBeforeUnmount(() => {
  active = false
  clearState()
})
</script>

<template>
  <div class="reports-page">
    <header class="page-heading">
      <div>
        <span class="eyebrow">HR RECORDS</span>
        <h1>Reports & exports</h1>
        <p>
          Review workforce, attendance, requests, and payroll records available to your account.
        </p>
      </div>
      <Button
        v-if="preview"
        label="Export CSV"
        icon="pi pi-download"
        :disabled="!canExport"
        :loading="exporting"
        @click="exportReport"
      />
    </header>
    <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>
    <Message v-if="exportError" severity="error" :closable="false">{{ exportError }}</Message>
    <Message v-if="exportSuccess" severity="success" :closable="false">{{ exportSuccess }}</Message>
    <Skeleton v-if="catalogLoading && !catalog" height="17rem" />
    <section v-else-if="!catalog && error" class="panel report-unavailable">
      <Button label="Retry loading reports" icon="pi pi-refresh" outlined @click="loadCatalog" />
    </section>
    <template v-if="catalog && selectedReport">
      <form class="panel report-filters" @submit.prevent="applyFilters">
        <div class="report-filter-heading">
          <div>
            <h2>Choose your report</h2>
            <p>{{ selectedReport.description }}</p>
          </div>
          <span v-if="selectedReport.date_basis" class="muted"
            >Dates use {{ labelFor(selectedReport.date_basis).toLowerCase() }}.</span
          >
          <span v-else class="muted">Current snapshot</span>
        </div>
        <div class="report-filter-grid">
          <div class="field-label report-choice">
            <label id="report-choice-label" for="report-choice">Report</label>
            <Select
              input-id="report-choice"
              v-model="selectedKey"
              :options="reports"
              option-label="label"
              option-value="key"
              aria-labelledby="report-choice-label"
              @change="changeReport"
            />
          </div>
          <div class="field-label">
            <label id="report-scope-label" for="report-scope">Records</label>
            <Select
              input-id="report-scope"
              v-model="draft.scope"
              :options="scopeOptions"
              option-label="label"
              option-value="value"
              aria-labelledby="report-scope-label"
              :disabled="loading"
            />
            <small v-if="fieldError('scope')" class="field-error">{{ fieldError('scope') }}</small>
          </div>
          <label v-if="selectedReport.date_basis" class="field-label" for="report-start"
            >From
            <InputText
              id="report-start"
              v-model="draft.start"
              type="date"
              required
              :disabled="loading"
            />
            <small v-if="fieldError('start')" class="field-error">{{ fieldError('start') }}</small>
          </label>
          <label v-if="selectedReport.date_basis" class="field-label" for="report-end"
            >Until
            <InputText
              id="report-end"
              v-model="draft.end"
              type="date"
              required
              :min="draft.start"
              :disabled="loading"
            />
            <small v-if="fieldError('end')" class="field-error">{{ fieldError('end') }}</small>
          </label>
          <label class="field-label" for="report-employee"
            >Employee (optional)
            <LookupPicker
              input-id="report-employee"
              v-model="draft.employee_id"
              :field="employeeField"
              :disabled="loading"
              @selection-change="rememberSelection('employee', $event)"
            />
            <small v-if="fieldError('employee_id')" class="field-error">{{
              fieldError('employee_id')
            }}</small>
          </label>
          <div v-if="statusOptions.length" class="field-label">
            <label id="report-status-label" for="report-status">Status</label>
            <Select
              input-id="report-status"
              v-model="draft.status"
              :options="statusOptions"
              option-label="label"
              option-value="value"
              show-clear
              placeholder="All statuses"
              aria-labelledby="report-status-label"
              :disabled="loading"
            />
            <small v-if="fieldError('status')" class="field-error">{{
              fieldError('status')
            }}</small>
          </div>
          <label v-if="selectedReport.year_filter" class="field-label" for="report-year"
            >Year
            <InputText
              id="report-year"
              v-model="draft.year"
              type="number"
              min="1"
              step="1"
              required
              :disabled="loading"
            />
            <small v-if="fieldError('year')" class="field-error">{{ fieldError('year') }}</small>
          </label>
          <label v-if="selectedReport.key === 'payroll'" class="field-label" for="report-run"
            >Payroll run (optional)
            <LookupPicker
              input-id="report-run"
              v-model="draft.payroll_run_id"
              :field="payrollField"
              :disabled="loading"
              @selection-change="rememberSelection('payroll', $event)"
            />
            <small v-if="fieldError('payroll_run_id')" class="field-error">{{
              fieldError('payroll_run_id')
            }}</small>
          </label>
        </div>
        <div class="report-filter-actions">
          <span class="muted">Accessible records follow your current HRIS permissions.</span>
          <div class="actions">
            <Button
              label="Reset filters"
              type="button"
              text
              severity="secondary"
              :disabled="loading"
              @click="resetFilters"
            />
            <Button label="Apply filters" type="submit" icon="pi pi-filter" :loading="loading" />
          </div>
        </div>
      </form>
      <Message v-if="hasDraftChanges" severity="info" :closable="false"
        >Apply your changes to update the preview. CSV export uses the filters shown below.</Message
      >
      <Skeleton v-if="loading && !preview" height="18rem" />
      <template v-if="preview">
        <section class="report-summary" aria-label="Report summary">
          <article class="panel report-metric">
            <span>Matching records</span
            ><strong>{{ formatValue(preview.summary.records, 'integer') }}</strong>
          </article>
          <article
            v-for="metric in preview.summary.metrics"
            :key="metric.label"
            class="panel report-metric"
          >
            <span>{{ metric.label }}</span
            ><strong>{{ formatValue(metric.value, metric.type) }}</strong>
          </article>
        </section>
        <section class="panel record-panel report-preview" :aria-busy="loading">
          <div class="report-preview-heading">
            <div>
              <h2>{{ preview.report.label }}</h2>
              <p>{{ appliedLabel }}</p>
            </div>
            <Button
              label="Refresh preview"
              icon="pi pi-refresh"
              text
              severity="secondary"
              :disabled="loading"
              @click="refreshPreview"
            />
          </div>
          <DataTable
            :value="preview.rows"
            :loading="loading"
            scrollable
            striped-rows
            table-style="min-width: 45rem"
          >
            <Column
              v-for="column in reportColumns"
              :key="column.key"
              :field="column.key"
              :header="column.label"
            >
              <template #body="{ data }"
                ><Tag
                  v-if="['status', 'entry_status'].includes(column.key)"
                  :value="labelFor(String(data[column.key] || 'Unknown'))"
                  :severity="statusSeverity(data[column.key])"
                /><span v-else>{{
                  formatValue(columnValue(data, column), column.type)
                }}</span></template
              >
            </Column>
            <template #empty
              ><div class="empty-state">
                <span class="empty-icon"><i class="pi pi-file" /></span><b>No matching records</b>
                <p>Try another date range or clear a filter.</p>
              </div></template
            >
          </DataTable>
          <div class="table-footer">
            <span
              >{{ preview.pagination.total }} matching records · Page
              {{ preview.pagination.current_page }} of {{ preview.pagination.last_page }}</span
            ><Paginator
              :first="(preview.pagination.current_page - 1) * preview.pagination.per_page"
              :rows="preview.pagination.per_page"
              :total-records="preview.pagination.total"
              :rows-per-page-options="[20, 50, 100]"
              :disabled="loading"
              @page="changePage"
            />
          </div>
        </section>
        <footer class="report-export-note">
          <p>
            CSV includes all matching records with the applied filters, up to
            {{ exportLimit.toLocaleString('en-PH') }} records.
          </p>
          <p v-if="!preview.export.available" class="export-limit-message">
            Narrow your filters to {{ exportLimit.toLocaleString('en-PH') }} records or fewer to
            export.
          </p>
          <p class="muted">Generated {{ formatValue(preview.generated_at, 'datetime') }}</p>
        </footer>
      </template>
    </template>
    <section v-else-if="catalog && !reports.length" class="panel report-unavailable empty-state">
      <span class="empty-icon"><i class="pi pi-file" /></span><b>No reports available</b>
      <p>Your account does not currently have access to a report.</p>
    </section>
  </div>
</template>

<style scoped>
.reports-page {
  min-width: 0;
}
.reports-page > .p-message {
  margin-bottom: 18px;
}
.report-filters {
  padding: 23px;
  margin-bottom: 22px;
}
.report-filter-heading,
.report-preview-heading,
.report-filter-actions {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
}
.report-filter-heading {
  margin-bottom: 22px;
  align-items: flex-start;
}
.report-filter-heading h2,
.report-preview-heading h2 {
  margin: 0 0 6px;
  font-size: 16px;
}
.report-filter-heading p,
.report-preview-heading p,
.report-export-note p {
  margin: 0;
  color: var(--muted);
  font-size: 11px;
  line-height: 1.7;
}
.report-filter-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 19px 18px;
}
.report-choice {
  grid-column: span 2;
}
.report-filter-actions {
  margin-top: 22px;
}
.report-summary {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(175px, 1fr));
  gap: 15px;
  margin-bottom: 22px;
}
.report-metric {
  padding: 18px 20px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  min-width: 0;
}
.report-metric span {
  color: var(--muted);
  font-size: 11px;
}
.report-metric strong {
  font-size: 24px;
  font-weight: 650;
  overflow-wrap: anywhere;
}
.report-preview {
  min-width: 0;
}
.report-preview :deep(.p-datatable-tbody > tr > td) {
  max-width: none;
}
.report-preview-heading {
  padding: 20px 23px;
}
.report-export-note {
  margin-top: 18px;
  display: grid;
  gap: 5px;
}
.report-export-note .export-limit-message {
  color: var(--accent);
  font-weight: 600;
}
.report-unavailable {
  padding: 28px;
}
@media (max-width: 1000px) {
  .report-filter-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
@media (max-width: 650px) {
  .report-filters {
    padding: 19px;
  }
  .report-filter-heading,
  .report-filter-actions,
  .report-preview-heading {
    flex-direction: column;
    align-items: stretch;
    gap: 14px;
  }
  .report-filter-grid {
    grid-template-columns: 1fr;
  }
  .report-choice {
    grid-column: auto;
  }
  .report-filter-actions > .actions {
    justify-content: space-between;
  }
  .report-summary {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
  }
  .report-metric {
    padding: 15px;
  }
  .report-metric strong {
    font-size: 21px;
  }
}
</style>
