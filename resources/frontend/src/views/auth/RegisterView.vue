<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import AuthFrame from '@/layout/AuthFrame.vue'
import { useAuthStore } from '@/stores/auth'
import { apiError } from '@/api/http'

const auth = useAuthStore()
const router = useRouter()
const form = ref({ name: '', email: '', password: '', password_confirmation: '' })
const error = ref('')
const busy = ref(false)

async function submit() {
    error.value = ''
    busy.value = true
    try { await auth.register(form.value); await router.replace('/verify-email') }
    catch (failure) { error.value = apiError(failure).message }
    finally { busy.value = false }
}
</script>

<template>
    <AuthFrame>
        <div class="auth-card">
            <span class="eyebrow">GET STARTED</span><h2>Create your account</h2>
            <p class="muted">Use your work email so the support team can identify your requests.</p>
            <form class="form-stack" @submit.prevent="submit">
                <label class="field-label">Full name<input v-model.trim="form.name" required autocomplete="name" placeholder="Your name" /></label>
                <label class="field-label">Work email<input v-model.trim="form.email" type="email" required autocomplete="email" placeholder="you@company.com" /></label>
                <label class="field-label">Password<input v-model="form.password" type="password" required autocomplete="new-password" minlength="8" placeholder="Create a password" /></label>
                <label class="field-label">Confirm password<input v-model="form.password_confirmation" type="password" required autocomplete="new-password" placeholder="Enter it again" /></label>
                <p v-if="error" class="form-error" role="alert">{{ error }}</p>
                <button class="button button-primary button-wide" :disabled="busy">{{ busy ? 'Creating account…' : 'Create account' }}</button>
            </form>
            <div class="auth-links single">Already have an account? <RouterLink to="/auth/login">Sign in</RouterLink></div>
        </div>
    </AuthFrame>
</template>
