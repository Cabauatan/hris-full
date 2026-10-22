<script setup>
import TelegramConnectionCard from '@/components/TelegramConnectionCard.vue'
import { useApplicantStore } from '@/stores/applicant'
const applicant = useApplicantStore()
</script>

<template>
  <div class="page-heading">
    <div>
      <span class="eyebrow">APPLICANT PORTAL</span>
      <h1>Account settings</h1>
      <p>Manage where you receive recruitment updates.</p>
    </div>
  </div>
  <section class="panel">
    <h2>{{ applicant.user?.name }}</h2>
    <p>{{ applicant.user?.email }}</p>
  </section>
  <TelegramConnectionCard scope="applicant" />
</template>
