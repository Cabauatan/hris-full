<script setup>
import { onMounted, ref } from 'vue'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Message from 'primevue/message'
import Paginator from 'primevue/paginator'
import { useApplicantStore } from '@/stores/applicant'
import { useJobOpeningsStore } from '@/stores/jobOpenings'
import { formatMonthlySalary } from '@/utils/recruitment'
const applicant = useApplicantStore()
const openings = useJobOpeningsStore()
const search = ref('')
const expanded = ref({})
function applyRoute(opening) {
  const destination = `/applicant/applications?opening=${opening.id}`
  return applicant.authenticated
    ? destination
    : { path: '/applicant/register', query: { redirect: destination } }
}
onMounted(() => {
  applicant.initialize()
  openings.loadOpenings()
})
</script>
<template>
  <section class="careers-hero grid gap-8 md:grid-cols-2 items-center">
    <div>
      <span class="eyebrow">GROW WITH US</span>
      <h1>Your next chapter starts here.</h1>
      <p class="muted">
        Explore our open positions, create an applicant account, and follow your application from
        review to your first day.
      </p>
      <div class="flex flex-wrap gap-3">
        <RouterLink
          :to="applicant.authenticated ? '/applicant/applications' : '/applicant/register'"
          ><Button
            :label="
              applicant.authenticated ? 'View my applications' : 'Create an applicant account'
            "
            icon="pi pi-arrow-right"
            icon-pos="right" /></RouterLink
        ><RouterLink v-if="!applicant.authenticated" to="/applicant/login"
          ><Button label="I already have an account" outlined
        /></RouterLink>
      </div>
    </div>
    <div class="careers-illustration">
      <img src="/hris-icon.png" alt="HRIS — people and opportunity" width="250" height="250" />
    </div>
  </section>
  <section class="mt-8" aria-label="Open positions">
    <div class="flex flex-wrap items-end justify-between gap-4 mb-5">
      <div>
        <span class="eyebrow">OPPORTUNITIES</span>
        <h2 class="mt-3">Open positions</h2>
        <p class="muted mb-0">Choose the position that fits your next step.</p>
      </div>
      <form class="flex flex-wrap gap-2" @submit.prevent="openings.loadOpenings(1, search)">
        <InputText
          v-model.trim="search"
          placeholder="Search positions"
          maxlength="180"
          aria-label="Search positions"
        />
        <Button type="submit" label="Search" icon="pi pi-search" :loading="openings.loading" />
      </form>
    </div>
    <Message v-if="openings.error" severity="error" :closable="false" class="mb-4">{{
      openings.error
    }}</Message>
    <p v-if="openings.loading" class="muted" role="status">Loading open positions…</p>
    <p v-else-if="!openings.openings.length" class="muted">
      {{
        search
          ? 'No open positions match your search.'
          : 'No positions are open right now. Please check back soon.'
      }}
    </p>
    <div class="grid gap-4 md:grid-cols-2">
      <article
        v-for="opening in openings.openings"
        :key="opening.id"
        class="careers-step opening-card"
      >
        <h3>{{ opening.title }}</h3>
        <b>{{ formatMonthlySalary(opening.monthly_salary) }}</b>
        <div class="flex flex-wrap gap-3 text-sm muted">
          <span v-if="opening.location">{{ opening.location }}</span>
          <span v-if="opening.employment_type">{{ opening.employment_type }}</span>
          <span
            >{{ opening.vacancies }} {{ opening.vacancies === 1 ? 'vacancy' : 'vacancies' }}</span
          >
        </div>
        <p class="whitespace-pre-wrap" :class="!expanded[opening.id] && 'line-clamp-4'">
          {{ opening.description }}
        </p>
        <template v-if="expanded[opening.id] && opening.requirements">
          <h4>Requirements</h4>
          <p class="whitespace-pre-wrap">{{ opening.requirements }}</p>
        </template>
        <small v-if="opening.closes_on" class="muted">Apply by {{ opening.closes_on }}</small>
        <div class="flex flex-wrap gap-2 mt-5">
          <RouterLink v-if="opening.accepting_applications" :to="applyRoute(opening)"
            ><Button
              :label="`Apply for ${opening.title}`"
              icon="pi pi-arrow-right"
              icon-pos="right"
          /></RouterLink>
          <Button
            :label="expanded[opening.id] ? 'Show less' : 'View details'"
            text
            :aria-expanded="Boolean(expanded[opening.id])"
            @click="expanded[opening.id] = !expanded[opening.id]"
          />
        </div>
      </article>
    </div>
    <Paginator
      v-if="openings.pagination.total > 15"
      :rows="15"
      :first="(openings.pagination.current_page - 1) * 15"
      :total-records="openings.pagination.total"
      @page="openings.loadOpenings($event.page + 1, search)"
    />
  </section>
  <section class="grid gap-4 md:grid-cols-3 mt-10" aria-label="Application process">
    <article class="careers-step">
      <i class="pi pi-user-plus" />
      <h2>1. Apply</h2>
      <p>Create your account and choose from positions posted by HR.</p>
    </article>
    <article class="careers-step">
      <i class="pi pi-file-check" />
      <h2>2. Complete your assessment</h2>
      <p>
        After approval, take your assigned exam and join scheduled interviews. Your final grade
        appears when grading is complete.
      </p>
    </article>
    <article class="careers-step">
      <i class="pi pi-calendar" />
      <h2>3. See your start date</h2>
      <p>
        Follow your application status. When hired, your confirmed start date appears in your
        portal.
      </p>
    </article>
  </section>
</template>
<style scoped>
.careers-hero {
  padding: 32px 0;
}
.careers-hero h1 {
  font-size: clamp(32px, 5vw, 52px);
  line-height: 1.12;
  max-width: 530px;
  margin: 14px 0 20px;
}
.careers-hero p {
  max-width: 530px;
  margin-bottom: 28px;
}
.careers-illustration {
  display: grid;
  place-items: center;
  min-height: 280px;
  background: var(--accent-soft);
  border-radius: 28px;
}
.careers-step {
  background: var(--panel);
  border: 1px solid var(--line);
  border-radius: 14px;
  padding: 24px;
}
.careers-step i {
  font-size: 22px;
  color: var(--accent);
  display: block;
  margin-bottom: 18px;
}
.careers-step p {
  color: var(--muted);
  margin-bottom: 0;
  font-size: 13px;
}
.opening-card {
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.opening-card h3,
.opening-card h4 {
  margin: 0;
}
.opening-card p {
  margin: 0;
}
</style>
