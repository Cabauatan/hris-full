<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import Dialog from 'primevue/dialog'
import Message from 'primevue/message'
import Select from 'primevue/select'
import Skeleton from 'primevue/skeleton'
import Tag from 'primevue/tag'
import http, { apiError } from '@/api/http'
import { useAuthStore } from '@/stores/auth'
import { formatValue, labelFor, statusSeverity } from '@/utils/forms'
import { sourceLink } from '@/utils/workspace'
import { displayLabel, displayRecord } from '@/utils/records'
import {
  addDays,
  addMonths,
  birthdayAgeLabel,
  calendarRange,
  calendarTypes,
  dateKey,
  datesInRange,
  eventRangeLabel,
  eventsForDate,
  eventTimeLabel,
  eventTitleLabel,
  formatCalendarDate,
  mondayOf,
  typesQuery,
} from '@/utils/calendar'

const auth = useAuthStore()
const route = useRoute()
const birthdayNotification = computed(
  () => route.query.scope === 'visible' && route.query.types === 'birthday',
)
const metadata = ref(null)
const events = ref([])
const anchor = ref('')
const selectedDay = ref('')
const mode = ref('month')
const scope = ref('')
const selectedTypes = ref([])
const busy = ref(true)
const initialized = ref(false)
const error = ref('')
const filterError = ref('')
const filtersOpen = ref(false)
const jumpDate = ref('')
const rangeStart = ref('')
const rangeEnd = ref('')
const customRange = ref(false)
const appliedRange = ref(null)
const selectedEvent = ref(null)
const detailOpen = ref(false)
const calendarGrid = ref(null)
let requestVersion = 0
let controller
let active = true

const viewOptions = [
  { label: 'Month', value: 'month' },
  { label: 'Week', value: 'week' },
  { label: 'Agenda', value: 'agenda' },
]
const weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']
const allowedTypes = computed(() =>
  (metadata.value?.allowed_types || []).filter((type) => calendarTypes[type]),
)
const scopeOptions = computed(() =>
  (metadata.value?.available_scopes || []).map((value) => ({
    value,
    label: value === 'mine' ? 'My calendar' : 'My authorized view',
  })),
)
const displayRange = computed(() => {
  if (!anchor.value) return null
  return customRange.value ? appliedRange.value : calendarRange(anchor.value, mode.value)
})
const days = computed(() =>
  displayRange.value ? datesInRange(displayRange.value.start, displayRange.value.end) : [],
)
const dayEvents = computed(() =>
  Object.fromEntries(days.value.map((day) => [day, eventsForDate(events.value, day)])),
)
const agendaDays = computed(() => days.value.filter((day) => dayEvents.value[day]?.length))
const selectedEvents = computed(() => eventsForDate(events.value, selectedDay.value))
const heading = computed(() => {
  if (!anchor.value) return 'Calendar'
  if (mode.value === 'month' || (mode.value === 'agenda' && !customRange.value))
    return formatCalendarDate(anchor.value, { month: 'long', year: 'numeric', day: undefined })
  return `${formatCalendarDate(displayRange.value.start)} – ${formatCalendarDate(displayRange.value.end, { year: 'numeric' })}`
})
const source = computed(() =>
  selectedEvent.value
    ? sourceLink(
        selectedEvent.value.source,
        auth,
        metadata.value?.scope || scope.value || 'visible',
      )
    : null,
)
const warnings = computed(() => metadata.value?.meta?.warnings || [])
const selectedDayLabel = computed(() =>
  formatCalendarDate(selectedDay.value, { weekday: 'long', month: 'long', year: 'numeric' }),
)
const selectedDetails = computed(() => displayRecord(selectedEvent.value?.details || {}))

function typeInfo(type) {
  return calendarTypes[type] || { singular: 'Event', icon: 'pi pi-calendar', tone: 'slate' }
}

