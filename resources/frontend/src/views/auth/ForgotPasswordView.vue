<script setup>
import { ref } from 'vue'
import { RouterLink } from 'vue-router'
import http, { apiError } from '@/api/http'
import AuthFrame from '@/layout/AuthFrame.vue'

const email = ref('')
const busy = ref(false)
const error = ref('')
const success = ref('')
async function submit() {
    busy.value = true; error.value = ''; success.value = ''
    try { const { data } = await http.post('/forgot-password', { email: email.value }); success.value = data.message || 'If that address is registered, a reset link is on its way.' }
    catch (failure) { error.value = apiError(failure).message }
    finally { busy.value = false }
}
</script>

<template>
    <AuthFrame><div class="auth-card"><span class="eyebrow">ACCOUNT RECOVERY</span><h2>Reset your password</h2><p class="muted">We will send a secure reset link to your email address.</p>
        <form class="form-stack" @submit.prevent="submit"><label class="field-label">Email address<input v-model.trim="email" type="email" required autocomplete="email" placeholder="you@company.com" /></label><p v-if="error" class="form-error">{{ error }}</p><p v-if="success" class="form-success">{{ success }}</p><button class="button button-primary button-wide" :disabled="busy">{{ busy ? 'Sending…' : 'Send reset link' }}</button></form>
        <div class="auth-links single"><RouterLink to="/auth/login">Back to sign in</RouterLink></div>
    </div></AuthFrame>
</template>
