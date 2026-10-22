<script setup>
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import Checkbox from 'primevue/checkbox'
import Button from 'primevue/button'
import Message from 'primevue/message'
import AuthFrame from '@/layout/AuthFrame.vue'
import { useAuthStore } from '@/stores/auth'
import { apiError } from '@/api/http'
import { safeRedirect } from '@/utils/forms'
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const form = ref({ employee_number: '', password: '', remember: false })
const busy = ref(false)
const error = ref('')
const errors = ref({})
async function submit() {
  busy.value = true
  error.value = ''
  errors.value = {}
  try {
    await auth.signIn({
      employee_number: form.value.employee_number.trim(),
      password: form.value.password,
      remember: form.value.remember,
    })
    await router.replace(safeRedirect(route.query.redirect, '/dashboard'))
  } catch (failure) {
    const detail = apiError(failure)
    error.value = detail.message
    errors.value = detail.errors
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <AuthFrame>
    <div class="auth-card">
      <span class="eyebrow">WELCOME TO HRIS</span>
      <h2>Sign in to your workspace</h2>
      <p class="muted">Use the account provided by your organization.</p>
      <RouterLink to="/careers" class="applicant-portal">
        <span class="applicant-portal-icon"><i class="pi pi-briefcase" aria-hidden="true" /></span>
        <span class="applicant-portal-copy">
          <strong>Applicant portal</strong>
          <span>Looking for a job? Browse open positions and apply.</span>
        </span>
        <i class="pi pi-arrow-right" aria-hidden="true" />
      </RouterLink>
      <Message v-if="route.query.expired" severity="warn" :closable="false"
        >Your session has ended. Please sign in again.</Message
      >
      <Message v-if="auth.connectionError" severity="warn" :closable="false">{{
        auth.connectionError
      }}</Message>
      <form class="form-stack" @submit.prevent="submit">
        <label class="field-label" for="login-employee-number"
          >Employee number
          <InputText
            id="login-employee-number"
            v-model.trim="form.employee_number"
            type="text"
            autocomplete="username"
            placeholder="EMP-0001"
            maxlength="50"
            :disabled="busy"
            required
            fluid
          /><small
            v-for="message in errors.employee_number || []"
            :key="message"
            class="field-error"
            >{{ message }}</small
          ></label
        >
        <label class="field-label" for="login-password"
          >Password
          <Password
            input-id="login-password"
            v-model="form.password"
            :feedback="false"
            toggle-mask
            :input-props="{ autocomplete: 'current-password' }"
            placeholder="Enter your password"
            :disabled="busy"
            required
            fluid
          /><small v-for="message in errors.password || []" :key="message" class="field-error">{{
            message
          }}</small></label
        >
        <label class="check-row"
          ><Checkbox v-model="form.remember" binary :disabled="busy" /><span
            >Keep me signed in</span
          ></label
        >
        <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>
        <Button
          type="submit"
          label="Sign in"
          icon="pi pi-arrow-right"
          icon-pos="right"
          :loading="busy"
          fluid
        />
      </form>
      <p class="auth-help">
        <i class="pi pi-info-circle" /> Need access? Contact your HR administrator.
      </p>
    </div>
    <small class="auth-legal">For authorized employees and administrators.</small>
  </AuthFrame>
</template>

<style scoped>
.applicant-portal {
  display: flex;
  align-items: center;
  gap: 14px;
  min-height: 88px;
  margin-bottom: 24px;
  padding: 16px;
  border: 1px solid var(--accent);
  border-radius: 14px;
  background: var(--accent-soft);
  color: var(--text);
}
.applicant-portal:hover {
  background: var(--panel);
}
.applicant-portal:focus-visible {
  outline-color: var(--accent);
}
.applicant-portal-icon {
  display: grid;
  flex-shrink: 0;
  place-items: center;
  width: 40px;
  height: 40px;
  border-radius: 12px;
  background: var(--panel);
  color: var(--accent);
  font-size: 18px;
}
.applicant-portal-copy {
  display: grid;
  flex: 1;
  min-width: 0;
  gap: 4px;
  line-height: 1.5;
}
.applicant-portal-copy strong {
  font-size: 14px;
  font-weight: 700;
}
.applicant-portal-copy > span {
  font-size: 12px;
}
.applicant-portal > .pi {
  flex-shrink: 0;
  color: var(--accent);
}
</style>
