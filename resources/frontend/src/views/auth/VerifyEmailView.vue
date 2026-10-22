<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import AuthFrame from '@/layout/AuthFrame.vue'
import { useAuthStore } from '@/stores/auth'
import { apiError } from '@/api/http'

const auth = useAuthStore()
const router = useRouter()
const busy = ref(false)
const message = ref('')
const error = ref('')
async function resend() {
    busy.value = true; error.value = ''; message.value = ''
    try { await auth.resendVerification(); message.value = 'Verification email sent. Check your inbox and spam folder.' }
    catch (failure) { error.value = apiError(failure).message }
    finally { busy.value = false }
}
async function checkAgain() {
    busy.value = true; error.value = ''
    try { auth.ready = false; await auth.restoreSession(); if (!auth.emailUnverified) router.replace('/dashboard'); else message.value = 'Your email is not verified yet. Open the verification link from your email.' }
    catch (failure) { error.value = apiError(failure).message }
    finally { busy.value = false }
}
</script>

<template>
    <AuthFrame><div class="auth-card verify-card"><span class="verify-icon"><i class="pi pi-envelope" /></span><span class="eyebrow">ONE MORE STEP</span><h2>Verify your email</h2><p class="muted">We sent a verification link to <strong>{{ auth.user?.email }}</strong>. Please open the link before using the ticketing workspace.</p><p v-if="message" class="form-success">{{ message }}</p><p v-if="error" class="form-error">{{ error }}</p><div class="form-stack"><button class="button button-primary button-wide" :disabled="busy" @click="checkAgain">I verified my email</button><button class="button button-secondary button-wide" :disabled="busy" @click="resend">{{ busy ? 'Please wait…' : 'Resend verification email' }}</button><button class="text-action" @click="auth.signOut().then(() => router.replace('/auth/login'))">Sign out</button></div></div></AuthFrame>
</template>
