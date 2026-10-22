<script setup>
import { computed, ref } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import http, { apiError } from '@/api/http'
import AuthFrame from '@/layout/AuthFrame.vue'

const route = useRoute()
const router = useRouter()
const email = ref(String(route.query.email || ''))
const password = ref('')
const passwordConfirmation = ref('')
const busy = ref(false)
const error = ref('')
const done = ref(false)
const token = computed(() => route.params.token)
async function submit() {
    busy.value = true; error.value = ''
    try { await http.post('/reset-password', { token: token.value, email: email.value, password: password.value, password_confirmation: passwordConfirmation.value }); done.value = true; setTimeout(() => router.replace('/auth/login'), 1200) }
    catch (failure) { error.value = apiError(failure).message }
    finally { busy.value = false }
}
</script>

<template>
    <AuthFrame><div class="auth-card"><span class="eyebrow">ACCOUNT RECOVERY</span><h2>Choose a new password</h2><p class="muted">Set a new password for your support account.</p>
        <form class="form-stack" @submit.prevent="submit"><label class="field-label">Email address<input v-model.trim="email" type="email" required autocomplete="email" /></label><label class="field-label">New password<input v-model="password" type="password" required autocomplete="new-password" minlength="8" /></label><label class="field-label">Confirm password<input v-model="passwordConfirmation" type="password" required autocomplete="new-password" /></label><p v-if="error" class="form-error">{{ error }}</p><p v-if="done" class="form-success">Password updated. Redirecting to sign in…</p><button class="button button-primary button-wide" :disabled="busy || done">{{ busy ? 'Saving…' : 'Save new password' }}</button></form>
        <div class="auth-links single"><RouterLink to="/auth/login">Back to sign in</RouterLink></div>
    </div></AuthFrame>
</template>
