<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import Button from 'primevue/button'
import Message from 'primevue/message'
import { useInterviewChatStore } from '@/stores/interviewChat'

const props = defineProps({
  scope: { type: String, required: true },
  interview: { type: Object, required: true },
  actorId: { type: Number, default: null },
})
const chat = useInterviewChatStore()
const history = ref(null)
const remaining = computed(() => 2000 - new TextEncoder().encode(chat.draft.trim()).length)
const status = computed(() => {
  if (chat.loading) return 'Loading messages…'
  if (!chat.canSend) return 'Transcript'
  return chat.live ? 'Live' : 'Updates refresh automatically'
})

function timestamp(value) {
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? '' : date.toLocaleString()
}

function sender(message) {
  if (message.sender.key === chat.ownKey) return `${message.sender.name} · You`
  return `${message.sender.name} · ${message.sender.kind === 'applicant' ? 'Applicant' : 'HR'}`
}

async function earlier() {
  const element = history.value
  const height = element?.scrollHeight || 0
  const top = element?.scrollTop || 0
  await chat.earlier()
  await nextTick()
  if (element && history.value === element) element.scrollTop = top + element.scrollHeight - height
}

watch(
  () => [props.scope, props.actorId, props.interview.id, props.interview.uuid],
  () => chat.open(props.scope, props.interview),
  { immediate: true, flush: 'sync' },
)
watch(
  () => chat.messages.at(-1)?.id,
  async () => {
    const element = history.value
    const nearBottom =
      !element || element.scrollHeight - element.scrollTop - element.clientHeight < 96
    await nextTick()
    if (element && nearBottom && history.value === element) element.scrollTop = element.scrollHeight
  },
)
onBeforeUnmount(() => chat.reset())
</script>

<template>
  <section class="interview-chat" aria-labelledby="interview-chat-title" :aria-busy="chat.loading">
    <div class="chat-heading">
      <div>
        <h2 id="interview-chat-title">
          <i class="pi pi-comments" aria-hidden="true" /> Interview chat
        </h2>
        <p>Messages are shared with the applicant and assigned interviewers.</p>
      </div>
      <span role="status" class="chat-status" :class="{ 'is-live': chat.live && chat.canSend }">{{
        status
      }}</span>
    </div>
    <Message v-if="chat.error" severity="error" :closable="false">{{ chat.error }}</Message>
    <div class="chat-toolbar">
      <Button
        v-if="chat.hasEarlier"
        label="Load earlier messages"
        icon="pi pi-history"
        severity="secondary"
        text
        :loading="chat.loadingEarlier"
        :disabled="chat.loading"
        @click="earlier"
      />
      <Button
        label="Refresh messages"
        icon="pi pi-refresh"
        severity="secondary"
        text
        :disabled="chat.loading"
        @click="chat.refresh"
      />
    </div>
    <div
      ref="history"
      class="chat-history"
      role="log"
      aria-label="Interview messages"
      aria-live="polite"
      aria-relevant="additions"
      tabindex="0"
    >
      <p v-if="!chat.messages.length && !chat.loading" class="chat-empty">No messages yet.</p>
      <article
        v-for="message in chat.messages"
        :key="message.id"
        class="chat-message"
        :class="{ 'is-own': message.sender.key === chat.ownKey }"
      >
        <div class="chat-message-meta">
          <span>{{ sender(message) }}</span>
          <time :datetime="message.created_at">{{ timestamp(message.created_at) }}</time>
        </div>
        <p class="chat-message-body">{{ message.body }}</p>
      </article>
    </div>
    <p v-if="!chat.canSend && !chat.loading && !chat.error" class="chat-availability">
      Messages can be sent while the scheduled interview room is open. You can still read its
      history.
    </p>
    <form class="chat-compose" @submit.prevent="chat.send">
      <label for="interview-message">Message</label>
      <textarea
        id="interview-message"
        v-model="chat.draft"
        rows="3"
        maxlength="2000"
        placeholder="Write a message…"
        :disabled="!chat.canSend"
        aria-describedby="interview-message-limit"
      />
      <Message v-if="chat.sendError" severity="error" :closable="false">{{
        chat.sendError
      }}</Message>
      <div class="chat-compose-footer">
        <span id="interview-message-limit" :class="{ 'over-limit': remaining < 0 }">{{
          remaining < 0 ? 'Message too long. Shorten it before sending.' : ''
        }}</span>
        <Button
          type="submit"
          label="Send message"
          icon="pi pi-send"
          :loading="chat.sending"
          :disabled="!chat.canSend || !chat.draft.trim() || remaining < 0"
        />
      </div>
    </form>
  </section>
</template>

<style scoped>
.interview-chat {
  margin-top: 1.5rem;
  padding: 1.25rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: 1rem;
  background: var(--p-content-background);
  color: var(--p-text-color);
  min-width: 0;
}
.chat-heading,
.chat-message-meta,
.chat-compose-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 0.5rem 1rem;
}
.chat-heading h2 {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin: 0;
  font-size: 1.1rem;
}
.chat-heading p,
.chat-availability,
.chat-empty {
  color: var(--p-text-muted-color);
  font-size: 0.875rem;
}
.chat-status {
  padding: 0.35rem 0.65rem;
  border-radius: 1rem;
  font-size: 0.75rem;
  background: var(--p-content-hover-background);
  color: var(--p-text-muted-color);
}
.chat-status.is-live {
  background: var(--p-primary-50);
  color: var(--p-primary-700);
}
.chat-toolbar {
  display: flex;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin: 0.75rem 0;
}
.chat-history {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.75rem;
  min-height: 10rem;
  max-height: 26rem;
  overflow: auto;
  padding: 1rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: 0.75rem;
  background: var(--p-content-hover-background);
  overscroll-behavior: contain;
}
.chat-message {
  padding: 0.75rem 1rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: 0.85rem;
  background: var(--p-content-background);
  max-width: min(90%, 48rem);
  min-width: 0;
}
.chat-message.is-own {
  align-self: flex-end;
  border-color: var(--p-primary-color);
}
.chat-message-meta {
  font-size: 0.75rem;
  color: var(--p-text-muted-color);
  overflow-wrap: anywhere;
}
.chat-message-meta span {
  font-weight: 600;
}
.chat-message-body {
  margin: 0.5rem 0 0;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.chat-compose {
  display: grid;
  gap: 0.5rem;
  margin-top: 1rem;
}
.chat-compose label {
  font-size: 0.875rem;
  font-weight: 600;
}
.chat-compose textarea {
  width: 100%;
  min-width: 0;
  resize: vertical;
  border: 1px solid var(--p-form-field-border-color);
  border-radius: var(--p-form-field-border-radius);
  padding: 0.75rem;
  background: var(--p-form-field-background);
  color: var(--p-form-field-color);
  font: inherit;
}
.chat-compose textarea:focus-visible,
.chat-history:focus-visible {
  outline: 2px solid var(--p-primary-color);
  outline-offset: 2px;
}
.chat-compose textarea:disabled {
  opacity: 0.7;
}
.chat-compose-footer {
  font-size: 0.75rem;
  color: var(--p-text-muted-color);
}
.over-limit {
  color: var(--p-red-500);
}
@media (max-width: 560px) {
  .interview-chat {
    padding: 1rem;
  }
  .chat-history {
    padding: 0.75rem;
  }
  .chat-message {
    max-width: 100%;
  }
}
</style>