async function loadCalendar(initial = false) {
  const version = ++requestVersion
  controller?.abort()
  controller = new AbortController()
  busy.value = true
  error.value = ''
  events.value = []
  detailOpen.value = false
  selectedEvent.value = null
  try {
    const params = initial
      ? birthdayNotification.value
        ? { scope: 'visible', types: ['birthday'] }
        : {}
      : { ...displayRange.value, scope: scope.value, types: typesQuery(selectedTypes.value) }
    const { data } = await http.get('/calendar', { params, signal: controller.signal })
    if (!active || version !== requestVersion) return
    metadata.value = data.data
    scope.value = data.data.scope
    if (initial) {
      customRange.value = false
      appliedRange.value = null
      mode.value = 'month'
      anchor.value = data.data.today
      selectedDay.value = data.data.today
      selectedTypes.value = birthdayNotification.value
        ? allowedTypes.value.filter((type) => type === 'birthday')
        : [...allowedTypes.value]
      const range = calendarRange(anchor.value)
      rangeStart.value = range.start
      rangeEnd.value = range.end
      jumpDate.value = anchor.value
      initialized.value = true
      await loadCalendar()
      return
    }
    events.value = data.data.events || []
  } catch (failure) {
    if (!active || version !== requestVersion || failure.code === 'ERR_CANCELED') return
    error.value = apiError(failure).message
  } finally {
    if (active && version === requestVersion) busy.value = false
  }
}

function resetRangeInputs() {
  if (!displayRange.value) return
  rangeStart.value = displayRange.value.start
  rangeEnd.value = displayRange.value.end
  jumpDate.value = anchor.value
  filterError.value = ''
}

function toggleDateFilters() {
  filtersOpen.value = !filtersOpen.value
  resetRangeInputs()
}

function changeView() {
  customRange.value = false
  selectedDay.value = anchor.value
  resetRangeInputs()
  loadCalendar()
}

function move(amount) {
  customRange.value = false
  anchor.value =
    mode.value === 'week' ? addDays(anchor.value, amount * 7) : addMonths(anchor.value, amount)
  selectedDay.value = anchor.value
  resetRangeInputs()
  loadCalendar()
}

function goToday() {
  customRange.value = false
  anchor.value = metadata.value.today
  selectedDay.value = anchor.value
  resetRangeInputs()
  loadCalendar()
}

function jumpToDate() {
  if (!dateKey(jumpDate.value)) return
  customRange.value = false
  anchor.value = jumpDate.value
  selectedDay.value = anchor.value
  resetRangeInputs()
  loadCalendar()
}

function applyRange() {
  if (!dateKey(rangeStart.value) || !dateKey(rangeEnd.value) || rangeEnd.value < rangeStart.value) {
    filterError.value = 'Choose a valid start and end date, with the end on or after the start.'
    return
  }
  if (!datesInRange(rangeStart.value, rangeEnd.value).length) {
    filterError.value = 'Choose a date range of 62 days or fewer.'
    return
  }
  filterError.value = ''
  customRange.value = true
  appliedRange.value = { start: rangeStart.value, end: rangeEnd.value }
  anchor.value = rangeStart.value
  selectedDay.value = rangeStart.value
  mode.value = 'agenda'
  filtersOpen.value = false
  loadCalendar()
}

function openEvent(event) {
  selectedEvent.value = event
  detailOpen.value = true
}

async function navigateDay(event, day) {
  const offsets = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }
  let target
  if (Object.hasOwn(offsets, event.key)) target = addDays(day, offsets[event.key])
  else if (event.key === 'Home') target = mondayOf(day)
  else if (event.key === 'End') target = addDays(mondayOf(day), 6)
  else if (event.key === 'PageUp') target = addMonths(day, -1)
  else if (event.key === 'PageDown') target = addMonths(day, 1)
  else return
  event.preventDefault()
  selectedDay.value = target
  if (!days.value.includes(target)) {
    anchor.value = target
    customRange.value = false
    resetRangeInputs()
    await loadCalendar()
  }
  await nextTick()
  calendarGrid.value?.querySelector(`[data-date="${target}"]`)?.focus()
}

