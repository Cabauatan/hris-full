<script setup>
import { computed, onMounted, ref } from 'vue'
import Button from 'primevue/button'
import Password from 'primevue/password'
import Dialog from 'primevue/dialog'
import Message from 'primevue/message'
import Skeleton from 'primevue/skeleton'
import RecordDetails from '@/components/RecordDetails.vue'
import TelegramConnectionCard from '@/components/TelegramConnectionCard.vue'
import { useAuthStore } from '@/stores/auth'
import http, { apiError } from '@/api/http'
import { displayRecord } from '@/utils/records'
const auth = useAuthStore()
const profile = ref(null)
const profileDetails = computed(() => (profile.value ? displayRecord(profile.value) : null))
const busy = ref(true)
const error = ref('')
const passwordOpen = ref(false)
const saving = ref(false)
const passwordError = ref('')
const passwordErrors = ref({})
const passwordSuccess = ref(false)
const form = ref({ current_password: '', password: '', password_confirmation: '' })
onMounted(async () => {
  if (auth.employee) {
    try {
      profile.value = (await http.get('/ess/profile')).data.data
    } catch (failure) {
      error.value = apiError(failure).message
    }
  }
  busy.value = false
})
function openPassword() {
  form.value = { current_password: '', password: '', password_confirmation: '' }
  passwordError.value = ''
  passwordErrors.value = {}
  passwordOpen.value = true
}
async function savePassword() {
  saving.value = true
  passwordError.value = ''
  passwordErrors.value = {}
  try {
    await auth.changePassword(form.value)
    form.value = { current_password: '', password: '', password_confirmation: '' }
    passwordOpen.value = false
    passwordSuccess.value = true
  } catch (failure) {
    const detail = apiError(failure)
    passwordError.value = detail.message
    passwordErrors.value = detail.errors
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="page-heading">
    <div>
      <span class="eyebrow">PERSONAL WORKSPACE</span>
      <h1>My profile</h1>
      <p>Your employee information and account security.</p>
    </div>
    <Button label="Change password" icon="pi pi-lock" outlined @click="openPassword" />
  </div>
  <Message v-if="passwordSuccess" severity="success" :closable="true"
    >Your password has been updated.</Message
  ><Message v-if="error" severity="error" :closable="false">{{ error }}</Message>
  <section class="profile-summary panel">
    <span class="profile-avatar">{{ auth.user?.name?.slice(0, 1)?.toUpperCase() }}</span>
    <div>
      <h2>{{ auth.user?.name }}</h2>
      <p>{{ auth.user?.email }}</p>
      <small>{{ auth.employee?.employee_number || 'Administrator account' }}</small>
    </div>
  </section>
  <TelegramConnectionCard scope="employee" />
  <section class="panel">
    <div class="panel-heading">
      <div>
        <h2>Employee information</h2>
        <p>Your records are managed by your HR team.</p>
      </div>
      <RouterLink
        v-if="
          auth.employee && auth.can('profile-changes.create') && auth.can('profile-changes.view')
        "
        to="/my/requests/profile-changes"
        ><Button label="Request a profile change" icon="pi pi-pencil" outlined
      /></RouterLink>
    </div>
    <Skeleton v-if="busy" height="12rem" /><RecordDetails
      v-else-if="profileDetails"
      :record="profileDetails"
    />
    <div v-else class="empty-state">
      <span class="empty-icon"><i class="pi pi-id-card" /></span><b>No employee profile linked</b>
      <p>Ask your HR administrator to link an employee profile to your account.</p>
    </div>
  </section>
  <Dialog
    v-model:visible="passwordOpen"
    header="Change password"
    modal
    :closable="!saving"
    :close-on-escape="!saving"
    :style="{ width: '30rem' }"
    :breakpoints="{ '600px': '94vw' }"
    ><form class="form-stack" @submit.prevent="savePassword">
      <p class="muted">Choose a new password with at least 8 characters.</p>
      <label
        v-for="field in [
          { key: 'current_password', label: 'Current password' },
          { key: 'password', label: 'New password' },
          { key: 'password_confirmation', label: 'Confirm new password' },
        ]"
        :key="field.key"
        class="field-label"
        :for="field.key"
        >{{ field.label
        }}<Password
          :input-id="field.key"
          v-model="form[field.key]"
          :feedback="false"
          toggle-mask
          :autocomplete="field.key === 'current_password' ? 'current-password' : 'new-password'"
          :disabled="saving"
          required
          fluid
        /><small
          v-for="message in passwordErrors[field.key] || []"
          :key="message"
          class="field-error"
          >{{ message }}</small
        ></label
      ><Message v-if="passwordError" severity="error" :closable="false">{{
        passwordError
      }}</Message>
      <div class="dialog-actions">
        <Button
          type="button"
          label="Cancel"
          severity="secondary"
          outlined
          :disabled="saving"
          @click="passwordOpen = false"
        /><Button type="submit" label="Update password" :loading="saving" />
      </div></form
  ></Dialog>
</template>
