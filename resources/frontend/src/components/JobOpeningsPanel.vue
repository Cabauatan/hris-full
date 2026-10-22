<script setup>
import { ref, watch } from 'vue'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Paginator from 'primevue/paginator'
import Dialog from 'primevue/dialog'
import Message from 'primevue/message'
import Tag from 'primevue/tag'
import { useAuthStore } from '@/stores/auth'
import { useRecruitmentStore } from '@/stores/recruitment'
import { formatMonthlySalary, hasMonthlySalary, jobOpeningPayload } from '@/utils/recruitment'
const auth = useAuthStore()
const recruitment = useRecruitmentStore()
const filters = ref({ status: null, search: '' })
const visible = ref(false)
const editing = ref(null)
const success = ref('')
const statuses = [
  { value: 'open', label: 'Open' },
  { value: 'closed', label: 'Closed' },
]
function emptyDraft() {
  return {
    title: '',
    description: '',
    requirements: '',
    location: '',
    employment_type: '',
    monthly_salary: '',
    vacancies: 1,
    status: 'open',
    closes_on: '',
  }
}
const draft = ref(emptyDraft())
watch(
  () => auth.user?.id,
  () => {
    visible.value = false
    editing.value = null
    draft.value = emptyDraft()
    success.value = ''
    if (auth.authenticated) recruitment.loadJobOpenings(filters.value)
  },
  { immediate: true },
)
function beginEdit(opening = null) {
  if (!recruitment.canManage || (opening && !opening.abilities?.update)) return
  editing.value = opening
  draft.value = opening
    ? {
        title: opening.title,
        description: opening.description,
        requirements: opening.requirements || '',
        location: opening.location || '',
        employment_type: opening.employment_type || '',
        monthly_salary: opening.monthly_salary || '',
        vacancies: opening.vacancies,
        status: opening.status,
        closes_on: opening.closes_on || '',
      }
    : emptyDraft()
  recruitment.openingsError = ''
  recruitment.openingErrors = {}
  visible.value = true
}
async function save() {
  success.value = ''
  const account = auth.user?.id
  const opening = editing.value
  const saved = await recruitment.saveJobOpening(jobOpeningPayload(draft.value), opening)
  if (!saved || account !== auth.user?.id) return
  visible.value = false
  editing.value = null
  draft.value = emptyDraft()
  success.value = opening ? 'Position updated.' : 'Position posted. Applicants can now choose it.'
  if (saved.status === 'closed') success.value = 'Position saved as closed.'
  await recruitment.loadJobOpenings(filters.value, recruitment.openingPagination.current_page)
}
</script>
<template>
  <section class="openings-panel mb-8" aria-label="Job positions">
    <div class="flex flex-wrap items-start justify-between gap-4 mb-5">
      <div>
        <span class="eyebrow">JOB POSITIONS</span>
        <h2 class="mt-3">Positions applicants can choose</h2>
        <p class="muted mb-0">
          Post available positions and manage which postings accept applications.
        </p>
      </div>
      <div class="flex flex-wrap gap-2">
        <RouterLink to="/careers"
          ><Button label="View careers page" icon="pi pi-external-link" outlined
        /></RouterLink>
        <Button
          v-if="recruitment.canManage"
          label="Post a position"
          icon="pi pi-plus"
          :disabled="recruitment.openingsBusy"
          @click="beginEdit()"
        />
      </div>
    </div>
    <Message v-if="success" severity="success" :closable="false" class="mb-4">{{
      success
    }}</Message>
    <Message
      v-if="recruitment.openingsError && !visible"
      severity="error"
      :closable="false"
      class="mb-4"
      >{{ recruitment.openingsError }}</Message
    >
    <form
      class="flex flex-wrap items-end gap-3 mb-4"
      @submit.prevent="recruitment.loadJobOpenings(filters)"
    >
      <label class="field-label"
        >Position search<InputText
          v-model.trim="filters.search"
          maxlength="180"
          placeholder="Title or location"
      /></label>
      <label class="field-label"
        >Posting status<Select
          v-model="filters.status"
          :options="statuses"
          option-label="label"
          option-value="value"
          show-clear
          placeholder="All postings"
      /></label>
      <Button
        type="submit"
        label="Refresh positions"
        icon="pi pi-refresh"
        outlined
        :loading="recruitment.openingsLoading"
      />
    </form>
    <DataTable
      :value="recruitment.jobOpenings"
      :loading="recruitment.openingsLoading"
      data-key="id"
      responsive-layout="scroll"
    >
      <template #empty
        >No positions match your filters. Post a position to start receiving applications.</template
      >
      <Column header="Position"
        ><template #body="{ data }"
          ><b>{{ data.title }}</b
          ><small class="block muted">{{
            [data.location, data.employment_type].filter(Boolean).join(' · ') ||
            'Location and employment type not specified'
          }}</small></template
        ></Column
      >
      <Column field="vacancies" header="Vacancies" />
      <Column header="Fixed monthly salary"
        ><template #body="{ data }">{{
          formatMonthlySalary(data.monthly_salary)
        }}</template></Column
      >
      <Column header="Status"
        ><template #body="{ data }"
          ><Tag
            :value="
              !hasMonthlySalary(data.monthly_salary)
                ? 'Salary required'
                : data.status === 'closed'
                  ? 'Closed'
                  : data.accepting_applications
                    ? 'Open'
                    : 'Expired'
            "
            :severity="data.accepting_applications ? 'success' : 'secondary'" /></template
      ></Column>
      <Column header="Apply by"
        ><template #body="{ data }">{{ data.closes_on || 'No closing date' }}</template></Column
      >
      <Column header="Actions" header-class="table-actions-cell" body-class="table-actions-cell"
        ><template #body="{ data }"
          ><Button
            v-if="data.abilities?.update && recruitment.canManage"
            label="Edit"
            icon="pi pi-pencil"
            text
            :aria-label="`Edit ${data.title}`"
            :disabled="recruitment.openingsBusy"
            @click="beginEdit(data)" /></template
      ></Column>
    </DataTable>
    <Paginator
      v-if="recruitment.openingPagination.total > 15"
      :rows="15"
      :first="(recruitment.openingPagination.current_page - 1) * 15"
      :total-records="recruitment.openingPagination.total"
      @page="recruitment.loadJobOpenings(filters, $event.page + 1)"
    />
  </section>
  <Dialog
    v-model:visible="visible"
    modal
    :header="editing ? 'Edit position' : 'Post a position'"
    :style="{ width: '720px', maxWidth: '95vw' }"
    :closable="!recruitment.openingsBusy"
    :close-on-escape="!recruitment.openingsBusy"
  >
    <Message v-if="recruitment.openingsError" severity="error" :closable="false" class="mb-4"
      >{{ recruitment.openingsError
      }}<small
        v-for="(messages, field) in recruitment.openingErrors"
        :key="field"
        class="block mt-1"
        >{{ field }}: {{ Array.isArray(messages) ? messages.join(' ') : messages }}</small
      ></Message
    >
    <form class="form-stack" @submit.prevent="save">
      <label class="field-label"
        >Position title<InputText
          v-model="draft.title"
          maxlength="180"
          required
          :disabled="recruitment.openingsBusy"
          fluid
      /></label>
      <label class="field-label"
        >Job description<Textarea
          v-model="draft.description"
          rows="5"
          maxlength="10000"
          required
          :disabled="recruitment.openingsBusy"
          fluid
      /></label>
      <label class="field-label"
        >Requirements<Textarea
          v-model="draft.requirements"
          rows="4"
          maxlength="10000"
          :disabled="recruitment.openingsBusy"
          fluid
      /></label>
      <div class="grid gap-4 sm:grid-cols-2">
        <label class="field-label">
          Fixed monthly salary (PHP)<InputText
            v-model="draft.monthly_salary"
            inputmode="decimal"
            pattern="[0-9]{1,13}(\.[0-9]{1,2})?"
            maxlength="16"
            required
            :disabled="recruitment.openingsBusy"
            fluid
          />
        </label>
        <label class="field-label"
          >Location<InputText
            v-model="draft.location"
            maxlength="180"
            :disabled="recruitment.openingsBusy"
            fluid
        /></label>
        <label class="field-label"
          >Employment type<InputText
            v-model="draft.employment_type"
            maxlength="50"
            :disabled="recruitment.openingsBusy"
            fluid
        /></label>
        <label class="field-label"
          >Vacancies<InputNumber
            v-model="draft.vacancies"
            :min="1"
            :max="1000"
            :max-fraction-digits="0"
            required
            :disabled="recruitment.openingsBusy"
            fluid
        /></label>
        <label class="field-label"
          >Posting status<Select
            v-model="draft.status"
            :options="statuses"
            option-label="label"
            option-value="value"
            aria-label="Posting status"
            required
            :disabled="recruitment.openingsBusy"
            fluid
        /></label>
        <label class="field-label"
          >Closing date<InputText
            v-model="draft.closes_on"
            type="date"
            :disabled="recruitment.openingsBusy"
            fluid
        /></label>
      </div>
      <Button
        type="submit"
        :label="editing ? 'Save position' : 'Post position'"
        icon="pi pi-check"
        :loading="recruitment.openingsBusy"
      />
    </form>
  </Dialog>
</template>
<style scoped>
.openings-panel {
  padding: 24px;
  border: 1px solid var(--line);
  border-radius: 14px;
  background: var(--panel);
}
</style>