onMounted(() => loadCalendar(true))
watch(birthdayNotification, () => loadCalendar(true))
onBeforeUnmount(() => {
  active = false
  requestVersion += 1
  controller?.abort()
})
</script>

<template>
  <div class="calendar-page">
    <div class="page-heading">
      <div>
        <span class="eyebrow">PLAN YOUR WORKDAY</span>
        <h1>Calendar</h1>
        <p>Birthdays, schedules, holidays, and approved time away in one view.</p>
      </div>
      <div class="actions">
        <Button
          icon="pi pi-refresh"
          label="Refresh"
          severity="secondary"
          outlined
          :loading="busy"
          @click="loadCalendar(!initialized)"
        /><RouterLink to="/dashboard"
          ><Button label="Dashboard" icon="pi pi-chart-bar" outlined
        /></RouterLink>
      </div>
    </div>
    <Message v-if="error" severity="error" :closable="false"
      >{{ error }} <Button label="Try again" text @click="loadCalendar(!initialized)"
    /></Message>
    <div v-if="!initialized && busy" class="calendar-initial-loading" aria-label="Loading calendar">
      <Skeleton height="4rem" /><Skeleton height="30rem" />
    </div>
    <template v-if="initialized">
      <section class="panel calendar-controls">
        <div class="calendar-toolbar">
          <div class="calendar-period-controls">
            <Button icon="pi pi-angle-left" text aria-label="Previous period" @click="move(-1)" />
            <h2 aria-live="polite">{{ heading }}</h2>
            <Button
              icon="pi pi-angle-right"
              text
              aria-label="Next period"
              @click="move(1)"
            /><Button label="Today" text @click="goToday" />
          </div>
          <div class="calendar-view-controls">
            <Select
              v-model="mode"
              :options="viewOptions"
              option-label="label"
              option-value="value"
              aria-label="Calendar view"
              @change="changeView"
            /><Select
              v-if="scopeOptions.length > 1"
              v-model="scope"
              :options="scopeOptions"
              option-label="label"
              option-value="value"
              aria-label="Calendar scope"
              @change="loadCalendar()"
            /><Button
              icon="pi pi-sliders-h"
              label="Dates"
              severity="secondary"
              outlined
              :aria-expanded="filtersOpen"
              aria-controls="calendar-date-filters"
              @click="toggleDateFilters"
            />
          </div>
        </div>
        <div v-if="filtersOpen" id="calendar-date-filters" class="calendar-date-filters">
          <label class="field-label"
            >Jump to date<input
              v-model="jumpDate"
              class="native-input"
              type="date"
              @change="jumpToDate"
          /></label>
          <label class="field-label"
            >Range start<input v-model="rangeStart" class="native-input" type="date"
          /></label>
          <label class="field-label"
            >Range end<input v-model="rangeEnd" class="native-input" type="date"
          /></label>
          <Button label="Apply range" icon="pi pi-check" @click="applyRange" /><small
            class="calendar-filter-help"
            >Custom ranges open in Agenda view · maximum 62 days.</small
          ><small v-if="filterError" class="field-error">{{ filterError }}</small>
        </div>
        <div class="calendar-type-filters" aria-label="Event types">
          <label v-for="type in allowedTypes" :key="type" class="calendar-type-choice"
            ><Checkbox
              v-model="selectedTypes"
              :input-id="`calendar-type-${type}`"
              :value="type"
              @change="loadCalendar()"
            /><span class="calendar-dot" :class="`tone-${typeInfo(type).tone}`" />{{
              typeInfo(type).label
            }}</label
          ><span v-if="!allowedTypes.length" class="muted"
            >No calendar sources are available for your permissions.</span
          >
        </div>
        <div class="calendar-scope-note">
          <span
            ><i class="pi pi-shield" />{{
              scope === 'mine' ? 'Your employee records' : 'Records within your authorized access'
            }}</span
          ><span>{{ metadata.range?.timezone }} · Monday start</span>
        </div>
      </section>
      <Message v-if="warnings.length || metadata.meta?.truncated" severity="warn" :closable="false"
        ><div v-for="warning in warnings" :key="warning">{{ warning }}</div>
        <span v-if="metadata.meta?.truncated && !warnings.length"
          >Some events could not be displayed. Narrow the range or event types.</span
        ></Message
      >
      <Message v-if="!selectedTypes.length" severity="info" :closable="false"
        >Select an event type to see it on your calendar.</Message
      >
      <div class="calendar-columns" :aria-busy="busy">
        <section class="panel calendar-main">
          <div v-if="busy" class="calendar-loading-note" role="status">
            <i class="pi pi-spinner pi-spin" />Updating calendar…
          </div>
          <div
            v-if="mode !== 'agenda'"
            ref="calendarGrid"
            class="calendar-grid-wrap"
            :class="{ 'calendar-week': mode === 'week' }"
          >
            <div class="calendar-weekdays" aria-hidden="true">
              <span v-for="day in weekdays" :key="day">{{ day }}</span>
            </div>
            <div class="calendar-grid" aria-label="Calendar dates">
              <article
                v-for="day in days"
                :key="day"
                class="calendar-day"
                :class="{
                  'outside-month': mode === 'month' && day.slice(0, 7) !== anchor.slice(0, 7),
                  'selected-day': day === selectedDay,
                  'today-day': day === metadata.today,
                }"
              >
                <button
                  class="calendar-day-number"
                  :data-date="day"
                  :aria-pressed="day === selectedDay"
                  :aria-label="`${formatCalendarDate(day, { weekday: 'long', year: 'numeric' })}, ${dayEvents[day].length} events`"
                  :tabindex="day === selectedDay ? 0 : -1"
                  @click="selectedDay = day"
                  @keydown="navigateDay($event, day)"
                >
                  {{ Number(day.slice(8))
                  }}<span v-if="day === metadata.today" class="calendar-today-dot" />
                </button>
                <button
                  v-for="item in dayEvents[day].slice(0, mode === 'week' ? 8 : 3)"
                  :key="item.id"
                  class="calendar-event-chip"
                  :class="`tone-${typeInfo(item.type).tone}`"
                  :title="`${eventTitleLabel(item)} · ${eventTimeLabel(item, day)}`"
                  @click="openEvent(item)"
                >
                  <span
                    v-if="
                      !item.all_day ||
                      (item.type === 'leave' &&
                        item.details?.day_portion &&
                        item.details.day_portion !== 'full_day')
                    "
                    class="calendar-chip-time"
                    >{{ eventTimeLabel(item, day) }}</span
                  ><span>{{ eventTitleLabel(item) }}</span>
                </button>
                <button
                  v-if="dayEvents[day].length > (mode === 'week' ? 8 : 3)"
                  class="calendar-more"
                  @click="selectedDay = day"
                >
                  +{{ dayEvents[day].length - (mode === 'week' ? 8 : 3) }} more
                </button>
              </article>
            </div>
          </div>
          <div class="calendar-agenda" :class="{ 'mobile-agenda': mode !== 'agenda' }">
            <div v-if="!agendaDays.length && !busy" class="empty-state">
              <span class="empty-icon"><i class="pi pi-calendar" /></span
              ><b>No events in this period</b>
              <p>Try another date range or choose different event types.</p>
            </div>
            <section v-for="day in agendaDays" :key="day" class="calendar-agenda-day">
              <button class="calendar-agenda-date" @click="selectedDay = day">
                <strong>{{ Number(day.slice(8)) }}</strong
                ><span>{{
                  formatCalendarDate(day, { weekday: 'short', month: 'short', day: undefined })
                }}</span
                ><small v-if="day === metadata.today">Today</small>
              </button>
              <div class="calendar-agenda-events">
                <button
                  v-for="item in dayEvents[day]"
                  :key="item.id"
                  class="calendar-agenda-event"
                  @click="openEvent(item)"
                >
                  <span class="calendar-event-icon" :class="`tone-${typeInfo(item.type).tone}`"
                    ><i :class="typeInfo(item.type).icon" /></span
                  ><span
                    ><b>{{ eventTitleLabel(item) }}</b
                    ><small
                      >{{ eventTimeLabel(item, day) }} · {{ typeInfo(item.type).singular }}</small
                    ></span
                  ><i class="pi pi-angle-right" />
                </button>
              </div>
            </section>
          </div>
          <div class="calendar-bottom-note">
            <span
              >{{ events.length }} {{ events.length === 1 ? 'event' : 'events' }} in this date
              range</span
            ><span v-if="mode !== 'agenda'">Arrow keys move between dates</span>
          </div>
        </section>
        <aside class="panel calendar-day-detail">
          <div class="panel-heading">
            <div>
              <span class="eyebrow">YOUR DAY AT A GLANCE</span>
              <h2>{{ selectedDayLabel }}</h2>
            </div>
          </div>
          <div v-if="busy" class="calendar-detail-skeleton">
            <Skeleton height="4rem" /><Skeleton height="4rem" />
          </div>
          <div v-else-if="!selectedEvents.length" class="empty-state calendar-day-empty">
            <span class="empty-icon"><i class="pi pi-calendar" /></span><b>A clear day</b>
            <p>No matching events on this date.</p>
          </div>
          <button
            v-for="item in selectedEvents"
            :key="item.id"
            class="calendar-day-event"
            @click="openEvent(item)"
          >
            <span class="calendar-event-icon" :class="`tone-${typeInfo(item.type).tone}`"
              ><i :class="typeInfo(item.type).icon" /></span
            ><span
              ><small
                >{{ typeInfo(item.type).singular }} · {{ eventTimeLabel(item, selectedDay) }}</small
              ><b>{{ eventTitleLabel(item) }}</b
              ><span v-if="item.employee">{{ item.employee.employee_number }}</span></span
            ><i class="pi pi-angle-right" />
          </button>
        </aside>
      </div>
    </template>
    <Dialog
      v-model:visible="detailOpen"
      modal
      :header="typeInfo(selectedEvent?.type).singular"
      :style="{ width: '36rem' }"
      :breakpoints="{ '640px': '95vw' }"
    >
      <template v-if="selectedEvent"
        ><div class="calendar-event-detail-heading">
          <span class="calendar-event-icon" :class="`tone-${typeInfo(selectedEvent.type).tone}`"
            ><i :class="typeInfo(selectedEvent.type).icon"
          /></span>
          <div>
            <h2>{{ selectedEvent.title }}</h2>
            <p>{{ eventRangeLabel(selectedEvent) }}</p>
            <small>{{
              selectedEvent.all_day ? eventTimeLabel(selectedEvent) : metadata.range?.timezone
            }}</small>
          </div>
        </div>
        <Tag
          v-if="selectedEvent.status"
          :value="labelFor(selectedEvent.status)"
          :severity="statusSeverity(selectedEvent.status)" />
        <dl class="calendar-event-details">
          <div v-if="selectedEvent.employee">
            <dt>Employee</dt>
            <dd>
              {{ selectedEvent.employee.name
              }}<small>{{ selectedEvent.employee.employee_number }}</small>
            </dd>
          </div>
          <div v-for="(value, key) in selectedDetails" :key="key">
            <dt>
              {{
                selectedEvent.type === 'birthday' && key === 'age'
                  ? 'Age on birthday'
                  : displayLabel(key.replace(/_name$/, ''))
              }}
            </dt>
            <dd>
              {{
                selectedEvent.type === 'birthday' && key === 'age'
                  ? birthdayAgeLabel(selectedEvent)
                  : formatValue(value)
              }}
            </dd>
          </div>
        </dl>
        <div class="dialog-actions">
          <Button label="Close" text @click="detailOpen = false" /><RouterLink
            v-if="source"
            :to="source"
            ><Button label="Open record" icon="pi pi-arrow-up-right" @click="detailOpen = false"
          /></RouterLink></div
      ></template>
    </Dialog>
  </div>
