<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Message from 'primevue/message'
import Skeleton from 'primevue/skeleton'
import Tag from 'primevue/tag'
import http, { apiError } from '@/api/http'
import { useAuthStore } from '@/stores/auth'
import { canViewCalendar } from '@/config/workspace'
import { workflows } from '@/config/workflows'
import { formatValue, labelFor, statusSeverity } from '@/utils/forms'
import { requestTotals, sourceLink } from '@/utils/workspace'

const auth = useAuthStore()
const summary = ref(null)
const loading = ref(true)
const error = ref('')
const filters = ref({ start: '', end: '', scope: '' })
const availableScopes = ref([])
let controller
let version = 0
let active = true
const totals = computed(() => requestTotals(summary.value?.requests))
const canCalendar = computed(() => canViewCalendar(auth))
const scopeOptions = computed(() =>
  availableScopes.value.map((value) => ({
    value,
    label: value === 'mine' ? 'My records' : 'Accessible records',
  })),
)
const scopeLabel = computed(() =>
  summary.value?.scope === 'mine' ? 'My records' : 'Accessible records',
)
const statusRows = computed(() =>
  Object.entries(summary.value?.attendance?.by_status ?? {}).sort((a, b) => b[1] - a[1]),
)
const recentDays = computed(() => (summary.value?.attendance?.by_day ?? []).slice(-31))
const peakDay = computed(() => Math.max(1, ...recentDays.value.map((day) => day.total)))
const hasSections = computed(
  () =>
    summary.value &&
    (summary.value.workforce ||
      summary.value.attendance ||
      summary.value.requests.length ||
      summary.value.approvals.by_type.length),
)
const name = computed(() => auth.employee?.first_name || auth.user?.name?.split(' ')[0] || 'there')

function requestPath(module) {
  if (!workflows[module]) return '/home'
  return `${summary.value?.scope === 'mine' && auth.employee ? '/my/requests' : '/requests'}/${module}`
}
function approvalLink(item) {
  return sourceLink({ module: item.module, id: item.id }, auth, summary.value?.scope)
}
async function load(apply = false) {
  controller?.abort()
  controller = new AbortController()
  const current = ++version
  error.value = ''
  summary.value = null
  loading.value = true
  try {
    const { data } = await http.get('/dashboard', {
      signal: controller.signal,
      params: apply ? { ...filters.value } : {},
    })
    if (!active || current !== version) return
    summary.value = data.data
    availableScopes.value = data.data.available_scopes
    filters.value = {
      start: data.data.range.start,
      end: data.data.range.end,
      scope: data.data.scope,
    }
  } catch (failure) {
    if (!active || current !== version || failure.code === 'ERR_CANCELED') return
    const problem = apiError(failure)
    error.value = Object.values(problem.errors ?? {}).flat()[0] || problem.message
  } finally {
    if (active && current === version) loading.value = false
  }
}
onMounted(() => load())
onBeforeUnmount(() => {
  active = false
  version += 1
  controller?.abort()
})
</script>

