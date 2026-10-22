<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import http from '@/api/http'

const router = useRouter()
const busy = ref(false)
const error = ref('')
const form = ref({ subject: '', category: 'hardware', description: '' })

async function submit() {
    busy.value = true
    error.value = ''
    try {
        const { data } = await http.post('/api/tickets', form.value)
        await router.push(`/tickets/${data.data.id}`)
    } catch (failure) {
        error.value =
            failure.response?.data?.message ||
            Object.values(failure.response?.data?.errors || {})
                .flat()
                .join(' ') ||
            'Could not save the ticket.'
    } finally {
        busy.value = false
    }
}
</script>

<template>
    <div class="page-heading">
        <div>
            <h2>New ticket</h2>
            <p>Describe your request so it can be routed to the right team.</p>
        </div>
    </div>

    <form class="panel" @submit.prevent="submit">
        <div class="form-grid">
            <label class="field-label full">
                Subject
                <input
                    v-model="form.subject"
                    class="input"
                    required
                    maxlength="200"
                    placeholder="e.g. My laptop will not start"
                />
            </label>
            <label class="field-label">
                Category
                <select v-model="form.category" class="select">
                    <option value="hardware">Hardware</option>
                    <option value="software">Software</option>
                    <option value="access">Access</option>
                    <option value="network">Network</option>
                    <option value="other">Other</option>
                </select>
            </label>
            <div class="field-label">
                Priority
                <div class="input" aria-label="Default priority: Low">Low</div>
            </div>
            <label class="field-label full">
                Description
                <textarea
                    v-model="form.description"
                    class="textarea"
                    required
                    maxlength="20000"
                    placeholder="What happened, and when did it start?"
                ></textarea>
            </label>
        </div>

        <p v-if="error" class="error-box" role="alert">{{ error }}</p>
        <div class="actions" style="margin-top: 18px">
            <button class="button button-primary" :disabled="busy">
                {{ busy ? 'Submitting…' : 'Submit ticket' }}
            </button>
            <button
                type="button"
                class="button button-secondary"
                :disabled="busy"
                @click="router.back()"
            >
                Cancel
            </button>
        </div>
    </form>
</template>
