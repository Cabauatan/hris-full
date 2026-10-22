<script setup>
import { computed, onMounted, onBeforeUnmount, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import http, { downloadFile } from '@/api/http'
import { useAuthStore } from '@/stores/auth'
import { connectRealtime, ticketChannel, ticketStaffChannel } from '@/realtime/echo'

const route = useRoute()
const auth = useAuthStore()
const ticket = ref(null)
const messages = ref([])
const attachments = ref([])
const assets = ref([])
const agents = ref([])
const canManage = ref(false)
const canAssign = ref(false)
const canChangePriority = ref(false)
const canInternal = ref(false)
const comment = ref('')
const agentSearch = ref('')
const internal = ref(false)
const busy = ref(false)
const error = ref('')
const notice = ref('')
const status = ref('')
const priority = ref('')
const assignees = ref([])
const allowedStatusTransitions = {
  new: ['open'],
  open: ['in_progress', 'pending'],
  in_progress: ['pending', 'resolved'],
  pending: ['open', 'in_progress'],
  resolved: ['open', 'closed'],
  closed: ['open'],
}
const statusOptions = computed(() => {
  const current = ticket.value?.status
  return [...new Set([current, ...(allowedStatusTransitions[current] || [])].filter(Boolean))]
})
const nextStatuses = computed(() => allowedStatusTransitions[ticket.value?.status] || [])
function formatStatus(value) {
  return value?.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()) || ''
}
const visibleAgents = computed(() => {
  const search = agentSearch.value.trim().toLowerCase()
  if (!search) return agents.value
  return agents.value.filter((agent) => agent.name.toLowerCase().includes(search))
})
let channel
let staffChannel

async function readChat() {
  try {
    const { data } = await http.get(`/api/tickets/${route.params.id}/chat`)
    messages.value = data.data.messages || []
  } catch {
    // Ticket access errors are reported by the ticket details request.
  }
}

async function readAssets() {
  if (!auth.can('inventory.view')) return
  try {
    const { data } = await http.get(`/api/tickets/${route.params.id}/assets`)
    assets.value = data.data || []
  } catch {
    assets.value = []
  }
}

async function load() {
  try {
    const { data } = await http.get(`/api/tickets/${route.params.id}`)
    ticket.value = data.data.ticket
    agents.value = data.data.agents || []
    attachments.value = data.data.attachments || []
    canManage.value = Boolean(data.data.canManage)
    canAssign.value = Boolean(data.data.canAssign)
    canChangePriority.value = Boolean(data.data.canChangePriority)
    canInternal.value = Boolean(data.data.canInternal)
    status.value = ticket.value.status
    priority.value = ticket.value.priority
    assignees.value = (ticket.value.assignees || []).map((agent) => String(agent.id))
    error.value = ''
    await Promise.all([readChat(), readAssets()])
  } catch (failure) {
    error.value = failure.response?.data?.message || 'Hindi makuha ang ticket.'
  }
}

function toggleAssignee(agentId) {
  const id = String(agentId)
  assignees.value = assignees.value.includes(id)
    ? assignees.value.filter((selected) => selected !== id)
    : [...assignees.value, id]
}

async function send() {
  if (!comment.value.trim() || busy.value) return
  busy.value = true
  error.value = ''
  notice.value = ''
  try {
    await http.post(`/api/tickets/${route.params.id}/comments`, {
      body: comment.value,
      internal: internal.value,
    })
    comment.value = ''
    internal.value = false
    await load()
  } catch (failure) {
    error.value = failure.response?.data?.message || 'Hindi naipadala ang mensahe.'
  } finally {
    busy.value = false
  }
}

async function upload(file) {
  if (!file || busy.value) return
  busy.value = true
  error.value = ''
  const form = new FormData()
  form.append('file', file)
  try {
    await http.post(`/api/tickets/${route.params.id}/attachments`, form)
    await load()
  } catch (failure) {
    error.value = failure.response?.data?.message || 'Hindi na-upload ang attachment.'
  } finally {
    busy.value = false
  }
}