<template>
  <div class="dashboard-page">
    <header class="page-heading">
      <div>
        <h1>Dashboard</h1>
        <p>Attendance, requests, and your workday.</p>
      </div>
      <div class="actions">
        <RouterLink v-if="canCalendar" to="/calendar"
          ><Button label="Open calendar" icon="pi pi-calendar" outlined
        /></RouterLink>
        <RouterLink to="/home"
          ><Button label="Self-service home" icon="pi pi-arrow-right" text
        /></RouterLink>
      </div>
    </header>

    <Message v-if="error" severity="error" :closable="false"
      >{{ error }}
      <Button
        label="Try again"
        text
        size="small"
        @click="load(Boolean(filters.start && filters.end))"
      />
    </Message>
    <div v-if="loading" class="dashboard-stats" aria-label="Loading dashboard" aria-busy="true">
      <Skeleton v-for="item in 4" :key="item" height="8rem" />
    </div>

    <template v-else-if="summary">
      <section class="dashboard-welcome" aria-label="Workspace summary">
        <div class="dashboard-welcome-copy">
          <span class="dashboard-welcome-label">YOUR HRIS WORKSPACE</span>
          <h2>Welcome back, {{ name }}!</h2>
          <p>Keep your workday and your priorities in view.</p>
          <span class="dashboard-welcome-scope"
            ><i class="pi pi-briefcase" aria-hidden="true" /> {{ scopeLabel }}</span
          >
        </div>
        <div
          v-if="summary.workforce || summary.approvals.by_type.length"
          class="dashboard-welcome-metrics"
        >
          <div v-if="summary.workforce">
            <span>Employees in view</span>
            <strong>{{ summary.workforce.total }}</strong>
            <small>Current headcount</small>
          </div>
          <div v-if="summary.approvals.by_type.length">
            <span>Awaiting your review</span>
            <strong>{{ summary.approvals.total }}</strong>
            <small>Your approval authority · all dates</small>
          </div>
        </div>
        <i v-else class="pi pi-calendar dashboard-welcome-symbol" aria-hidden="true" />
      </section>
      <div class="dashboard-context">
        <span
          ><i class="pi pi-filter" /> {{ scopeLabel }} · {{ summary.range.start }} —
          {{ summary.range.end }}</span
        >
        <span
          >{{ summary.range.timezone }} · Refreshed
          {{ formatValue(summary.generated_at, 'datetime') }}</span
        >
      </div>
      <div class="dashboard-stats">
        <article v-if="summary.workforce" class="panel dashboard-stat">
          <div class="dashboard-stat-heading">
            <span>Accessible employees</span>
            <span class="dashboard-stat-icon"><i class="pi pi-users" aria-hidden="true" /></span>
          </div>
          <strong>{{ summary.workforce.total }}</strong>
          <small
            >{{ summary.workforce.active }} active · {{ summary.workforce.inactive }} inactive ·
            current headcount</small
          >
        </article>
        <article v-if="summary.attendance" class="panel dashboard-stat">
          <div class="dashboard-stat-heading">
            <span>Processed attendance</span>
            <span class="dashboard-stat-icon"><i class="pi pi-clock" aria-hidden="true" /></span>
          </div>
          <strong>{{ summary.attendance.total }}</strong>
          <small>Recorded employee-days in this period</small>
        </article>
        <article v-if="summary.requests.length" class="panel dashboard-stat">
          <div class="dashboard-stat-heading">
            <span>Pending requests</span>
            <span class="dashboard-stat-icon"><i class="pi pi-inbox" aria-hidden="true" /></span>
          </div>
          <strong>{{ totals.pending }}</strong>
          <small>{{ totals.total }} requests in this period</small>
        </article>
        <article v-if="summary.approvals.by_type.length" class="panel dashboard-stat">
          <div class="dashboard-stat-heading">
            <span>Awaiting your review</span>
            <span class="dashboard-stat-icon"
              ><i class="pi pi-check-circle" aria-hidden="true"
            /></span>
          </div>
          <strong>{{ summary.approvals.total }}</strong>
          <small>Your current approval authority · all dates</small>
        </article>
      </div>
    </template>

    <form
      v-if="availableScopes.length"
      class="panel dashboard-filters"
      @submit.prevent="load(true)"
    >
      <label class="field-label" for="dashboard-start"
        >From
        <InputText
          id="dashboard-start"
          v-model="filters.start"
          type="date"
          required
          :disabled="loading"
        />
      </label>
      <label class="field-label" for="dashboard-end"
        >Until
        <InputText
          id="dashboard-end"
          v-model="filters.end"
          type="date"
          required
          :min="filters.start"
          :disabled="loading"
        />
      </label>
      <div class="field-label">
        <label id="dashboard-scope-label" for="dashboard-scope">Workspace</label>
        <Select
          input-id="dashboard-scope"
          aria-labelledby="dashboard-scope-label"
          v-model="filters.scope"
          :options="scopeOptions"
          option-label="label"
          option-value="value"
          :disabled="loading || scopeOptions.length === 1"
        />
      </div>
      <Button type="submit" label="Apply filters" icon="pi pi-filter" :loading="loading" />
      <Button label="This month" icon="pi pi-refresh" text :disabled="loading" @click="load()" />
    </form>

    <template v-if="!loading && summary">
      <div class="dashboard-analysis">
        <section v-if="summary.attendance" class="panel dashboard-trend">
          <div class="dashboard-section-heading">
            <div>
              <span class="eyebrow">DAILY ACTIVITY</span>
              <h2>Attendance processing</h2>
              <p class="muted">
                {{
                  recentDays.length > 0
                    ? 'Most recent recorded dates in this period, up to 31 days.'
                    : 'Processed records per date.'
                }}
              </p>
            </div>
            <span class="dashboard-chart-legend"
              ><span aria-hidden="true"></span> Processed records</span
            >
          </div>
          <figure v-if="recentDays.length" class="dashboard-chart">
            <div
              class="dashboard-bars"
              role="img"
              :aria-label="recentDays.map((day) => `${day.date}: ${day.total} records`).join('; ')"
            >
              <div
                v-for="day in recentDays"
                :key="day.date"
                class="dashboard-bar-track"
                :title="`${day.date}: ${day.total} records`"
              >
                <div :style="{ height: `${(day.total / peakDay) * 100}%` }"></div>
              </div>
            </div>
            <figcaption>
              <span>{{ recentDays[0].date }}</span
              ><span>{{ recentDays.at(-1).date }}</span>
            </figcaption>
          </figure>
          <div v-else class="empty-state">
            <i class="pi pi-chart-bar" />
            <p>Daily activity appears after attendance is processed.</p>
          </div>
        </section>
        <section v-if="summary.attendance" class="panel dashboard-attendance">
          <div class="dashboard-section-heading">
            <div>
              <span class="eyebrow">ATTENDANCE</span>
              <h2>Recorded workdays</h2>
              <p class="muted">Status of processed attendance in this period.</p>
            </div>
            <Tag :value="`${summary.attendance.total} records`" severity="secondary" />
          </div>
          <div v-if="statusRows.length" class="dashboard-status-list">
            <div v-for="[status, count] in statusRows" :key="status" class="dashboard-status-row">
              <div>
                <Tag
                  :value="labelFor(status || 'unknown')"
                  :severity="statusSeverity(status)"
                /><strong>{{ count }}</strong>
              </div>
              <progress
                :value="count"
                :max="summary.attendance.total || 1"
                :aria-label="`${labelFor(status || 'unknown')}: ${count} of ${summary.attendance.total} records`"
              ></progress>
            </div>
          </div>
          <p v-else class="muted dashboard-inline-empty">
            No processed attendance for this date range.
          </p>
          <div class="dashboard-minute-grid">
            <div>
              <strong>{{ summary.attendance.late_minutes }}</strong
              ><span>Late minutes</span>
            </div>
            <div>
              <strong>{{ summary.attendance.undertime_minutes }}</strong
              ><span>Undertime minutes</span>
            </div>
            <div>
              <strong>{{ summary.attendance.overtime_minutes }}</strong
              ><span>Overtime minutes</span>
            </div>
          </div>
          <p class="dashboard-footnote">
            Employees without a processed record are excluded from these counts.
          </p>
        </section>
      </div>

      <section v-if="summary.approvals.by_type.length" class="panel dashboard-approvals">
        <div class="dashboard-section-heading">
          <div>
            <span class="eyebrow">NEXT ACTION</span>
            <h2>Approval queue</h2>
            <p class="muted">Pending requests you can review now, across all dates.</p>
          </div>
          <Tag
            :value="`${summary.approvals.total} pending`"
            :severity="summary.approvals.total ? 'warn' : 'success'"
          />
        </div>
        <div class="dashboard-approval-types">
          <RouterLink
            v-for="type in summary.approvals.by_type"
            :key="type.type"
            :to="requestPath(type.module)"
            class="dashboard-queue-type"
          >
            {{ type.label }} <strong>{{ type.total }}</strong>
          </RouterLink>
        </div>
        <div v-if="summary.approvals.items.length" class="dashboard-queue-list">
          <article
            v-for="item in summary.approvals.items"
            :key="`${item.type}:${item.id}`"
            class="dashboard-queue-row"
          >
            <span class="dashboard-avatar">{{ item.employee?.name?.charAt(0) || '?' }}</span>
            <div class="dashboard-queue-person">
              <strong>{{ item.employee?.name || 'Employee' }}</strong
              ><span>{{ item.employee?.employee_number }} · {{ item.label }}</span>
            </div>
            <span class="dashboard-queue-date"
              >{{ formatValue(item.start, 'datetime')
              }}<template v-if="item.end && item.end !== item.start">
                — {{ formatValue(item.end, 'datetime') }}</template
              ></span
            >
            <RouterLink v-if="approvalLink(item)" :to="approvalLink(item)"
              ><Button label="Review" icon="pi pi-arrow-right" text size="small"
            /></RouterLink>
          </article>
          <p
            v-if="summary.approvals.total > summary.approvals.items.length"
            class="muted dashboard-preview-note"
          >
            Showing the {{ summary.approvals.items.length }} oldest requests. Open a request type to
            see the full queue.
          </p>
        </div>
        <div v-else class="empty-state">
          <i class="pi pi-check-circle" />
          <h3>You're up to date</h3>
          <p>No pending requests under your current approval authority.</p>
        </div>
      </section>

      <section v-if="summary.requests.length" class="panel dashboard-request-panel">
        <div class="dashboard-section-heading">
          <div>
            <span class="eyebrow">REQUEST ACTIVITY</span>
            <h2>Requests by type</h2>
            <p class="muted">
              Requests covering the selected period. Profile changes use their submission date.
            </p>
          </div>
          <Tag :value="`${totals.total} total`" severity="secondary" />
        </div>
        <div class="dashboard-table-scroll">
          <table class="dashboard-table">
            <thead>
              <tr>
                <th scope="col">Request type</th>
                <th scope="col">Total</th>
                <th scope="col">Pending</th>
                <th scope="col">Approved / applied</th>
                <th scope="col">Rejected</th>
                <th scope="col">Cancelled</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="request in summary.requests" :key="request.type">
                <th scope="row">
                  <RouterLink :to="requestPath(request.module)"
                    >{{ request.label }} <i class="pi pi-arrow-up-right"
                  /></RouterLink>
                </th>
                <td>{{ request.total }}</td>
                <td>{{ request.by_status.pending || 0 }}</td>
                <td>{{ (request.by_status.approved || 0) + (request.by_status.applied || 0) }}</td>
                <td>{{ request.by_status.rejected || 0 }}</td>
                <td>{{ request.by_status.cancelled || 0 }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
      <div v-if="!hasSections" class="panel empty-state">
        <i class="pi pi-th-large" />
        <h3>Your workspace is ready</h3>
        <p>
          Dashboard summaries appear when your account has access to employee, attendance, or
          request records.
        </p>
        <RouterLink to="/profile"><Button label="View my account" outlined /></RouterLink>
      </div>
    </template>
  </div>
</template>

<style scoped>
.dashboard-page {
  display: grid;
  gap: 24px;
}
.dashboard-page .page-heading {
  margin-bottom: 0;
}
.dashboard-page .panel {
  margin-bottom: 0;
}
.dashboard-page .page-heading h1 {
  font-size: 26px;
  font-weight: 600;
  letter-spacing: -0.025em;
}
.dashboard-welcome {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 32px;
  isolation: isolate;
  overflow: hidden;
  padding: 26px 30px;
  color: var(--hero-contrast);
  background: var(--hero);
  border-radius: 12px;
  min-height: 175px;
}
.dashboard-welcome::before,
.dashboard-welcome::after {
  content: '';
  position: absolute;
  z-index: -1;
  border-radius: 50%;
  pointer-events: none;
}
.dashboard-welcome::before {
  width: 470px;
  height: 470px;
  right: -85px;
  top: -290px;
  border: 70px solid rgb(255 255 255 / 8%);
}
.dashboard-welcome::after {
  width: 400px;
  height: 400px;
  right: 80px;
  bottom: -305px;
  border: 65px solid rgb(255 255 255 / 7%);
}
.dashboard-welcome-copy h2 {
  font-size: 25px;
  font-weight: 600;
  color: var(--hero-contrast);
  letter-spacing: -0.025em;
  margin: 13px 0 9px;
}
.dashboard-welcome-copy p {
  color: var(--hero-muted);
  font-size: 14px;
  margin-bottom: 14px;
}
.dashboard-welcome-label {
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.12em;
  color: var(--hero-muted);
}
.dashboard-welcome-scope {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-size: 12px;
}
.dashboard-welcome-metrics {
  display: flex;
  flex-wrap: wrap;
  gap: 28px;
}
.dashboard-welcome-metrics > div {
  display: grid;
  gap: 7px;
  min-width: 145px;
  border-left: 1px solid rgb(255 255 255 / 25%);
  padding-left: 28px;
}
.dashboard-welcome-metrics span {
  font-size: 12px;
  color: var(--hero-muted);
}
.dashboard-welcome-metrics strong {
  font-size: 36px;
  line-height: 1.15;
  font-weight: 600;
}
.dashboard-welcome-metrics small {
  font-size: 10px;
  color: var(--hero-muted);
  max-width: 165px;
  line-height: 1.5;
}
.dashboard-welcome-symbol {
  font-size: 64px;
  opacity: 0.4;
  padding-right: 22px;
}
.dashboard-filters {
  display: flex;
  align-items: end;
  flex-wrap: wrap;
  gap: 14px;
  padding: 18px 22px;
}
.dashboard-filters .field-label {
  display: grid;
  gap: 7px;
  min-width: 155px;
}
.dashboard-context {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: 10px;
  color: var(--muted);
  font-size: 12px;
}
.dashboard-context i {
  margin-right: 5px;
}
.dashboard-stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(215px, 1fr));
  gap: 18px;
}
.dashboard-stat {
  display: grid;
  gap: 0;
  padding: 0;
  overflow: hidden;
}
.dashboard-stat-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 16px 20px;
  border-bottom: 1px solid var(--line);
  font-size: 12px;
  font-weight: 500;
  color: var(--muted);
}
.dashboard-stat > strong {
  padding: 18px 20px 7px;
  font-size: 30px;
  line-height: 1.15;
  letter-spacing: -0.035em;
  font-weight: 600;
}
.dashboard-stat small {
  padding: 0 20px 19px;
  color: var(--muted);
  font-size: 11px;
  line-height: 1.55;
}
.dashboard-stat-icon {
  display: grid;
  place-items: center;
  flex-shrink: 0;
  width: 28px;
  height: 28px;
  color: var(--accent);
  background: var(--accent-soft);
  border-radius: 7px;
  font-size: 13px;
}
.dashboard-section-heading {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 14px;
  margin: -24px -24px 24px;
  padding: 22px 24px 20px;
  border-bottom: 1px solid var(--line);
}
.dashboard-section-heading h2 {
  margin-top: 0;
  font-size: 16px;
  font-weight: 600;
  letter-spacing: -0.015em;
}
.dashboard-section-heading .eyebrow {
  display: block;
  margin-bottom: 7px;
  color: var(--muted);
  font-size: 9px;
  letter-spacing: 0.09em;
}
.dashboard-section-heading p {
  font-size: 13px;
  margin-bottom: 0;
}
.dashboard-approvals,
.dashboard-attendance,
.dashboard-trend,
.dashboard-request-panel {
  padding: 24px;
  min-width: 0;
}
.dashboard-approval-types {
  display: flex;
  flex-wrap: wrap;
  gap: 9px;
  margin-bottom: 20px;
}
.dashboard-queue-type {
  display: inline-flex;
  gap: 14px;
  font-size: 12px;
  color: var(--muted);
  border: 1px solid var(--line);
  border-radius: 8px;
  padding: 9px 12px;
}
.dashboard-queue-type strong {
  color: var(--text);
}
.dashboard-queue-row {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 15px 0;
  border-top: 1px solid var(--line);
}
.dashboard-avatar {
  display: grid;
  place-items: center;
  flex-shrink: 0;
  width: 36px;
  height: 36px;
  background: var(--accent-soft);
  color: var(--accent);
  border-radius: 12px;
  font-weight: 650;
}
.dashboard-queue-person {
  display: grid;
  gap: 5px;
  min-width: 155px;
}
.dashboard-queue-person strong {
  font-size: 13px;
}
.dashboard-queue-person span,
.dashboard-queue-date {
  font-size: 12px;
  color: var(--muted);
}
.dashboard-queue-date {
  flex: 1;
  text-align: right;
}
.dashboard-preview-note {
  font-size: 12px;
  margin: 12px 0 0;
}
.dashboard-analysis {
  display: grid;
  grid-template-columns: minmax(0, 1.7fr) minmax(280px, 1fr);
  gap: 24px;
}
.dashboard-status-list {
  display: grid;
  gap: 18px;
}
.dashboard-status-row > div {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 9px;
}
.dashboard-status-row strong {
  font-size: 14px;
}
.dashboard-status-row progress {
  display: block;
  appearance: none;
  width: 100%;
  height: 7px;
  border: 0;
  border-radius: 6px;
  overflow: hidden;
  background: var(--soft);
  accent-color: var(--accent);
}
.dashboard-status-row progress::-webkit-progress-bar {
  background: var(--soft);
}
.dashboard-status-row progress::-webkit-progress-value {
  background: var(--accent);
  border-radius: 6px;
}
.dashboard-minute-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12px;
  border-top: 1px solid var(--line);
  margin-top: 24px;
  padding-top: 19px;
}
.dashboard-minute-grid > div {
  display: grid;
  gap: 7px;
}
.dashboard-minute-grid strong {
  font-size: 20px;
}
.dashboard-minute-grid span,
.dashboard-footnote {
  color: var(--muted);
  font-size: 11px;
  line-height: 1.6;
}
.dashboard-footnote {
  margin: 18px 0 0;
}
.dashboard-inline-empty {
  padding: 20px 0;
  font-size: 13px;
}
.dashboard-chart {
  margin: 30px 0 0;
}
.dashboard-chart-legend {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  flex-shrink: 0;
  font-size: 11px;
  color: var(--muted);
  padding-top: 3px;
}
.dashboard-chart-legend > span {
  width: 9px;
  height: 9px;
  border-radius: 2px;
  background: var(--accent);
}
.dashboard-bars {
  height: 260px;
  display: flex;
  align-items: end;
  gap: 6px;
  border-bottom: 1px solid var(--line);
  padding-bottom: 1px;
  background-image: repeating-linear-gradient(
    to top,
    transparent 0,
    transparent calc(25% - 1px),
    var(--line) calc(25% - 1px),
    var(--line) 25%
  );
}
.dashboard-bar-track {
  display: flex;
  align-items: end;
  height: 100%;
  flex: 1;
  min-width: 0;
}
.dashboard-bar-track > div {
  width: 100%;
  max-width: 34px;
  margin: 0 auto;
  background: var(--accent);
  border-radius: 4px 4px 0 0;
  opacity: 0.9;
}
.dashboard-chart figcaption {
  display: flex;
  justify-content: space-between;
  margin-top: 12px;
  font-size: 11px;
  color: var(--muted);
}
.dashboard-table tbody th {
  font-weight: 550;
}
.dashboard-table i {
  font-size: 10px;
  color: var(--muted);
  margin-left: 5px;
}
.dashboard-table tbody tr:last-child > * {
  border-bottom: 0;
}
@media (max-width: 1000px) {
  .dashboard-welcome {
    padding: 26px;
    gap: 24px;
  }
  .dashboard-welcome-metrics {
    gap: 18px;
  }
  .dashboard-welcome-metrics > div {
    min-width: 110px;
    padding-left: 20px;
  }
  .dashboard-analysis {
    grid-template-columns: 1fr;
  }
}
@media (max-width: 620px) {
  .dashboard-welcome {
    flex-direction: column;
    align-items: flex-start;
    padding: 25px;
  }
  .dashboard-welcome-copy h2 {
    font-size: 24px;
  }
  .dashboard-welcome-copy p {
    margin-bottom: 15px;
  }
  .dashboard-welcome-metrics {
    width: 100%;
    padding-top: 20px;
    border-top: 1px solid rgb(255 255 255 / 25%);
    gap: 20px;
  }
  .dashboard-welcome-metrics > div {
    flex: 1;
    padding-left: 0;
    border-left: 0;
  }
  .dashboard-welcome-metrics strong {
    font-size: 28px;
  }
  .dashboard-welcome-symbol {
    display: none;
  }
  .dashboard-stats {
    grid-template-columns: 1fr 1fr;
    gap: 12px;
  }
  .dashboard-stat-heading {
    padding: 14px;
    min-height: 57px;
    font-size: 11px;
  }
  .dashboard-stat-icon {
    display: none;
  }
  .dashboard-stat > strong {
    padding: 15px 14px 7px;
    font-size: 28px;
  }
  .dashboard-stat small {
    padding: 0 14px 16px;
    font-size: 10px;
  }
  .dashboard-filters {
    padding: 16px;
  }
  .dashboard-filters .field-label {
    flex: 1;
    min-width: 120px;
  }
  .dashboard-approvals,
  .dashboard-attendance,
  .dashboard-trend,
  .dashboard-request-panel {
    padding: 18px;
  }
  .dashboard-section-heading {
    margin: -18px -18px 20px;
    padding: 18px;
    flex-wrap: wrap;
  }
  .dashboard-queue-row {
    flex-wrap: wrap;
    gap: 10px;
  }
  .dashboard-queue-person {
    flex: 1;
    min-width: 150px;
  }
  .dashboard-queue-date {
    flex-basis: 100%;
    text-align: left;
    order: 4;
    margin-left: 46px;
  }
  .dashboard-context {
    font-size: 11px;
  }
}
</style>