</template>

<style scoped>
.calendar-initial-loading,
.calendar-detail-skeleton {
  display: grid;
  gap: 16px;
}
.calendar-controls {
  padding-bottom: 16px;
}
.calendar-toolbar,
.calendar-period-controls,
.calendar-view-controls {
  display: flex;
  align-items: center;
  gap: 9px;
}
.calendar-toolbar {
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 18px;
}
.calendar-period-controls h2 {
  margin: 0;
  min-width: 190px;
  text-align: center;
  font-size: 20px;
}
.calendar-view-controls .p-select {
  font-size: 11px;
}
.calendar-date-filters {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr)) auto;
  gap: 15px;
  align-items: end;
  padding: 20px 0;
  border-bottom: 1px solid var(--line);
}
.calendar-filter-help,
.calendar-date-filters > .field-error {
  grid-column: 1/-1;
  font-size: 10px;
  color: var(--muted);
}
.calendar-date-filters > .field-error {
  color: #dc5656;
}
.calendar-type-filters {
  display: flex;
  flex-wrap: wrap;
  gap: 13px 20px;
  padding: 22px 0 16px;
}
.calendar-type-choice {
  display: flex;
  align-items: center;
  gap: 7px;
  font-size: 11px;
  cursor: pointer;
}
.calendar-dot {
  height: 7px;
  width: 7px;
  border-radius: 50%;
  background: currentColor;
}
.calendar-scope-note {
  display: flex;
  justify-content: space-between;
  gap: 15px;
  color: var(--muted);
  font-size: 10px;
}
.calendar-scope-note span:first-child {
  display: flex;
  align-items: center;
  gap: 7px;
}
.calendar-columns {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 290px;
  gap: 21px;
  align-items: start;
}
.calendar-main {
  padding: 0;
  overflow: hidden;
}
.calendar-day-detail {
  padding: 23px 20px;
}
.calendar-day-detail h2 {
  font-size: 15px;
  line-height: 1.5;
}
.calendar-loading-note {
  padding: 11px 20px;
  font-size: 11px;
  display: flex;
  gap: 9px;
  color: var(--accent);
  border-bottom: 1px solid var(--line);
  background: var(--accent-soft);
}
.calendar-weekdays,
.calendar-grid {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
}
.calendar-weekdays {
  padding: 15px 0;
  color: var(--muted);
  background: var(--soft);
  font-size: 10px;
  text-align: center;
  border-bottom: 1px solid var(--line);
  letter-spacing: 0.04em;
}
.calendar-day {
  min-width: 0;
  min-height: 114px;
  padding: 8px 5px;
  border-bottom: 1px solid var(--line);
  border-right: 1px solid var(--line);
}
.calendar-day:nth-child(7n) {
  border-right: 0;
}
.calendar-day:nth-last-child(-n + 7) {
  border-bottom: 0;
}
.calendar-day.outside-month {
  background: var(--soft);
}
.calendar-day.outside-month > .calendar-day-number {
  color: var(--muted);
}
.calendar-day.selected-day {
  background: var(--accent-soft);
  box-shadow: inset 0 0 0 1px var(--accent);
}
.calendar-day-number {
  background: transparent;
  border: 0;
  border-radius: 50%;
  color: var(--text);
  font-size: 11px;
  font-weight: 600;
  width: 27px;
  height: 27px;
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
  margin-bottom: 5px;
}
.calendar-day-number:focus-visible,
.calendar-event-chip:focus-visible,
.calendar-more:focus-visible,
.calendar-agenda-date:focus-visible,
.calendar-agenda-event:focus-visible,
.calendar-day-event:focus-visible {
  outline: 2px solid var(--accent);
  outline-offset: 2px;
}
.today-day .calendar-day-number {
  background: var(--accent);
  color: var(--panel);
}
.calendar-today-dot {
  display: none;
}
.calendar-event-chip {
  display: flex;
  align-items: center;
  gap: 4px;
  width: 100%;
  padding: 4px 5px;
  border: 0;
  border-radius: 4px;
  text-align: left;
  font-size: 9px;
  margin-bottom: 3px;
  line-height: 1.4;
  background: var(--event-bg);
  color: var(--event-color);
  min-width: 0;
}
.calendar-event-chip > span:last-child {
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}
.calendar-chip-time {
  font-size: 8px;
  white-space: nowrap;
}
.calendar-more {
  padding: 4px;
  background: transparent;
  border: 0;
  font-size: 9px;
  color: var(--muted);
}
.calendar-week .calendar-day {
  min-height: 350px;
}
.calendar-week .calendar-event-chip {
  display: block;
  padding: 7px;
  line-height: 1.5;
}
.calendar-week .calendar-chip-time {
  display: block;
}
.calendar-week .calendar-event-chip > span:last-child {
  display: block;
  white-space: normal;
  overflow-wrap: anywhere;
}
.calendar-agenda {
  padding: 0 22px;
}
.calendar-agenda.mobile-agenda {
  display: none;
}
.calendar-agenda-day {
  display: grid;
  grid-template-columns: 67px minmax(0, 1fr);
  gap: 14px;
  border-bottom: 1px solid var(--line);
  padding: 19px 0;
}
.calendar-agenda-day:last-child {
  border-bottom: 0;
}
.calendar-agenda-date {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  border: 0;
  background: var(--soft);
  color: var(--text);
  border-radius: 9px;
  padding: 12px 5px;
  align-self: start;
}
.calendar-agenda-date strong {
  font-size: 23px;
  font-weight: 650;
}
.calendar-agenda-date span {
  font-size: 9px;
  color: var(--muted);
}
.calendar-agenda-date small {
  font-size: 9px;
  color: var(--accent);
}
.calendar-agenda-events {
  display: grid;
  gap: 5px;
}
.calendar-agenda-event,
.calendar-day-event {
  border: 0;
  background: transparent;
  color: var(--text);
  text-align: left;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 0;
  min-width: 0;
  width: 100%;
}
.calendar-agenda-event > span:nth-child(2),
.calendar-day-event > span:nth-child(2) {
  flex: 1;
  min-width: 0;
}
.calendar-agenda-event b,
.calendar-day-event b {
  display: block;
  font-size: 11px;
  line-height: 1.6;
  overflow-wrap: anywhere;
  font-weight: 600;
}
.calendar-agenda-event small,
.calendar-day-event small,
.calendar-day-event > span > span {
  display: block;
  font-size: 9px;
  color: var(--muted);
  line-height: 1.6;
}
.calendar-agenda-event > i,
.calendar-day-event > i {
  font-size: 10px;
  color: var(--muted);
}
.calendar-event-icon {
  display: grid;
  place-items: center;
  height: 34px;
  width: 34px;
  flex: 0 0 34px;
  border-radius: 9px;
  background: var(--event-bg);
  color: var(--event-color);
  font-size: 14px;
}
.calendar-day-event {
  align-items: flex-start;
  border-top: 1px solid var(--line);
  padding: 16px 0;
}
.calendar-day-event > i {
  margin-top: 9px;
}
.calendar-day-empty {
  padding: 28px 8px;
}
.calendar-bottom-note {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  padding: 13px 20px;
  font-size: 9px;
  color: var(--muted);
  border-top: 1px solid var(--line);
}
.calendar-event-detail-heading {
  display: flex;
  align-items: flex-start;
  gap: 14px;
  margin: 8px 0 22px;
}
.calendar-event-detail-heading > div {
  min-width: 0;
  overflow-wrap: anywhere;
}
.calendar-event-detail-heading h2 {
  font-size: 19px;
  line-height: 1.5;
  margin-bottom: 7px;
}
.calendar-event-detail-heading p {
  margin: 0 0 6px;
  font-size: 12px;
  color: var(--muted);
}
.calendar-event-detail-heading small {
  font-size: 10px;
  color: var(--muted);
}
.calendar-event-details {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 21px;
  margin: 23px 0;
}
.calendar-event-details dt {
  font-size: 10px;
  color: var(--muted);
  margin-bottom: 7px;
}
.calendar-event-details dd {
  font-size: 12px;
  line-height: 1.6;
  margin: 0;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.calendar-event-details dd small {
  display: block;
  font-size: 10px;
  color: var(--muted);
}
.tone-amber {
  --event-color: #997124;
  --event-bg: #fcf2dc;
  color: var(--event-color);
}
.tone-slate {
  --event-color: #647582;
  --event-bg: #edf1f4;
  color: var(--event-color);
}
.tone-emerald {
  --event-color: #217959;
  --event-bg: #e4f3eb;
  color: var(--event-color);
}
.tone-teal {
  --event-color: #2b8282;
  --event-bg: #e3f3f1;
  color: var(--event-color);
}
.tone-violet {
  --event-color: #7e64a7;
  --event-bg: #eee8f8;
  color: var(--event-color);
}
.tone-blue {
  --event-color: #487aaf;
  --event-bg: #e8f0fc;
  color: var(--event-color);
}
.tone-rose {
  --event-color: #b06678;
  --event-bg: #faeaf0;
  color: var(--event-color);
}
:global(.hris-dark) .tone-amber {
  --event-color: #e4c171;
  --event-bg: #3b3422;
}
:global(.hris-dark) .tone-slate {
  --event-color: #bacad8;
  --event-bg: #293945;
}
:global(.hris-dark) .tone-emerald {
  --event-color: #98d9b6;
  --event-bg: #214435;
}
:global(.hris-dark) .tone-teal {
  --event-color: #8ad3d0;
  --event-bg: #214144;
}
:global(.hris-dark) .tone-violet {
  --event-color: #c6b0ee;
  --event-bg: #343046;
}
:global(.hris-dark) .tone-blue {
  --event-color: #a0c6f1;
  --event-bg: #243c50;
}
:global(.hris-dark) .tone-rose {
  --event-color: #eab1c0;
  --event-bg: #452e39;
}
@media (max-width: 1300px) {
  .calendar-columns {
    grid-template-columns: minmax(0, 1fr);
  }
  .calendar-day-detail {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0 20px;
  }
  .calendar-day-detail > .panel-heading,
  .calendar-day-detail > .empty-state,
  .calendar-day-detail > .calendar-detail-skeleton {
    grid-column: 1/-1;
  }
}
@media (max-width: 700px) {
  .calendar-period-controls {
    gap: 2px;
  }
  .calendar-period-controls h2 {
    min-width: 165px;
    font-size: 18px;
  }
  .calendar-view-controls {
    width: 100%;
    flex-wrap: wrap;
  }
  .calendar-scope-note {
    flex-direction: column;
    gap: 6px;
  }
  .calendar-date-filters {
    grid-template-columns: 1fr 1fr;
  }
  .calendar-date-filters > .field-label:first-child {
    grid-column: 1/-1;
  }
  .calendar-date-filters > .p-button {
    grid-column: 1/-1;
  }
  .calendar-day-detail {
    grid-template-columns: 1fr;
  }
  .calendar-grid-wrap {
    display: none;
  }
  .calendar-agenda.mobile-agenda {
    display: block;
  }
  .calendar-bottom-note > span:last-child {
    display: none;
  }
  .calendar-agenda {
    padding: 0 17px;
  }
  .calendar-agenda-day {
    grid-template-columns: 56px minmax(0, 1fr);
    gap: 12px;
  }
  .calendar-type-filters {
    gap: 14px;
  }
  .calendar-event-details {
    grid-template-columns: 1fr;
  }
}
</style>