async function update() {
  if (busy.value) return
  busy.value = true
  error.value = ''
  notice.value = ''
  try {
    const changes = {
      version: ticket.value.version,
      status: status.value,
    }
    if (canChangePriority.value) changes.priority = priority.value
    if (canAssign.value) changes.assignee_ids = assignees.value.map(Number)
    await http.patch(`/api/tickets/${route.params.id}`, changes)
    notice.value = 'Ticket properties saved.'
    await load()
  } catch (failure) {
    error.value = failure.response?.data?.message || 'Hindi na-update ang ticket.'
  } finally {
    busy.value = false
  }
}

async function reopen() {
  if (busy.value) return
  busy.value = true
  error.value = ''
  try {
    await http.post(`/api/tickets/${route.params.id}/reopen`)
    notice.value = 'Ticket reopened.'
    await load()
  } catch (failure) {
    error.value = failure.response?.data?.message || 'Hindi na-reopen ang ticket.'
  } finally {
    busy.value = false
  }
}

const refresh = () => load()
onMounted(() => {
  load()
  window.addEventListener('supportdesk:refresh', refresh)
  connectRealtime()
  channel = ticketChannel(route.params.id)
  channel?.listen('.chat.changed', readChat)
  if (auth.isStaff) {
    staffChannel = ticketStaffChannel(route.params.id)
    staffChannel?.listen('.chat.changed', readChat)
  }
})
onBeforeUnmount(() => {
  window.removeEventListener('supportdesk:refresh', refresh)
  channel?.stopListening('.chat.changed')
  staffChannel?.stopListening('.chat.changed')
})
</script>

