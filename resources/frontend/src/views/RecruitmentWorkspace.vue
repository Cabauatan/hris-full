<script setup>
import { computed, ref, watch } from 'vue'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import MultiSelect from 'primevue/multiselect'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Paginator from 'primevue/paginator'
import Dialog from 'primevue/dialog'
import Message from 'primevue/message'
import Tag from 'primevue/tag'
import JobOpeningsPanel from '@/components/JobOpeningsPanel.vue'
import { useAuthStore } from '@/stores/auth'
import { useRecruitmentStore } from '@/stores/recruitment'
import {
  examPayload,
  finalResult,
  formatMonthlySalary,
  writtenGrades,
  interviewSchedule,
  interviewTime,
} from '@/utils/recruitment'
import { labelFor, statusSeverity } from '@/utils/forms'
const auth = useAuthStore()
const recruitment = useRecruitmentStore()
const filters = ref({ status: null, search: '' })
const success = ref('')
const action = ref('')
const actionDraft = ref({ remarks: '', start_date: '', recruitment_exam_id: null })
const schedule = ref({
  round: 'initial',
  scheduled_at: '',
  duration_minutes: 30,
  interviewer_ids: [],
  private_notes: '',
})
const interviewAction = ref(null)
const interviewNotes = ref('')
const examVisible = ref(false)
let nextQuestion = 0
function newQuestion() {
  return {
    key: ++nextQuestion,
    type: 'multiple_choice',
    prompt: '',
    points: 1,
    options: ['', ''],
    correct_option: 0,
  }
}
function newExam() {
  return { title: '', instructions: '', passing_percentage: 70, questions: [newQuestion()] }
}
const examDraft = ref(newExam())
const gradeDraft = ref({})
const writtenQuestions = computed(
  () => recruitment.attempt?.questions?.filter((question) => question.type === 'written') || [],
)
const gradesComplete = computed(
  () =>
    writtenQuestions.value.length > 0 &&
    writtenQuestions.value.every((question) => {
      const score = gradeDraft.value[question.id]?.score
      return (
        typeof score === 'number' &&
        Number.isFinite(score) &&
        score >= 0 &&
        score <= question.points
      )
    }),
)
const statuses = ['pending', 'approved', 'rejected', 'hired'].map((value) => ({
  value,
  label: labelFor(value),
}))
const questionTypes = [
  { value: 'multiple_choice', label: 'Multiple choice' },
  { value: 'written', label: 'Written response' },
]
watch(
  () => auth.user?.id,
  () => {
    success.value = ''
    examVisible.value = false
    examDraft.value = newExam()
    if (auth.authenticated) {
      recruitment.loadApplications()
      recruitment.loadCatalogs()
    }
  },
  { immediate: true },
)
watch(
  () => recruitment.selected?.id,
  () => {
    action.value = ''
    interviewAction.value = null
    actionDraft.value = { remarks: '', start_date: '', recruitment_exam_id: null }
    schedule.value = {
      round: 'initial',
      scheduled_at: '',
      duration_minutes: 30,
      interviewer_ids: [],
      private_notes: '',
    }
  },
)
watch(
  () => recruitment.attempt?.id,
  () => {
    gradeDraft.value = Object.fromEntries(
      writtenQuestions.value.map((question) => [question.id, { score: null, feedback: '' }]),
    )
  },
  { immediate: true },
)
function addQuestion() {
  if (examDraft.value.questions.length < 50) examDraft.value.questions.push(newQuestion())
}
function removeOption(question, index) {
  if (question.options.length <= 2) return
  question.options.splice(index, 1)
  if (question.correct_option === index) question.correct_option = 0
  else if (question.correct_option > index) question.correct_option -= 1
}
async function createExam() {
  success.value = ''
  if (await recruitment.createExam(examPayload(examDraft.value))) {
    examVisible.value = false
    examDraft.value = newExam()
    success.value = 'Assessment created and ready to assign.'
  }
}
async function performAction() {
  success.value = ''
  let payload = { remarks: actionDraft.value.remarks.trim() || null }
  if (action.value === 'hire') payload.start_date = actionDraft.value.start_date
  if (action.value === 'exams')
    payload = { recruitment_exam_id: actionDraft.value.recruitment_exam_id }
  if (action.value === 'interviews') {
    try {
      payload = interviewSchedule(schedule.value)
    } catch (failure) {
      recruitment.error = failure.message
      return
    }
  }
  if (await recruitment.applicationAction(action.value, payload)) {
    success.value = 'Application updated.'
    action.value = ''
    await recruitment.loadApplications(filters.value, recruitment.pagination.current_page)
  }
}
function answerFor(question) {
  const answer = recruitment.attempt?.answers?.find(
    (item) => item.question_id === question.id,
  )?.answer
  return answer === undefined
    ? 'No answer recorded'
    : question.type === 'multiple_choice'
      ? question.options[Number(answer)]
      : answer
}
async function submitGrades() {
  success.value = ''
  if (!gradesComplete.value) return
  if (
    await recruitment.gradeAttempt(writtenGrades(recruitment.attempt.questions, gradeDraft.value))
  ) {
    success.value = 'Final grade saved and released to the applicant.'
    await recruitment.loadApplications(filters.value, recruitment.pagination.current_page)
  }
}
async function updateInterview() {
  if (!interviewAction.value) return
  const { interview, action: selectedAction } = interviewAction.value
  if (
    await recruitment.interviewAction(interview, selectedAction, {
      private_notes: interviewNotes.value.trim() || null,
    })
  ) {
    interviewAction.value = null
    interviewNotes.value = ''
    success.value = `Interview ${selectedAction === 'complete' ? 'completed' : 'cancelled'}.`
    await recruitment.loadApplications(filters.value, recruitment.pagination.current_page)
  }
}
function beginInterviewAction(interview, selectedAction) {
  interviewAction.value = { interview, action: selectedAction }
  interviewNotes.value = ''
}
</script>
<template>
  <div class="flex flex-wrap gap-4 justify-between items-start mb-6">
    <div>
      <span class="eyebrow">RECRUITMENT</span>
      <h1 class="mt-3">Applications & assessments</h1>
      <p class="muted">
        Review external applicants, assign assessments, conduct interviews, and confirm start dates.
      </p>
    </div>
    <Button
      v-if="recruitment.canManage"
      label="Create assessment"
      icon="pi pi-plus"
      :disabled="recruitment.busy"
      @click="examVisible = true"
    />
  </div>
  <Message v-if="recruitment.error" severity="error" :closable="false" class="mb-4"
    >{{ recruitment.error
    }}<small v-for="(messages, field) in recruitment.fieldErrors" :key="field" class="block mt-1"
      >{{ field }}: {{ Array.isArray(messages) ? messages.join(' ') : messages }}</small
    ></Message
  >
  <Message v-if="success" severity="success" :closable="false" class="mb-4">{{ success }}</Message>
  <JobOpeningsPanel />
  <form
    class="flex flex-wrap items-end gap-3 mb-5"
    @submit.prevent="recruitment.loadApplications(filters)"
  >
    <label class="field-label"
      >Search<InputText
        v-model.trim="filters.search"
        placeholder="Applicant or position"
        maxlength="180" /></label
    ><label class="field-label"
      >Status<Select
        v-model="filters.status"
        :options="statuses"
        option-label="label"
        option-value="value"
        placeholder="All statuses"
        show-clear /></label
    ><Button
      type="submit"
      label="Apply filters"
      icon="pi pi-filter"
      :loading="recruitment.loading"
    />
  </form>
  <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]">
    <section class="recruitment-panel">
      <DataTable
        :value="recruitment.applications"
        :loading="recruitment.loading"
        data-key="id"
        responsive-layout="scroll"
        ><template #empty>No applications match your filters.</template
        ><Column header="Applicant"
          ><template #body="{ data }"
            ><b>{{ data.applicant?.name }}</b
            ><small class="block muted">{{ data.desired_position }}</small>
            <small class="block muted">{{
              formatMonthlySalary(data.monthly_salary)
            }}</small></template
          ></Column
        ><Column header="Status"
          ><template #body="{ data }"
            ><Tag
              :value="labelFor(data.status)"
              :severity="statusSeverity(data.status)" /></template></Column
        ><Column header="Actions" header-class="table-actions-cell" body-class="table-actions-cell"
          ><template #body="{ data }"
            ><Button
              icon="pi pi-eye"
              text
              :aria-label="`Review ${data.applicant?.name}`"
              :disabled="recruitment.busy"
              @click="recruitment.selectApplication(data.id)" /></template></Column></DataTable
      ><Paginator
        :rows="15"
        :first="(recruitment.pagination.current_page - 1) * 15"
        :total-records="recruitment.pagination.total"
        @page="recruitment.loadApplications(filters, $event.page + 1)"
      />
    </section>
    <section class="recruitment-panel">
      <p v-if="recruitment.detailLoading" role="status" class="muted">Loading application…</p>
      <p v-else-if="!recruitment.selected" class="muted">
        Select an application to review its details.
      </p>
      <template v-else
        ><div class="flex flex-wrap gap-3 justify-between">
          <div>
            <h2>{{ recruitment.selected.applicant?.name }}</h2>
            <p class="muted mb-2">
              {{ recruitment.selected.applicant?.email
              }}<span v-if="recruitment.selected.applicant?.mobile_number">
                · {{ recruitment.selected.applicant.mobile_number }}</span
              >
            </p>
          </div>
          <div class="flex items-center gap-2">
            <Tag
              :value="labelFor(recruitment.selected.status)"
              :severity="statusSeverity(recruitment.selected.status)"
            /><Button
              icon="pi pi-refresh"
              text
              aria-label="Refresh application details"
              :disabled="recruitment.busy"
              @click="recruitment.selectApplication(recruitment.selected.id)"
            />
          </div>
        </div>
        <h3>{{ recruitment.selected.desired_position }}</h3>
        <p class="muted text-sm">{{ formatMonthlySalary(recruitment.selected.monthly_salary) }}</p>
        <p v-if="recruitment.selected.cover_letter" class="whitespace-pre-wrap text-sm">
          {{ recruitment.selected.cover_letter }}
        </p>
        <p v-if="recruitment.selected.remarks" class="muted text-sm whitespace-pre-wrap">
          {{ recruitment.selected.remarks }}
        </p>
        <Message v-if="recruitment.selected.start_date" severity="success" :closable="false"
          >Confirmed start date: <b>{{ recruitment.selected.start_date }}</b></Message
        >
        <div class="flex flex-wrap gap-2 my-5">
          <Button
            v-if="recruitment.selected.abilities?.approve"
            label="Approve"
            icon="pi pi-check"
            :disabled="recruitment.busy"
            @click="action = 'approve'"
          /><Button
            v-if="recruitment.selected.abilities?.reject"
            label="Reject"
            severity="danger"
            outlined
            :disabled="recruitment.busy"
            @click="action = 'reject'"
          /><Button
            v-if="recruitment.selected.abilities?.assign_exam"
            label="Assign assessment"
            outlined
            :disabled="recruitment.busy"
            @click="action = 'exams'"
          /><Button
            v-if="recruitment.selected.abilities?.schedule_interview"
            label="Schedule interview"
            outlined
            :disabled="recruitment.busy"
            @click="action = 'interviews'"
          /><Button
            v-if="recruitment.selected.abilities?.hire"
            label="Confirm hire"
            icon="pi pi-user-plus"
            :disabled="recruitment.busy"
            @click="action = 'hire'"
          />
        </div>
        <form v-if="action" class="form-stack action-form mb-5" @submit.prevent="performAction">
          <h3>
            {{
              action === 'exams'
                ? 'Assign assessment'
                : action === 'interviews'
                  ? 'Schedule interview'
                  : `${labelFor(action)} application`
            }}
          </h3>
          <label v-if="action === 'exams'" class="field-label"
            >Assessment<Select
              v-model="actionDraft.recruitment_exam_id"
              :options="recruitment.exams.filter((exam) => exam.is_active)"
              option-label="title"
              option-value="id"
              placeholder="Choose an assessment"
              required
              :disabled="recruitment.busy"
              fluid
          /></label>
          <label v-if="action === 'hire'" class="field-label"
            >Confirmed start date<input
              v-model="actionDraft.start_date"
              type="date"
              required
              :disabled="recruitment.busy"
          /></label>
          <template v-if="action === 'interviews'"
            ><label class="field-label"
              >Round<Select
                v-model="schedule.round"
                :options="[
                  { label: 'Initial', value: 'initial' },
                  { label: 'Final', value: 'final' },
                ]"
                option-label="label"
                option-value="value"
                :disabled="recruitment.busy"
                fluid /></label
            ><label class="field-label"
              >Local date and time<input
                v-model="schedule.scheduled_at"
                type="datetime-local"
                required
                :disabled="recruitment.busy" /></label
            ><label class="field-label"
              >Duration (minutes)<InputNumber
                v-model="schedule.duration_minutes"
                :min="15"
                :max="120"
                :disabled="recruitment.busy"
                required
                fluid /></label
            ><label class="field-label"
              >Interviewers (choose 1–4)<MultiSelect
                v-model="schedule.interviewer_ids"
                :options="recruitment.interviewers"
                option-label="name"
                option-value="id"
                :selection-limit="4"
                :disabled="recruitment.busy"
                filter
                fluid /></label
            ><label class="field-label"
              >Private staff notes<Textarea
                v-model="schedule.private_notes"
                rows="3"
                maxlength="5000"
                :disabled="recruitment.busy"
                fluid /></label
            ><small class="muted"
              >A final interview can be scheduled after the initial interview is completed.</small
            ></template
          >
          <label v-if="!['exams', 'interviews'].includes(action)" class="field-label"
            >Remarks <small class="muted">Optional</small
            ><Textarea
              v-model="actionDraft.remarks"
              rows="3"
              maxlength="5000"
              :disabled="recruitment.busy"
              fluid
          /></label>
          <div class="flex gap-2">
            <Button
              type="submit"
              label="Confirm"
              :loading="recruitment.busy"
              :disabled="
                (action === 'exams' && !actionDraft.recruitment_exam_id) ||
                (action === 'interviews' && !schedule.interviewer_ids.length)
              "
            /><Button label="Cancel" text :disabled="recruitment.busy" @click="action = ''" />
          </div>
        </form>
        <section v-if="recruitment.selected.attempts?.length" class="mt-5">
          <h3>Assigned assessments</h3>
          <div
            v-for="attempt in recruitment.selected.attempts"
            :key="attempt.id"
            class="assessment-row"
          >
            <div>
              <b>{{ attempt.title }}</b
              ><small class="block muted">{{
                finalResult(attempt) || labelFor(attempt.status)
              }}</small>
            </div>
            <Button
              :label="attempt.abilities?.grade ? 'Review & grade' : 'View answers'"
              outlined
              size="small"
              :disabled="recruitment.busy"
              @click="recruitment.loadAttempt(attempt.id)"
            />
          </div>
        </section>
        <section v-if="recruitment.interviews.length" class="mt-5">
          <h3>Interviews</h3>
          <article
            v-for="interview in recruitment.interviews"
            :key="interview.id"
            class="assessment-row flex-col items-start"
          >
            <div>
              <b>{{ labelFor(interview.round) }} interview</b
              ><small class="block muted"
                >{{ interviewTime(interview.scheduled_at) }} ·
                {{ interview.duration_minutes }} minutes · {{ labelFor(interview.status) }}</small
              ><small class="block muted">{{
                interview.interviewers.map((person) => person.name).join(', ')
              }}</small>
            </div>
            <div class="flex flex-wrap gap-2">
              <RouterLink
                v-if="interview.abilities?.join"
                :to="`/recruitment/interviews/${interview.id}`"
                ><Button label="Join" icon="pi pi-video" size="small" /></RouterLink
              ><Button
                v-if="interview.abilities?.complete"
                label="Complete"
                size="small"
                outlined
                :disabled="recruitment.busy"
                @click="beginInterviewAction(interview, 'complete')"
              /><Button
                v-if="interview.abilities?.cancel"
                label="Cancel interview"
                size="small"
                text
                severity="danger"
                :disabled="recruitment.busy"
                @click="beginInterviewAction(interview, 'cancel')"
              />
            </div>
          </article>
        </section>
        <form
          v-if="interviewAction"
          class="form-stack action-form mt-4"
          @submit.prevent="updateInterview"
        >
          <h3>
            {{ interviewAction.action === 'complete' ? 'Complete interview' : 'Cancel interview' }}
          </h3>
          <p class="text-sm muted">
            Confirm this action for the {{ interviewAction.interview.round }} interview.
          </p>
          <label class="field-label"
            >Private staff notes<Textarea
              v-model="interviewNotes"
              rows="3"
              maxlength="5000"
              :disabled="recruitment.busy"
              fluid
          /></label>
          <div class="flex gap-2">
            <Button type="submit" label="Confirm" :loading="recruitment.busy" /><Button
              label="Back"
              text
              :disabled="recruitment.busy"
              @click="interviewAction = null"
            />
          </div>
        </form>
      </template>
    </section>
  </div>
  <Dialog
    v-model:visible="examVisible"
    modal
    header="Create assessment"
    :style="{ width: '850px', maxWidth: '95vw' }"
    :closable="!recruitment.busy"
    :close-on-escape="!recruitment.busy"
    ><form class="form-stack" @submit.prevent="createExam">
      <Message v-if="recruitment.error" severity="error" :closable="false"
        >{{ recruitment.error
        }}<small v-for="(messages, field) in recruitment.fieldErrors" :key="field" class="block"
          >{{ field }}: {{ messages.join(' ') }}</small
        ></Message
      ><label class="field-label"
        >Title<InputText
          v-model.trim="examDraft.title"
          maxlength="180"
          required
          :disabled="recruitment.busy"
          fluid /></label
      ><label class="field-label"
        >Instructions<Textarea
          v-model="examDraft.instructions"
          rows="3"
          maxlength="10000"
          :disabled="recruitment.busy"
          fluid /></label
      ><label class="field-label"
        >Passing percentage<InputNumber
          v-model="examDraft.passing_percentage"
          :min="0"
          :max="100"
          :max-fraction-digits="2"
          required
          :disabled="recruitment.busy"
          fluid
      /></label>
      <article
        v-for="(question, index) in examDraft.questions"
        :key="question.key"
        class="question-editor"
      >
        <div class="flex items-center justify-between gap-3">
          <h3>Question {{ index + 1 }}</h3>
          <Button
            icon="pi pi-trash"
            text
            severity="danger"
            :aria-label="`Remove question ${index + 1}`"
            :disabled="recruitment.busy || examDraft.questions.length === 1"
            @click="examDraft.questions.splice(index, 1)"
          />
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
          <label class="field-label"
            >Type<Select
              v-model="question.type"
              :options="questionTypes"
              option-label="label"
              option-value="value"
              :aria-label="`Question ${index + 1} type`"
              :disabled="recruitment.busy"
              fluid /></label
          ><label class="field-label"
            >Points<InputNumber
              v-model="question.points"
              :min="1"
              :max="100"
              :disabled="recruitment.busy"
              required
              fluid
          /></label>
        </div>
        <label class="field-label mt-4"
          >Question<Textarea
            v-model="question.prompt"
            rows="3"
            maxlength="5000"
            :disabled="recruitment.busy"
            required
            fluid
        /></label>
        <template v-if="question.type === 'multiple_choice'"
          ><div
            v-for="(option, optionIndex) in question.options"
            :key="optionIndex"
            class="flex gap-2 mt-3 items-center"
          >
            <label class="field-label grow"
              >Option {{ optionIndex + 1
              }}<InputText
                v-model="question.options[optionIndex]"
                maxlength="1000"
                required
                :disabled="recruitment.busy"
                fluid /></label
            ><Button
              icon="pi pi-times"
              text
              :aria-label="`Remove option ${optionIndex + 1}`"
              :disabled="recruitment.busy || question.options.length <= 2"
              @click="removeOption(question, optionIndex)"
            />
          </div>
          <Button
            label="Add option"
            text
            icon="pi pi-plus"
            :disabled="recruitment.busy || question.options.length >= 8"
            @click="question.options.push('')" /><label class="field-label"
            >Correct option<Select
              v-model="question.correct_option"
              :options="
                question.options.map((option, index) => ({
                  label: `Option ${index + 1}: ${option}`,
                  value: index,
                }))
              "
              option-label="label"
              option-value="value"
              :aria-label="`Question ${index + 1} correct option`"
              :disabled="recruitment.busy"
              required
              fluid /></label
        ></template>
        <p v-else class="muted text-sm mt-3 mb-0">
          Written responses are graded manually before the final grade is released.
        </p>
      </article>
      <div class="flex flex-wrap gap-3">
        <Button
          label="Add question"
          icon="pi pi-plus"
          outlined
          :disabled="recruitment.busy || examDraft.questions.length >= 50"
          @click="addQuestion"
        /><Button
          type="submit"
          label="Save assessment"
          icon="pi pi-check"
          :loading="recruitment.busy"
        />
      </div></form
  ></Dialog>
  <Dialog
    :visible="Boolean(recruitment.attempt)"
    modal
    :header="recruitment.attempt?.title || 'Assessment'"
    :style="{ width: '800px', maxWidth: '95vw' }"
    :closable="!recruitment.busy"
    :close-on-escape="!recruitment.busy"
    @update:visible="!$event && !recruitment.busy && (recruitment.attempt = null)"
    ><template v-if="recruitment.attempt"
      ><Message v-if="recruitment.error" severity="error" :closable="false"
        >{{ recruitment.error
        }}<small v-for="(messages, field) in recruitment.fieldErrors" :key="field" class="block"
          >{{ field }}: {{ messages.join(' ') }}</small
        ></Message
      ><Message v-if="finalResult(recruitment.attempt)" severity="success" :closable="false"
        >Final grade: {{ finalResult(recruitment.attempt) }}</Message
      >
      <p v-else class="muted">
        Automatic score so far: {{ recruitment.attempt.automatic_score }}. Final grading includes
        every written question.
      </p>
      <form class="form-stack" @submit.prevent="submitGrades">
        <article
          v-for="(question, index) in recruitment.attempt.questions"
          :key="question.id"
          class="question-editor"
        >
          <h3 class="whitespace-pre-wrap">
            {{ index + 1 }}. {{ question.prompt }}
            <small class="muted">({{ question.points }} points)</small>
          </h3>
          <p class="answer-text whitespace-pre-wrap">{{ answerFor(question) }}</p>
          <small v-if="question.type === 'multiple_choice'" class="muted"
            >Correct answer: {{ question.options[question.correct_option] }}</small
          ><template v-if="question.type === 'written' && recruitment.attempt.abilities?.grade"
            ><label class="field-label"
              >Score<InputNumber
                v-model="gradeDraft[question.id].score"
                :min="0"
                :max="question.points"
                :max-fraction-digits="2"
                :disabled="recruitment.busy"
                required
                fluid /></label
            ><label class="field-label mt-3"
              >Feedback <small class="muted">Optional</small
              ><Textarea
                v-model="gradeDraft[question.id].feedback"
                rows="3"
                maxlength="5000"
                :disabled="recruitment.busy"
                fluid /></label
          ></template>
          <p v-else-if="recruitment.attempt.status === 'graded'" class="muted text-sm">
            Score:
            {{ recruitment.attempt.grades?.find((item) => item.question_id === question.id)?.score
            }}<span class="block whitespace-pre-wrap">{{
              recruitment.attempt.grades?.find((item) => item.question_id === question.id)?.feedback
            }}</span>
          </p>
        </article>
        <Button
          v-if="recruitment.attempt.abilities?.grade"
          type="submit"
          label="Save & release final grade"
          icon="pi pi-check"
          :disabled="!gradesComplete"
          :loading="recruitment.busy"
        /></form></template
  ></Dialog>
</template>
<style scoped>
.recruitment-panel {
  padding: 22px;
  border: 1px solid var(--line);
  border-radius: 14px;
  background: var(--panel);
  min-width: 0;
}
.action-form,
.question-editor {
  padding: 18px;
  border: 1px solid var(--line);
  background: var(--soft);
  border-radius: 10px;
}
.assessment-row {
  display: flex;
  gap: 12px;
  align-items: center;
  justify-content: space-between;
  padding: 14px 0;
  border-bottom: 1px solid var(--line);
}
.answer-text {
  background: var(--panel);
  padding: 14px;
  border-radius: 8px;
  font-size: 13px;
}
.question-editor h3,
.question-editor p,
.question-editor > small {
  overflow-wrap: anywhere;
}
</style>
