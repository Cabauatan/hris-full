<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import Button from 'primevue/button'
import Message from 'primevue/message'
import RadioButton from 'primevue/radiobutton'
import Textarea from 'primevue/textarea'
import Tag from 'primevue/tag'
import { useApplicantStore } from '@/stores/applicant'
import { examAnswers, finalResult } from '@/utils/recruitment'
import { labelFor, statusSeverity } from '@/utils/forms'
const applicant = useApplicantStore()
const route = useRoute()
const answers = ref({})
const confirmation = ref(false)
const attempt = computed(() => applicant.attempt)
const result = computed(() => finalResult(attempt.value))
const editable = computed(() => attempt.value?.abilities?.submit === true && !applicant.saving)
const complete = computed(
  () =>
    attempt.value?.questions?.every(
      (question) => String(answers.value[question.id] ?? '').trim() !== '',
    ) === true,
)
watch(
  () => [route.params.attemptId, applicant.user?.id],
  () => {
    answers.value = {}
    confirmation.value = false
    if (applicant.authenticated) applicant.loadExam(route.params.attemptId)
  },
  { immediate: true },
)
function answerLabel(question) {
  const answer = attempt.value?.answers?.find((item) => item.question_id === question.id)?.answer
  return answer === undefined
    ? 'No answer recorded'
    : question.type === 'multiple_choice'
      ? question.options[Number(answer)]
      : answer
}
function grade(question) {
  return result.value
    ? attempt.value.grades?.find((item) => item.question_id === question.id)
    : null
}
async function submit() {
  if (!complete.value || !confirmation.value || !editable.value) return
  if (await applicant.submitExam(examAnswers(attempt.value.questions, answers.value))) {
    answers.value = {}
    confirmation.value = false
  }
}
</script>
<template>
  <RouterLink to="/applicant/applications" class="muted text-sm"
    ><i class="pi pi-arrow-left" /> Back to my applications</RouterLink
  >
  <Message v-if="applicant.error" severity="error" :closable="false" class="my-4"
    >{{ applicant.error
    }}<small
      v-for="message in Object.values(applicant.fieldErrors).flat()"
      :key="message"
      class="block mt-1"
      >{{ message }}</small
    ></Message
  >
  <p v-if="applicant.examLoading" role="status" class="mt-6 muted">Loading assessment…</p>
  <section v-else-if="attempt" class="exam-card mt-5">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <span class="eyebrow">YOUR ASSESSMENT</span>
        <h1 class="mt-3">{{ attempt.title }}</h1>
        <p class="muted">
          {{ attempt.max_score }} possible points · Passing grade {{ attempt.passing_percentage }}%
        </p>
      </div>
      <div class="flex items-center gap-3">
        <Tag :value="labelFor(attempt.status)" :severity="statusSeverity(attempt.status)" /><Button
          v-if="!attempt.abilities?.submit"
          label="Refresh result"
          icon="pi pi-refresh"
          outlined
          :disabled="applicant.saving"
          @click="applicant.loadExam(route.params.attemptId)"
        />
      </div>
    </div>
    <p v-if="attempt.instructions" class="whitespace-pre-wrap">{{ attempt.instructions }}</p>
    <Message v-if="result" :severity="attempt.passed ? 'success' : 'info'" :closable="false"
      ><b>Your final grade: {{ result }}</b
      ><span class="block">{{ attempt.passed ? 'Passed' : 'Below passing grade' }}</span></Message
    >
    <Message v-else-if="attempt.status === 'submitted'" severity="info" :closable="false"
      >Your answers were submitted. Your final grade will appear after written answers have been
      reviewed.</Message
    >
    <form @submit.prevent="submit">
      <article
        v-for="(question, index) in attempt.questions"
        :key="question.id"
        class="exam-question"
      >
        <div class="flex gap-3 justify-between">
          <h2 class="whitespace-pre-wrap">{{ index + 1 }}. {{ question.prompt }}</h2>
          <small class="muted shrink-0">{{ question.points }} points</small>
        </div>
        <template v-if="attempt.abilities?.submit"
          ><div v-if="question.type === 'multiple_choice'" class="grid gap-3">
            <label
              v-for="(option, optionIndex) in question.options"
              :key="optionIndex"
              class="exam-option"
              ><RadioButton
                v-model="answers[question.id]"
                :input-id="`${question.id}-${optionIndex}`"
                :name="question.id"
                :value="String(optionIndex)"
                :disabled="!editable"
              /><span>{{ option }}</span></label
            >
          </div>
          <Textarea
            v-else
            v-model="answers[question.id]"
            rows="6"
            maxlength="10000"
            :disabled="!editable"
            required
            fluid
        /></template>
        <template v-else
          ><p class="answer-text whitespace-pre-wrap">{{ answerLabel(question) }}</p>
          <small v-if="grade(question)" class="muted"
            >Score: {{ grade(question).score }} / {{ question.points }}</small
          >
          <p v-if="grade(question)?.feedback" class="text-sm whitespace-pre-wrap mt-2">
            {{ grade(question).feedback }}
          </p></template
        >
      </article>
      <template v-if="attempt.abilities?.submit"
        ><label class="flex gap-3 items-start text-sm mb-5"
          ><input
            v-model="confirmation"
            type="checkbox"
            :disabled="applicant.saving"
            required
          /><span
            >I have reviewed my answers. I understand I can submit this assessment once.</span
          ></label
        ><Button
          type="submit"
          label="Submit final answers"
          icon="pi pi-send"
          :loading="applicant.saving"
          :disabled="!complete || !confirmation"
      /></template>
    </form>
  </section>
</template>
<style scoped>
.exam-card {
  padding: 28px;
  border-radius: 16px;
  border: 1px solid var(--line);
  background: var(--panel);
}
.exam-question {
  padding: 24px 0;
  border-bottom: 1px solid var(--line);
  margin-bottom: 20px;
}
.exam-question h2 {
  font-size: 16px;
}
.exam-option {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px;
  border: 1px solid var(--line);
  border-radius: 8px;
  cursor: pointer;
}
.answer-text {
  background: var(--soft);
  border-radius: 8px;
  padding: 16px;
}
</style>
