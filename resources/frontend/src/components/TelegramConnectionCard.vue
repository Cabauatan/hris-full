<script setup>
import { computed, watch } from 'vue'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Tag from 'primevue/tag'
import { useTelegramStore } from '@/stores/telegram'

const props = defineProps({
  scope: {
    type: String,
    default: 'employee',
    validator: (value) => ['employee', 'applicant'].includes(value),
  },
})
const telegram = useTelegramStore()
const state = computed(() => telegram.states[props.scope])
const account = computed(() => telegram.identity(props.scope))
const commands = [
  { command: '/start', description: 'Show welcome and account linking instructions.' },
  { command: '/help', description: 'Show all available bot commands.' },
  { command: '/status', description: 'Check your Telegram notification connection.' },
  { command: '/portal', description: 'Open your employee or applicant portal.' },
  { command: '/unlink', description: 'Disconnect all HRIS accounts linked to this Telegram chat.' },
  { command: '/stop', description: 'Stop notifications and disconnect all linked HRIS accounts.' },
]
watch(
  [() => props.scope, account],
  ([scope], previous, onCleanup) => onCleanup(telegram.watchConnection(scope)),
  { immediate: true, flush: 'sync' },
)
</script>

<template>
  <section
    class="panel telegram-card"
    aria-label="Telegram notifications"
    :aria-busy="state.loading || state.busy"
  >
    <div class="panel-heading">
      <div>
        <div class="flex items-center gap-3">
          <i class="pi pi-telegram telegram-icon" aria-hidden="true" />
          <h2>Telegram notifications</h2>
        </div>
        <p>
          {{
            scope === 'applicant'
              ? 'Receive application, assessment, and interview updates in Telegram.'
              : 'Receive HRIS updates in your private Telegram chat.'
          }}
        </p>
      </div>
      <Tag v-if="state.status?.connected" value="Connected" severity="success" />
      <Tag v-else-if="state.status?.configured" value="Not connected" severity="secondary" />
    </div>
    <Message v-if="state.error" severity="error" :closable="false">{{ state.error }}</Message>
    <p v-if="state.loading && !state.status" role="status" class="muted">
      Checking Telegram connection…
    </p>
    <template v-else-if="state.status?.connected">
      <p role="status">
        Your account is connected to @{{ state.status.bot_username }}. New updates will arrive in
        Telegram.
      </p>
      <section class="telegram-command-help" aria-label="Telegram commands">
        <h3>Telegram commands</h3>
        <p class="muted">
          In your chat with @{{ state.status.bot_username }}, type a command or choose it from
          <b>Menu</b>.
        </p>
        <dl class="telegram-command-list">
          <div v-for="item in commands" :key="item.command">
            <dt>
              <code>{{ item.command }}</code>
            </dt>
            <dd>{{ item.description }}</dd>
          </div>
        </dl>
      </section>
      <div class="flex flex-wrap gap-3">
        <Button
          label="Refresh status"
          icon="pi pi-refresh"
          outlined
          :loading="state.loading"
          :disabled="state.busy"
          @click="telegram.load(scope)"
        />
        <Button
          label="Unlink Telegram"
          icon="pi pi-link"
          severity="secondary"
          outlined
          :loading="state.busy"
          :disabled="state.loading"
          @click="telegram.disconnect(scope)"
        />
      </div>
    </template>
    <template v-else-if="state.status?.configured">
      <Message v-if="state.linkExpired" severity="warn" :closable="false"
        >Your connection link expired. Create a new link to continue.</Message
      >
      <template v-if="state.link">
        <ol class="telegram-steps">
          <li>Open Telegram using the button below.</li>
          <li>
            Press <b>Start</b> in @{{ state.status.bot_username }} to allow HRIS notifications.
          </li>
          <li>Your connection status updates automatically after you press Start.</li>
        </ol>
        <p class="muted">This link expires in a few minutes. Keep it private.</p>
        <div class="flex flex-wrap gap-3">
          <a
            class="telegram-open"
            :href="state.link.url"
            target="_blank"
            rel="noopener noreferrer"
            referrerpolicy="no-referrer"
            ><i class="pi pi-telegram" aria-hidden="true" />Open Telegram<i
              class="pi pi-external-link"
              aria-hidden="true"
          /></a>
          <Button
            label="Refresh status"
            icon="pi pi-refresh"
            outlined
            :loading="state.loading"
            :disabled="state.busy"
            @click="telegram.load(scope)"
          />
        </div>
      </template>
      <template v-else>
        <p>
          Connect your Telegram account to receive automatic updates. You can unlink it at any time.
        </p>
        <Button
          label="Connect Telegram"
          icon="pi pi-telegram"
          :loading="state.busy"
          :disabled="state.loading"
          @click="telegram.connect(scope)"
        />
      </template>
    </template>
    <template v-else-if="state.status">
      <p class="muted">Telegram notifications are currently unavailable. Please try again later.</p>
      <Button
        label="Refresh status"
        icon="pi pi-refresh"
        outlined
        :loading="state.loading"
        :disabled="state.busy"
        @click="telegram.load(scope)"
      />
    </template>
    <Button
      v-else-if="!state.loading"
      label="Retry connection check"
      icon="pi pi-refresh"
      outlined
      @click="telegram.load(scope)"
    />
  </section>
</template>

<style scoped>
.telegram-card {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.telegram-card .panel-heading {
  margin-bottom: 0;
}
.telegram-icon {
  color: var(--accent);
  font-size: 24px;
}
.telegram-command-help {
  padding: 16px;
  border: 1px solid var(--line);
  border-radius: 8px;
  background: var(--soft);
}
.telegram-command-help h3 {
  margin: 0;
  font-size: 14px;
}
.telegram-command-help p {
  margin: 8px 0 16px;
  font-size: 12px;
  line-height: 1.6;
}
.telegram-command-list {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px 24px;
  margin: 0;
}
.telegram-command-list dt {
  color: var(--accent);
  font-weight: 600;
}
.telegram-command-list dd {
  margin: 4px 0 0;
  color: var(--muted);
  font-size: 12px;
  line-height: 1.6;
}
@media (max-width: 640px) {
  .telegram-command-list {
    grid-template-columns: minmax(0, 1fr);
  }
}
.telegram-steps {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding-left: 24px;
}
.telegram-open {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  padding: 10px 16px;
  border-radius: 6px;
  border: 1px solid var(--accent);
  color: var(--accent);
  font-weight: 600;
}
.telegram-open:hover {
  background: var(--accent-soft);
}
.telegram-open:focus-visible {
  outline: 2px solid var(--accent);
  outline-offset: 3px;
}
</style>
