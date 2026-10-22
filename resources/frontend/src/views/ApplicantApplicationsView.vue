<script setup>
import { computed, onUnmounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import Button from 'primevue/button'
import Select from 'primevue/select'
import Textarea from 'primevue/textarea'
import Message from 'primevue/message'
import Tag from 'primevue/tag'
import Paginator from 'primevue/paginator'
import { useApplicantStore } from '@/stores/applicant'
import { useJobOpeningsStore } from '@/stores/jobOpenings'
import { finalResult, formatMonthlySalary, interviewTime } from '@/utils/recruitment'
import { formatValue, labelFor, statusSeverity } from '@/utils/forms'
const applicant = useApplicantStore()
const openings = useJobOpeningsStore()
const route = useRoute()
const draft = ref({ job_opening_id: null, cover_letter: '' })
const success = ref('')
const selectionWarning = ref('')
let selectionVersion = 0
const selectedOpening = computed(
  () => openings.options.find((opening) => opening.id === draft.value.job_opening_id) || null,
)
watch(
  () => draft.value.job_opening_id,
  () => {
    if (selectedOpening.value?.accepting_applications) {
      selectionWarning.value = ''
      openings.selectionError = ''
    }
  },
)
watch(
  () => applicant.user?.id,
  () => {
    draft.value = { job_opening_id: null, cover_letter: '' }
    success.value = ''
    if (applicant.authenticated) applicant.loadApplications()
  },
  { immediate: true },
)
watch(
  () => [applicant.user?.id, route.query.opening],
  async () => {
    const version = ++selectionVersion
    const account = applicant.user?.id
    draft.value.job_opening_id = null
    selectionWarning.value = ''
    openings.clearSelection()
    if (!applicant.authenticated) return
    const [available, selected] = await Promise.all([
      openings.loadOptions(),
      route.query.opening ? openings.loadOpening(route.query.opening) : Promise.resolve(null),
    ])
    if (version !== selectionVersion || account !== applicant.user?.id) return
    if (selected && available?.some((opening) => opening.id === selected.id))
      draft.value.job_opening_id = selected.id
  },
  { immediate: true },
)
onUnmounted(() => {
  selectionVersion += 1
  openings.clearSelection()
})
async function refreshOpenings() {
  const version = selectionVersion
  const available = await openings.loadOptions()
  if (version !== selectionVersion) return
  if (
    draft.value.job_opening_id &&
    !available?.some((opening) => opening.id === draft.value.job_opening_id)
  ) {
    draft.value.job_opening_id = null
    selectionWarning.value = 'This position is no longer available. Choose another open position.'
  }
}
async function submit() {
  success.value = ''
  selectionWarning.value = ''
  if (!selectedOpening.value?.accepting_applications || openings.optionsLoading) {
    selectionWarning.value = 'Choose an open position before submitting your application.'
    return
  }
  const account = applicant.user?.id
  const created = await applicant.apply({
    job_opening_id: draft.value.job_opening_id,
    cover_letter: draft.value.cover_letter.trim() || null,
  })
  if (account !== applicant.user?.id) return
  if (created) {
    draft.value = { job_opening_id: null, cover_letter: '' }
    success.value = 'Your application was submitted. You can follow its progress below.'
  } else if (applicant.fieldErrors.job_opening_id || applicant.error) await refreshOpenings()
}
</script>
<template>
  <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
    <div>
      <span class="eyebrow">MY APPLICATIONS</span>
      <h1 class="mt-3">Hello, {{ applicant.user?.first_name }}</h1>
      <p class="muted">Follow your application, assessments, interviews, and start date here.</p>
    </div>
    <Button
      label="Refresh"
      icon="pi pi-refresh"
      outlined
      :loading="applicant.loading"
      @click="applicant.loadApplications()"
    />
  </div>
  <Message v-if="applicant.error" severity="error" :closable="false" class="mb-4">{{
    applicant.error
  }}</Message>
  <Message v-if="success" severity="success" :closable="false" class="mb-4">{{ success }}</Message>
  <section
    v-if="
      !applicant.loading &&
      !applicant.hasActiveApplication &&
      applicant.pagination.current_page === 1
    "
    class="application-card mb-6"
  >
    <h2>Submit an application</h2>
    <p class="muted text-sm">Choose a position posted by HR.</p>
    <Message v-if="openings.optionsError" severity="error" :closable="false" class="mb-4">{{
      openings.optionsError
    }}</Message>
    <Message
      v-if="selectionWarning || openings.selectionError"
      severity="warn"
      :closable="false"
      class="mb-4"
      >{{ selectionWarning || openings.selectionError }}</Message
    >
    <p v-if="openings.optionsLoading || openings.selectionLoading" role="status" class="muted">
      Loading available positions…
    </p>
    <p v-else-if="!openings.options.length" class="muted">
      No positions are accepting applications right now. Please check the careers page again later.
    </p>
    <Button
      label="Refresh positions"
      icon="pi pi-refresh"
      text
      :loading="openings.optionsLoading"
      :disabled="applicant.saving"
      @click="refreshOpenings"
    />
    <form class="form-stack" @submit.prevent="submit">
      <label class="field-label"
        >Position<Select
          v-model="draft.job_opening_id"
          :options="openings.options"
          option-label="title"
          option-value="id"
          placeholder="Choose an open position"
          filter
          show-clear
          required
          :loading="openings.optionsLoading"
          :disabled="applicant.saving || openings.optionsLoading"
          fluid
        /><small
          v-for="message in applicant.fieldErrors.job_opening_id || []"
          :key="message"
          class="field-error"
          >{{ message }}</small
        ></label
      >
      <article v-if="selectedOpening" class="position-details">
        <p class="font-semibold mb-3">{{ formatMonthlySalary(selectedOpening.monthly_salary) }}</p>
        <div class="flex flex-wrap gap-3 text-sm muted">
          <span v-if="selectedOpening.location"
            ><i class="pi pi-map-marker" /> {{ selectedOpening.location }}</span
          >
          <span v-if="selectedOpening.employment_type">{{ selectedOpening.employment_type }}</span>
          <span
            >{{ selectedOpening.vacancies }}
            {{ selectedOpening.vacancies === 1 ? 'vacancy' : 'vacancies' }}</span
          >
          <span v-if="selectedOpening.closes_on">Apply by {{ selectedOpening.closes_on }}</span>
        </div>
        <p class="whitespace-pre-wrap mt-3">{{ selectedOpening.description }}</p>
        <template v-if="selectedOpening.requirements">
          <h3>Requirements</h3>
          <p class="whitespace-pre-wrap mb-0">{{ selectedOpening.requirements }}</p>
        </template>
      </article>
      <label class="field-label"
        >Cover letter <small class="muted">Optional</small
        ><Textarea
          v-model="draft.cover_letter"
          maxlength="10000"
          rows="5"
          :disabled="applicant.saving"
          fluid
        /><small
          v-for="message in applicant.fieldErrors.cover_letter || []"
          :key="message"
          class="field-error"
          >{{ message }}</small
        ></label
      ><Button
        type="submit"
        label="Submit application"
        icon="pi pi-send"
        :loading="applicant.saving"
        :disabled="!selectedOpening || openings.optionsLoading || openings.selectionLoading"
      />
    </form>
  </section>
  <p v-if="applicant.loading" role="status" class="muted">Loading your applications…</p>
  <div class="grid gap-5">
    <article
      v-for="application in applicant.applications"
      :key="application.id"
      class="application-card"
    >
      <div class="flex flex-wrap gap-3 justify-between items-start">
        <div>
          <small class="muted">Applied {{ formatValue(application.created_at, 'datetime') }}</small>
          <h2 class="mt-2">{{ application.desired_position }}</h2>
          <p class="muted text-sm mb-0">{{ formatMonthlySalary(application.monthly_salary) }}</p>
        </div>
        <Tag :value="labelFor(application.status)" :severity="statusSeverity(application.status)" />
      </div>
      <p v-if="application.remarks" class="muted whitespace-pre-wrap">{{ application.remarks }}</p>
      <Message v-if="application.status === 'pending'" severity="info" :closable="false"
        >Your application is waiting for review. Assessments will appear here after
        approval.</Message
      >
      <Message
        v-if="application.status === 'approved' && !application.attempts?.length"
        severity="info"
        :closable="false"
        >Your application is approved. Your assigned assessments and interviews will appear
        here.</Message
      >
      <Message v-if="application.status === 'hired'" severity="success" :closable="false"
        ><b>You're hired!</b> Your confirmed start date is <b>{{ application.start_date }}</b
        >.</Message
      >
      <section v-if="application.attempts?.length" class="mt-5">
        <h3>Your assessments</h3>
        <div class="grid gap-3">
          <div v-for="attempt in application.attempts" :key="attempt.id" class="assessment-row">
            <div>
              <b>{{ attempt.title }}</b>
              <p class="muted text-sm mb-0">
                {{
                  finalResult(attempt) ||
                  (attempt.status === 'submitted'
                    ? 'Submitted · Waiting for final grading'
                    : labelFor(attempt.status))
                }}
              </p>
              <small
                v-if="finalResult(attempt)"
                :class="attempt.passed ? 'text-emerald-600' : 'muted'"
                >{{ attempt.passed ? 'Passed' : 'Below passing grade' }}</small
              >
            </div>
            <RouterLink :to="`/applicant/exams/${attempt.id}`"
              ><Button
                :label="attempt.abilities?.submit ? 'Take exam' : 'View assessment'"
                :icon="attempt.abilities?.submit ? 'pi pi-pencil' : 'pi pi-eye'"
                outlined
            /></RouterLink>
          </div>
        </div>
      </section>
      <section v-if="applicant.interviews[application.id]?.length" class="mt-5">
        <h3>Your interviews</h3>
        <div class="grid gap-3">
          <div
            v-for="interview in applicant.interviews[application.id]"
            :key="interview.id"
            class="assessment-row"
          >
            <div>
              <b>{{ labelFor(interview.round) }} interview</b>
              <p class="muted text-sm mb-0">
                {{ interviewTime(interview.scheduled_at) }} ·
                {{ interview.duration_minutes }} minutes
              </p>
              <small
                >{{ labelFor(interview.status) }} ·
                {{ interview.interviewers.map((person) => person.name).join(', ') }}</small
              >
            </div>
            <RouterLink
              v-if="interview.abilities?.join"
              :to="`/applicant/interviews/${interview.id}`"
              ><Button label="Join interview" icon="pi pi-video" /></RouterLink
            ><small v-else-if="interview.status === 'scheduled'" class="muted"
              >Join becomes available near the scheduled time.</small
            >
          </div>
        </div>
      </section>
    </article>
  </div>
  <Paginator
    v-if="applicant.pagination.total > 15"
    :rows="15"
    :first="(applicant.pagination.current_page - 1) * 15"
    :total-records="applicant.pagination.total"
    @page="applicant.loadApplications($event.page + 1)"
  />
</template>
<style scoped>
.application-card {
  background: var(--panel);
  border: 1px solid var(--line);
  border-radius: 16px;
  padding: 24px;
}
.assessment-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 16px;
  background: var(--soft);
  border-radius: 10px;
  flex-wrap: wrap;
}
.position-details {
  padding: 18px;
  background: var(--soft);
  border-radius: 10px;
}
</style>
