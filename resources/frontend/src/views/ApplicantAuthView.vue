<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import Button from 'primevue/button'
import Checkbox from 'primevue/checkbox'
import Message from 'primevue/message'
import { useApplicantStore } from '@/stores/applicant'
import { apiError } from '@/api/http'
import { applicantRedirect } from '@/utils/recruitment'
const props = defineProps({ mode: { type: String, default: 'login' } })
const applicant = useApplicantStore()
const route = useRoute()
const router = useRouter()
const registering = computed(() => props.mode === 'register')
const alternateAuthRoute = computed(() => ({
  path: registering.value ? '/applicant/login' : '/applicant/register',
  ...(route.query.redirect ? { query: { redirect: applicantRedirect(route.query.redirect) } } : {}),
}))
const form = ref({
  first_name: '',
  middle_name: '',
  last_name: '',
  mobile_number: '',
  email: '',
  password: '',
  password_confirmation: '',
  remember: false,
})
const busy = ref(false)
const error = ref('')
const errors = ref({})
watch(
  () => props.mode,
  () => {
    form.value.password = ''
    form.value.password_confirmation = ''
    form.value.remember = false
    error.value = ''
    errors.value = {}
  },
)
async function submit() {
  if (busy.value) return
  busy.value = true
  error.value = ''
  errors.value = {}
  try {
    if (registering.value) {
      await applicant.register({
        first_name: form.value.first_name.trim(),
        middle_name: form.value.middle_name.trim() || null,
        last_name: form.value.last_name.trim(),
        mobile_number: form.value.mobile_number.trim() || null,
        email: form.value.email.trim(),
        password: form.value.password,
        password_confirmation: form.value.password_confirmation,
      })
    } else {
      await applicant.signIn({
        email: form.value.email.trim(),
        password: form.value.password,
        remember: form.value.remember,
      })
    }
    form.value.password = ''
    form.value.password_confirmation = ''
    await router.replace(applicantRedirect(route.query.redirect))
  } catch (failure) {
    const detail = apiError(failure)
    error.value = detail.message
    errors.value = detail.errors
  } finally {
    busy.value = false
  }
}
async function signOutBlockedSession() {
  if (busy.value) return
  busy.value = true
  error.value = ''
  try {
    await applicant.signOut()
    await router.replace('/applicant/login')
  } catch (failure) {
    error.value = apiError(failure).message
  } finally {
    busy.value = false
  }
}
</script>
<template>
  <section class="applicant-auth-card">
    <span class="eyebrow">APPLICANT PORTAL</span>
    <h1>{{ registering ? 'Create your applicant account' : 'Welcome back' }}</h1>
    <p class="muted">
      {{
        registering
          ? 'Use your personal email to follow your application and assessments.'
          : 'Sign in with the email you used to apply.'
      }}
    </p>
    <Message v-if="route.query.expired" severity="warn" :closable="false"
      >Your applicant session has ended. Please sign in again.</Message
    >
    <Message v-if="applicant.connectionError" severity="warn" :closable="false">{{
      applicant.connectionError
    }}</Message>
    <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>
    <Button
      v-if="applicant.sessionBlocked"
      label="Sign out applicant session"
      icon="pi pi-sign-out"
      :loading="busy"
      @click="signOutBlockedSession"
    />
    <form v-else class="form-stack" @submit.prevent="submit">
      <template v-if="registering"
        ><div class="grid gap-4 sm:grid-cols-2">
          <label class="field-label"
            >First name<InputText
              v-model.trim="form.first_name"
              autocomplete="given-name"
              maxlength="100"
              required
              :disabled="busy"
              fluid
            /><small
              v-for="message in errors.first_name || []"
              :key="message"
              class="field-error"
              >{{ message }}</small
            ></label
          ><label class="field-label"
            >Last name<InputText
              v-model.trim="form.last_name"
              autocomplete="family-name"
              maxlength="100"
              required
              :disabled="busy"
              fluid
            /><small v-for="message in errors.last_name || []" :key="message" class="field-error">{{
              message
            }}</small></label
          >
        </div>
        <label class="field-label"
          >Middle name <small class="muted">Optional</small
          ><InputText
            v-model.trim="form.middle_name"
            autocomplete="additional-name"
            maxlength="100"
            :disabled="busy"
            fluid
          /><small v-for="message in errors.middle_name || []" :key="message" class="field-error">{{
            message
          }}</small></label
        >
        <label class="field-label"
          >Mobile number <small class="muted">Optional</small
          ><InputText
            v-model.trim="form.mobile_number"
            autocomplete="tel"
            type="tel"
            maxlength="30"
            :disabled="busy"
            fluid
          /><small
            v-for="message in errors.mobile_number || []"
            :key="message"
            class="field-error"
            >{{ message }}</small
          ></label
        >
      </template>
      <label class="field-label"
        >Email<InputText
          v-model.trim="form.email"
          type="email"
          :autocomplete="registering ? 'email' : 'username'"
          maxlength="255"
          required
          :disabled="busy"
          fluid
        /><small v-for="message in errors.email || []" :key="message" class="field-error">{{
          message
        }}</small></label
      >
      <label class="field-label"
        >Password<Password
          v-model="form.password"
          :feedback="registering"
          toggle-mask
          :input-props="{
            autocomplete: registering ? 'new-password' : 'current-password',
            minlength: registering ? 8 : undefined,
            maxlength: 255,
          }"
          required
          :disabled="busy"
          fluid
        /><small v-for="message in errors.password || []" :key="message" class="field-error">{{
          message
        }}</small></label
      >
      <label v-if="registering" class="field-label"
        >Confirm password<Password
          v-model="form.password_confirmation"
          :feedback="false"
          toggle-mask
          :input-props="{ autocomplete: 'new-password', minlength: 8, maxlength: 255 }"
          required
          :disabled="busy"
          fluid
      /></label>
      <label v-else class="check-row"
        ><Checkbox v-model="form.remember" binary :disabled="busy" /><span
          >Keep me signed in</span
        ></label
      >
      <Button
        type="submit"
        :label="registering ? 'Create applicant account' : 'Sign in'"
        icon="pi pi-arrow-right"
        icon-pos="right"
        :loading="busy"
        fluid
      />
    </form>
    <p class="mt-5 text-sm muted">
      {{ registering ? 'Already registered?' : 'New applicant?' }}
      <RouterLink :to="alternateAuthRoute">{{
        registering ? 'Sign in' : 'Create an account'
      }}</RouterLink>
    </p>
  </section>
</template>
<style scoped>
.applicant-auth-card {
  max-width: 550px;
  margin: 0 auto;
  padding: 28px;
  border: 1px solid var(--line);
  border-radius: 18px;
  background: var(--panel);
}
.applicant-auth-card h1 {
  font-size: 28px;
  margin-top: 12px;
}
.applicant-auth-card :deep(.p-message) {
  margin-bottom: 16px;
}
</style>