<template>
  <p v-if="error" class="error-box ticket-alert">{{ error }}</p>
  <p v-if="notice" class="success-box ticket-alert">{{ notice }}</p>

  <div v-if="ticket" class="ticket-page">
    <header class="ticket-page-heading">
      <RouterLink class="back-link" to="/tickets"
        ><i class="pi pi-arrow-left" /> Back to tickets</RouterLink
      >
      <div class="ticket-heading-row">
        <div class="ticket-title-block">
          <span class="ticket-heading-icon"><i class="pi pi-ticket" /></span>
          <div class="ticket-title-copy">
            <div class="ticket-identity">
              <span>Support request</span
              ><span class="ticket-category"><i class="pi pi-folder" /> {{ ticket.category }}</span>
            </div>
            <h2>{{ ticket.subject }}</h2>
            <div class="ticket-heading-badges">
              <span class="badge" :class="`badge-${ticket.status}`">{{
                ticket.status?.replace('_', ' ')
              }}</span
              ><span class="priority-badge" :class="`priority-${ticket.priority}`"
                ><i class="pi pi-flag-fill" /> {{ ticket.priority }} priority</span
              >
            </div>
          </div>
        </div>
        <button
          v-if="['resolved', 'closed'].includes(ticket.status)"
          class="button button-secondary reopen-button"
          :disabled="busy"
          @click="reopen"
        >
          <i class="pi pi-refresh" /> Reopen ticket
        </button>
      </div>
    </header>

    <div class="ticket-detail-layout">
      <main class="ticket-main-column">
        <section class="panel ticket-description-panel">
          <div class="ticket-section-label">
            <span class="section-icon"><i class="pi pi-align-left" /></span
            ><span>Request description</span>
          </div>
          <p>{{ ticket.description }}</p>
        </section>

        <section class="panel ticket-conversation-panel">
          <div class="section-heading conversation-heading">
            <div>
              <h3>Conversation</h3>
              <p>Updates and replies on this ticket.</p>
            </div>
            <span class="count-badge">{{ messages.length }}</span>
          </div>
          <div v-if="messages.length" class="conversation-list">
            <article
              v-for="message in messages"
              :key="message.id"
              class="conversation-message"
              :class="{ 'internal-message': message.internal }"
            >
              <span class="message-avatar">{{
                (message.author || 'S').slice(0, 1).toUpperCase()
              }}</span>
              <div class="message-content">
                <header class="message-header">
                  <b>{{ message.author || 'Support' }}</b
                  ><span v-if="message.internal" class="internal-badge"
                    ><i class="pi pi-lock" /> Internal note</span
                  ><time>{{ message.created_at }}</time>
                </header>
                <p>{{ message.body }}</p>
              </div>
            </article>
          </div>
          <div v-else class="conversation-empty">
            <span><i class="pi pi-comments" /></span><b>Wala pang reply</b
            ><small>Magsimula ng conversation sa pamamagitan ng pag-reply sa ticket.</small>
          </div>

          <form class="reply-composer" @submit.prevent="send">
            <label class="field-label" for="ticket-reply">{{
              internal ? 'Internal note' : 'Reply to requester'
            }}</label>
            <textarea
              id="ticket-reply"
              v-model="comment"
              class="textarea reply-input"
              :placeholder="
                internal ? 'Mag-iwan ng note para sa support team…' : 'Isulat ang reply…'
              "
              required
            />
            <div class="composer-footer">
              <label v-if="canInternal" class="internal-toggle"
                ><input v-model="internal" type="checkbox" /><span
                  ><i class="pi pi-lock" /> Internal note</span
                ></label
              >
              <label class="attachment-upload"
                ><input
                  type="file"
                  accept=".pdf,.jpg,.jpeg,.png,.txt"
                  :disabled="busy"
                  @change="
                    upload($event.target.files[0])
                    $event.target.value = ''
                  "
                /><i class="pi pi-paperclip" /> Attach file</label
              >
              <button
                class="button button-primary send-reply-button"
                :disabled="busy || !comment.trim()"
              >
                <i :class="internal ? 'pi pi-lock' : 'pi pi-send'" />{{
                  busy ? 'Sending…' : internal ? 'Add internal note' : 'Send reply'
                }}
              </button>
            </div>
            <small class="composer-hint">PDF, JPG, PNG, or TXT · max 2 MB</small>
          </form>
        </section>
      </main>

      <aside class="ticket-side-column">
        <section class="panel ticket-properties-panel">
          <div class="section-heading">
            <div>
              <h3>Ticket properties</h3>
              <p>Ownership and current state.</p>
            </div>
          </div>
          <div class="ticket-fact-list">
            <div class="ticket-fact">
              <span>Requester</span
              ><b
                ><i class="pi pi-user" /> {{ ticket.requester_name || ticket.requester || '—' }}</b
              >
            </div>
            <div class="ticket-fact">
              <span>Created</span><b><i class="pi pi-calendar" /> {{ ticket.created_at || '—' }}</b>
            </div>
            <div class="ticket-fact">
              <span>SLA due</span
              ><b
                :class="{
                  'sla-overdue':
                    ticket.due_at &&
                    new Date(ticket.due_at) < new Date() &&
                    !['resolved', 'closed'].includes(ticket.status),
                }"
                ><i class="pi pi-clock" /> {{ ticket.due_at || 'No SLA deadline' }}</b
              >
            </div>
          </div>

          <template v-if="canManage">
            <div class="property-field">
              <label class="field-label"
                >Status<select v-model="status" class="select">
                  <option v-for="value in statusOptions" :key="value" :value="value">
                    {{ formatStatus(value) }}{{ value === ticket.status ? ' · Current' : '' }}
                  </option>
                </select></label
              ><small class="status-transition-hint"
                >Next:
                {{
                  nextStatuses.length
                    ? nextStatuses.map(formatStatus).join(' or ')
                    : 'No further status available'
                }}</small
              >
            </div>
            <div class="property-field">
              <label v-if="canChangePriority" class="field-label"
                >Priority<select v-model="priority" class="select">
                  <option
                    v-for="value in ['low', 'medium', 'high', 'urgent']"
                    :key="value"
                    :value="value"
                  >
                    {{ value }}
                  </option>
                </select></label
              >
              <div v-else class="field-label">
                Priority
                <div class="input priority-readonly">{{ ticket.priority }}</div>
              </div>
            </div>
            <div v-if="canAssign" class="property-field">
              <div class="agent-picker-heading">
                <label class="field-label">Assigned agents</label
                ><span>{{ assignees.length }} selected</span>
              </div>
              <label class="agent-search"
                ><i class="pi pi-search" /><input
                  v-model="agentSearch"
                  placeholder="Find an agent"
                  aria-label="Find an agent"
              /></label>
              <div class="agent-picker-list">
                <label v-for="agent in visibleAgents" :key="agent.id" class="agent-option"
                  ><input
                    type="checkbox"
                    :checked="assignees.includes(String(agent.id))"
                    @change="toggleAssignee(agent.id)" /><span class="agent-option-avatar">{{
                    agent.name.slice(0, 1).toUpperCase()
                  }}</span
                  ><span>{{ agent.name }}</span
                  ><i
                    v-if="assignees.includes(String(agent.id))"
                    class="pi pi-check agent-check" /></label
                ><small v-if="!visibleAgents.length" class="agent-empty">No agents found.</small>
              </div>
            </div>
            <div v-else class="property-field">
              <label class="field-label">Assigned agents</label>
              <div v-if="ticket.assignees?.length" class="assigned-agent-summary">
                <span v-for="agent in ticket.assignees" :key="agent.id">{{ agent.name }}</span>
              </div>
              <small v-else class="muted">No agents assigned</small>
            </div>
            <button
              class="button button-primary save-ticket-button"
              :disabled="busy"
              @click="update"
            >
              <i class="pi pi-check" />{{ busy ? 'Saving…' : 'Save changes' }}
            </button>
          </template>
        </section>

        <section class="panel ticket-attachments-panel">
          <div class="section-heading">
            <div>
              <h3>Attachments</h3>
              <p>
                {{ attachments.length }} file{{ attachments.length === 1 ? '' : 's' }} attached.
              </p>
            </div>
            <span class="count-badge">{{ attachments.length }}</span>
          </div>
          <div v-if="attachments.length" class="ticket-file-list">
            <button
              v-for="file in attachments"
              :key="file.id"
              class="ticket-file"
              @click="downloadFile(`/api/tickets/${route.params.id}/attachments/${file.id}`)"
            >
              <span class="file-icon"><i class="pi pi-file" /></span
              ><span
                ><b>{{ file.filename }}</b
                ><small>{{ file.size }} bytes</small></span
              ><i class="pi pi-download file-download" />
            </button>
          </div>
          <div v-else class="attachment-empty">Wala pang naka-attach na file.</div>
        </section>

        <section v-if="auth.can('inventory.view')" class="panel ticket-assets-panel">
          <div class="section-heading">
            <div>
              <h3>Related assets</h3>
              <p>Equipment na naka-link sa ticket na ito.</p>
            </div>
            <span class="count-badge">{{ assets.length }}</span>
          </div>
          <RouterLink
            v-for="asset in assets"
            :key="asset.id"
            class="related-asset"
            :to="`/inventory/${asset.id}`"
            ><span class="related-asset-icon"><i class="pi pi-desktop" /></span
            ><span
              ><b>{{ asset.name }}</b
              ><small>{{ asset.asset_tag }}</small></span
            ><span class="badge" :class="`badge-${asset.status}`">{{ asset.status }}</span
            ><i class="pi pi-chevron-right related-chevron"
          /></RouterLink>
          <div v-if="!assets.length" class="attachment-empty">Walang naka-link na asset.</div>
        </section>
      </aside>
    </div>
  </div>
</template>
