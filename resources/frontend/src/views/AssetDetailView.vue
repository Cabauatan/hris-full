<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import http, { downloadFile } from '@/api/http'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const auth = useAuthStore()
const asset = ref(null)
const loading = ref(true)
const error = ref('')
const ticketError = ref('')
const ticketMessage = ref('')
const proofError = ref('')
const proofNotice = ref('')
const proofBusy = ref(false)
const purchaseBusy = ref(false)
const purchaseError = ref('')
const purchaseNotice = ref('')
const busy = ref(false)
const ticketBusy = ref(false)
const ticketsLoading = ref(false)
const action = ref('')
const move = ref({ holder_name: '', department: '', division: '', location: '', note: '' })
const purchase = ref({ quantity: 1, unit_cost: null, purchase_date: '', warranty_until: '' })
const stock = ref({
  action: 'issue',
  quantity: 1,
  recipient: '',
  employee_code: '',
  department: '',
  division: '',
  location: '',
  ticket_id: '',
  note: '',
})
const stockBusy = ref(false)
const stockError = ref('')
const stockNotice = ref('')
const notice = ref('')
const ticketSearch = ref('')
const tickets = ref([])
const selectedTicketId = ref('')
const canLinkTickets = computed(() => auth.can('tickets.view') && auth.can('tickets.update'))
const isConsumable = computed(() => asset.value?.type === 'consumable')
const linkedTicketIds = computed(
  () => new Set((asset.value?.tickets || []).map((ticket) => String(ticket.id))),
)
const stockTransactions = computed(() =>
  (asset.value?.history || []).filter((entry) =>
    ['stock_issue', 'stock_restock'].includes(entry.action),
  ),
)
const assetMovements = computed(() =>
  (asset.value?.history || []).filter(
    (entry) => !['stock_issue', 'stock_restock'].includes(entry.action),
  ),
)
const availableTickets = computed(() =>
  tickets.value.filter((ticket) => !linkedTicketIds.value.has(String(ticket.id))),
)
const assetActionTransitions = {
  available: ['deploy', 'repair', 'damage', 'retire'],
  deployed: ['transfer', 'return', 'damage', 'replace'],
  returned: ['deploy', 'repair', 'damage', 'retire'],
  damaged: ['repair', 'replace', 'retire'],
  replaced: ['retire'],
  repair: ['restore', 'replace', 'retire'],
  retired: [],
}
const actionLabels = {
  deploy: 'Deploy to employee',
  transfer: 'Transfer to another employee',
  return: 'Return to inventory',
  damage: 'Mark as damaged',
  replace: 'Mark as replaced',
  repair: 'Send for repair',
  restore: 'Restore to available',
  retire: 'Retire asset',
}
const actionResults = {
  deploy: 'deployed',
  transfer: 'deployed',
  return: 'returned',
  damage: 'damaged',
  replace: 'replaced',
  repair: 'repair',
  restore: 'available',
  retire: 'retired',
}
const availableActions = computed(() =>
  isConsumable.value ? [] : assetActionTransitions[asset.value?.status] || [],
)
function formatAssetStatus(value) {
  return value?.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()) || ''
}
function currency(value) {
  if (value === null || value === undefined || value === '') return '—'
  return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(
    Number(value),
  )
}
function fileSize(bytes) {
  const size = Number(bytes || 0)
  return size < 1024 * 1024
    ? `${Math.max(1, Math.round(size / 1024))} KB`
    : `${(size / (1024 * 1024)).toFixed(1)} MB`
}
function stockQuantity(note) {
  return String(note || '').match(/^Qty\s+(\d+)/i)?.[1] || '—'
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await http.get(`/api/inventory/${route.params.id}`)
    asset.value = data.data.asset
    move.value = {
      holder_name: asset.value.holder_name || '',
      department: asset.value.department || '',
      division: asset.value.division || '',
      location: asset.value.location || '',
      note: '',
    }
    purchase.value = {
      quantity: asset.value.quantity ?? 1,
      unit_cost: asset.value.unit_cost === null ? null : Number(asset.value.unit_cost),
      purchase_date: asset.value.purchase_date || '',
      warranty_until: asset.value.warranty_until || '',
    }
    if (!availableActions.value.includes(action.value))
      action.value = availableActions.value[0] || ''
    error.value = ''
  } catch (failure) {
    error.value = failure.response?.data?.message || 'Could not load the asset.'
  } finally {
    loading.value = false
  }
}

async function downloadProof(proof) {
  proofError.value = ''
  try {
    await downloadFile(`/api/inventory/${route.params.id}/attachments/${proof.id}`)
  } catch (failure) {
    proofError.value = failure.response?.data?.message || `Could not download ${proof.filename}.`
  }
}

async function loadTickets() {
  if (!auth.can('tickets.view')) return

  ticketsLoading.value = true
  ticketError.value = ''
  try {
    const { data } = await http.get('/api/tickets', {
      params: { search: ticketSearch.value.trim(), page: 1 },
    })
    tickets.value = data.data.tickets || []
  } catch (failure) {
    ticketError.value = failure.response?.data?.message || 'Could not load tickets.'
  } finally {
    ticketsLoading.value = false
  }
}

async function saveTicketLink(ticketId, link) {
  if (!asset.value || !ticketId || ticketBusy.value) return

  ticketBusy.value = true
  ticketError.value = ''
  ticketMessage.value = ''
  try {
    await http.post(`/api/inventory/${route.params.id}/tickets`, {
      version: asset.value.version,
      ticket_id: Number(ticketId),
      link,
    })
    selectedTicketId.value = ''
    await load()
    await loadTickets()
    ticketMessage.value = link ? 'Asset linked to ticket.' : 'Asset unlinked from ticket.'
  } catch (failure) {
    ticketError.value = failure.response?.data?.message || 'Could not update the ticket link.'
  } finally {
    ticketBusy.value = false
  }
}

async function linkSelectedTicket() {
  await saveTicketLink(selectedTicketId.value, true)
}

async function record() {
  if (!action.value || busy.value) return
  busy.value = true
  error.value = ''
  notice.value = ''
  const resultingStatus = actionResults[action.value]
  try {
    await http.post(`/api/inventory/${route.params.id}/movements`, {
      version: asset.value.version,
      action: action.value,
      ...move.value,
    })
    await load()
    notice.value = `Asset status updated to ${formatAssetStatus(resultingStatus)}.`
  } catch (failure) {
    error.value = failure.response?.data?.message || 'Could not save the asset movement.'
  } finally {
    busy.value = false
  }
}

async function recordStock() {
  if (!stock.value.action || stockBusy.value) return
  stockBusy.value = true
  stockError.value = ''
  stockNotice.value = ''
  const action = stock.value.action
  try {
    await http.post(`/api/inventory/${route.params.id}/stock`, {
      version: asset.value.version,
      ...stock.value,
      quantity: Number(stock.value.quantity),
      ticket_id: stock.value.ticket_id ? Number(stock.value.ticket_id) : null,
    })
    await load()
    stockNotice.value =
      action === 'issue' ? 'Stock issued and recorded.' : 'Stock replenished and recorded.'
    stock.value = {
      ...stock.value,
      quantity: 1,
      recipient: '',
      employee_code: '',
      department: '',
      division: '',
      location: '',
      ticket_id: '',
      note: '',
    }
  } catch (failure) {
    stockError.value =
      failure.response?.data?.errors?.asset?.[0] ||
      failure.response?.data?.message ||
      'Could not save the stock movement.'
  } finally {
    stockBusy.value = false
  }
}

async function uploadProof(file) {
  if (!file || proofBusy.value) return
  if (file.size > 10 * 1024 * 1024) {
    proofError.value = 'This file exceeds the 10 MB upload limit. Choose a smaller file.'
    return
  }

  proofBusy.value = true
  proofError.value = ''
  proofNotice.value = ''
  const form = new FormData()
  form.append('file', file)
  try {
    await http.post(`/api/inventory/${route.params.id}/attachments`, form)
    await load()
    proofNotice.value = 'Purchase proof uploaded.'
  } catch (failure) {
    const response = failure.response
    proofError.value =
      response?.data?.errors?.file?.[0] ||
      response?.data?.message ||
      (response?.status === 413
        ? 'This file exceeds the server upload limit. Choose a file under 10 MB and try again.'
        : failure.code === 'ECONNABORTED'
          ? 'The upload timed out. Check your connection and try again.'
          : !response
            ? 'Could not reach the server. Check your connection and try again.'
            : `Upload failed (HTTP ${response.status}). Please try again.`)
  } finally {
    proofBusy.value = false
  }
}

function onProofSelected(event) {
  const input = event.target
  void uploadProof(input.files?.[0])
  input.value = ''
}

async function savePurchaseDetails() {
  if (!asset.value || purchaseBusy.value) return
  purchaseBusy.value = true
  purchaseError.value = ''
  purchaseNotice.value = ''
  try {
    await http.patch(`/api/inventory/${route.params.id}`, {
      version: asset.value.version,
      asset_tag: asset.value.asset_tag,
      name: asset.value.name,
      type: asset.value.type,
      serial_number: asset.value.serial_number,
      brand: asset.value.brand,
      model: asset.value.model,
      specifications: asset.value.specifications,
      division: asset.value.division,
      location: asset.value.location,
      accessories: asset.value.accessories,
      quantity: Number(purchase.value.quantity),
      unit_cost: purchase.value.unit_cost === '' ? null : purchase.value.unit_cost,
      purchase_date: purchase.value.purchase_date || null,
      warranty_until: purchase.value.warranty_until || null,
    })
    await load()
    purchaseNotice.value = 'Quantity and purchase details saved.'
  } catch (failure) {
    purchaseError.value = failure.response?.data?.message || 'Could not save the purchase details.'
  } finally {
    purchaseBusy.value = false
  }
}

watch(
  () => route.params.id,
  async () => {
    asset.value = null
    await load()
    if (auth.can('tickets.view')) await loadTickets()
  },
  { immediate: true },
)
const refresh = async () => {
  await load()
  if (auth.can('tickets.view')) await loadTickets()
}
onMounted(() => window.addEventListener('supportdesk:refresh', refresh))
onBeforeUnmount(() => window.removeEventListener('supportdesk:refresh', refresh))
</script>

<template>
  <p v-if="error && asset" class="error-box">{{ error }}</p>
  <p v-if="notice" class="success-box">{{ notice }}</p>
  <div v-if="loading && !asset" class="panel asset-view-state">
    <i class="pi pi-spin pi-spinner" /> Loading asset details…
  </div>
  <section v-else-if="!asset" class="panel asset-view-state">
    <span class="asset-view-state-icon"><i class="pi pi-box" /></span>
    <h2>Asset unavailable</h2>
    <p>{{ error || 'This asset could not be found.' }}</p>
    <div class="asset-view-state-actions">
      <button class="button button-primary" :disabled="loading" @click="load">
        <i class="pi pi-refresh" />{{ loading ? 'Loading…' : 'Try again' }}</button
      ><RouterLink class="button button-secondary" to="/inventory">Back to inventory</RouterLink>
    </div>
  </section>
  <div v-if="asset" class="asset-page">
    <header class="asset-heading">
      <div class="asset-heading-main">
        <RouterLink class="back-link" to="/inventory"
          ><i class="pi pi-arrow-left" /> Back to inventory</RouterLink
        >
        <div class="asset-title-line">
          <span class="asset-icon"><i class="pi pi-desktop" /></span>
          <div class="asset-title-copy">
            <span class="eyebrow">INVENTORY ASSET</span>
            <h2>{{ asset.name }}</h2>
            <div class="asset-meta">
              <span>{{ asset.asset_tag }}</span
              ><span>{{ asset.type }}</span
              ><span v-if="asset.serial_number">Serial {{ asset.serial_number }}</span>
            </div>
          </div>
        </div>
      </div>
      <span class="badge asset-status" :class="`badge-${asset.status}`"
        ><i class="pi pi-circle-fill" /> {{ formatAssetStatus(asset.status) }}</span
      >
    </header>

    <div class="asset-detail-layout">
      <main class="asset-main-column">
        <section class="panel asset-info-panel">
          <div class="section-heading">
            <div>
              <h3>Asset information</h3>
              <p>Key details and current assignment.</p>
            </div>
          </div>
          <div class="asset-info-grid">
            <div class="asset-info-item">
              <span>Brand / model</span
              ><b>{{ [asset.brand, asset.model].filter(Boolean).join(' ') || '—' }}</b>
            </div>
            <div class="asset-info-item">
              <span>Division</span><b>{{ asset.division || '—' }}</b>
            </div>
            <div class="asset-info-item">
              <span>Location</span><b>{{ asset.location || '—' }}</b>
            </div>
            <div v-if="!isConsumable" class="asset-info-item">
              <span>Assigned to</span><b>{{ asset.holder_name || 'Unassigned' }}</b>
            </div>
            <div class="asset-info-item">
              <span>Department</span><b>{{ asset.department || '—' }}</b>
            </div>
            <div class="asset-info-item">
              <span>Accessories</span><b>{{ asset.accessories || '—' }}</b>
            </div>
            <div class="asset-info-item">
              <span>{{ isConsumable ? 'Stock on hand' : 'Quantity' }}</span
              ><b
                >{{ asset.quantity ?? 1 }}
                {{ isConsumable ? 'in stock' : `unit${(asset.quantity ?? 1) === 1 ? '' : 's'}` }}</b
              >
            </div>
            <div class="asset-info-item">
              <span>Unit cost</span><b>{{ currency(asset.unit_cost) }}</b>
            </div>
            <div class="asset-info-item">
              <span>Total value</span
              ><b>{{
                asset.unit_cost === null || asset.unit_cost === undefined
                  ? '—'
                  : currency((asset.quantity ?? 1) * asset.unit_cost)
              }}</b>
            </div>
            <div class="asset-info-item">
              <span>Purchase date</span><b>{{ asset.purchase_date || '—' }}</b>
            </div>
            <div class="asset-info-item">
              <span>Warranty until</span><b>{{ asset.warranty_until || '—' }}</b>
            </div>
          </div>
        </section>

        <section v-if="isConsumable" class="panel stock-history-panel">
          <div class="section-heading">
            <div>
              <h3>Movement history</h3>
              <p>Stock issues and restocks are recorded here with their quantity and recipient.</p>
            </div>
            <span class="count-badge">{{ stockTransactions.length }}</span>
          </div>
          <div v-if="stockTransactions.length" class="stock-transaction-list">
            <article
              v-for="entry in stockTransactions"
              :key="entry.id"
              class="stock-transaction-entry"
            >
              <span
                class="stock-transaction-icon"
                :class="entry.action === 'stock_issue' ? 'is-issue' : 'is-restock'"
                ><i
                  :class="
                    entry.action === 'stock_issue'
                      ? 'pi pi-arrow-up-right'
                      : 'pi pi-arrow-down-left'
                  "
              /></span>
              <div class="stock-transaction-copy">
                <div class="stock-transaction-heading">
                  <b>{{ entry.action === 'stock_issue' ? 'Stock issued' : 'Stock restocked' }}</b>
                  <span class="stock-transaction-quantity"
                    >{{ stockQuantity(entry.note) }}
                    {{ Number(stockQuantity(entry.note)) === 1 ? 'unit' : 'units' }}</span
                  >
                </div>
                <small
                  >{{ entry.created_at
                  }}<template v-if="entry.actor"> · {{ entry.actor }}</template></small
                >
                <p>
                  {{
                    entry.note?.replace(/^Qty\s+\d+\s+/i, '') || 'No additional details recorded.'
                  }}
                </p>
              </div>
            </article>
          </div>
          <div v-else class="history-empty">
            No stock issues or restocks have been recorded yet.
          </div>
        </section>

        <section class="panel proof-attachments-panel">
          <div class="section-heading">
            <div>
              <h3>Purchase proof</h3>
              <p>Invoices or receipts that document this asset purchase.</p>
            </div>
            <span class="count-badge">{{ asset.proof_attachments?.length || 0 }}</span>
          </div>
          <p v-if="proofError" class="error-box">{{ proofError }}</p>
          <p v-if="proofNotice" class="success-box">{{ proofNotice }}</p>
          <div v-if="asset.proof_attachments?.length" class="proof-file-list">
            <div v-for="proof in asset.proof_attachments" :key="proof.id" class="proof-file">
              <span class="proof-file-icon"><i class="pi pi-file-pdf" /></span
              ><span class="proof-file-copy"
                ><b>{{ proof.filename }}</b
                ><small>{{ fileSize(proof.size) }} · {{ proof.created_at }}</small></span
              ><button
                type="button"
                class="proof-download"
                :aria-label="`Download ${proof.filename}`"
                @click="downloadProof(proof)"
              >
                <i class="pi pi-download" />
              </button>
            </div>
          </div>
          <div v-else class="proof-empty">No invoice or receipt has been attached yet.</div>
          <label
            v-if="auth.can('inventory.manage')"
            class="proof-upload"
            :class="{ disabled: proofBusy }"
            ><input
              type="file"
              accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
              :disabled="proofBusy"
              @change="onProofSelected"
            /><i :class="proofBusy ? 'pi pi-spin pi-spinner' : 'pi pi-paperclip'" />{{
              proofBusy ? 'Uploading…' : 'Attach purchase proof'
            }}<small>PDF, JPG, or PNG · up to 10 MB</small></label
          >
        </section>

        <section class="panel ticket-panel">
          <div class="section-heading">
            <div>
              <h3>
                Related tickets
                <span class="count-badge">{{ asset.tickets?.length || 0 }}</span>
              </h3>
              <p>Link this equipment to a related request or incident.</p>
            </div>
          </div>
          <p v-if="ticketMessage" class="success-box">{{ ticketMessage }}</p>
          <p v-if="ticketError" class="error-box">{{ ticketError }}</p>

          <div v-if="asset.tickets?.length" class="linked-ticket-list">
            <div v-for="ticket in asset.tickets" :key="ticket.id" class="linked-ticket-row">
              <RouterLink class="linked-ticket-info" :to="`/tickets/${ticket.id}`">
                <span class="ticket-link-icon"><i class="pi pi-ticket" /></span>
                <span class="linked-ticket-copy"
                  ><b>{{ ticket.subject }}</b
                  ><small>{{ ticket.category || 'Support request' }}</small></span
                >
              </RouterLink>
              <div class="linked-ticket-actions">
                <span class="badge" :class="`badge-${ticket.status}`">{{
                  ticket.status?.replace('_', ' ')
                }}</span
                ><button
                  v-if="canLinkTickets"
                  class="button button-quiet unlink-button"
                  :disabled="ticketBusy"
                  @click="saveTicketLink(ticket.id, false)"
                >
                  <i class="pi pi-unlink" /><span>Unlink</span>
                </button>
              </div>
            </div>
          </div>
          <div v-else class="ticket-empty">
            <span><i class="pi pi-link" /></span><b>No linked tickets</b
            ><small>Linked tickets and their statuses will appear here.</small>
          </div>

          <form v-if="canLinkTickets" class="ticket-link-form" @submit.prevent="linkSelectedTicket">
            <div class="ticket-link-form-heading">
              <b>Link a ticket</b><small>Search by subject.</small>
            </div>
            <div class="ticket-search-control">
              <label class="ticket-search-input"
                ><i class="pi pi-search" /><input
                  v-model="ticketSearch"
                  class="input"
                  placeholder="Search ticket subjects"
                  aria-label="Search tickets"
                  @keyup.enter.prevent="loadTickets" /></label
              ><button
                type="button"
                class="button button-secondary"
                :disabled="ticketsLoading"
                @click="loadTickets"
              >
                {{ ticketsLoading ? 'Searching…' : 'Search' }}
              </button>
            </div>
            <div class="ticket-select-row">
              <label class="field-label"
                >Ticket<select v-model="selectedTicketId" class="select" required>
                  <option value="" disabled>
                    {{ ticketsLoading ? 'Loading tickets…' : 'Select a ticket' }}
                  </option>
                  <option
                    v-for="ticket in availableTickets"
                    :key="ticket.id"
                    :value="String(ticket.id)"
                  >
                    {{ ticket.subject || 'Ticket' }} ({{ ticket.status?.replace('_', ' ') }})
                  </option>
                </select></label
              ><button
                class="button button-primary link-ticket-button"
                :disabled="ticketBusy || !selectedTicketId"
              >
                <i class="pi pi-link" />{{ ticketBusy ? 'Saving…' : 'Link ticket' }}
              </button>
            </div>
            <small v-if="!ticketsLoading && !availableTickets.length" class="ticket-search-hint"
              >No tickets found. Search to find more.</small
            >
          </form>
          <p v-else class="permission-hint">
            <i class="pi pi-info-circle" /> Ticket update permission is required to link or unlink
            tickets.
          </p>
        </section>

        <section v-if="!isConsumable" class="panel movement-history-panel">
          <div class="section-heading">
            <div>
              <h3>Movement history</h3>
              <p>Recorded changes to this asset.</p>
            </div>
            <span class="count-badge">{{ assetMovements.length }}</span>
          </div>
          <div v-if="assetMovements.length" class="movement-timeline">
            <article v-for="history in assetMovements" :key="history.id" class="movement-entry">
              <span class="movement-dot"><i class="pi pi-history" /></span>
              <div>
                <b>{{ history.action?.replaceAll('_', ' ') }}</b
                ><small
                  >{{ history.created_at }}
                  <template v-if="history.actor">· {{ history.actor }}</template></small
                >
                <p>
                  {{
                    [history.holder_name, history.location, history.note]
                      .filter(Boolean)
                      .join(' · ') || 'No additional details'
                  }}
                </p>
              </div>
            </article>
          </div>
          <div v-else class="history-empty">No movements have been recorded yet.</div>
        </section>
      </main>

      <aside class="asset-side-column">
        <form
          v-if="auth.can('inventory.manage')"
          class="panel purchase-details-panel"
          @submit.prevent="savePurchaseDetails"
        >
          <div class="section-heading">
            <div>
              <h3>Quantity & purchase</h3>
              <p>Stock count and purchase value.</p>
            </div>
          </div>
          <p v-if="purchaseError" class="error-box">{{ purchaseError }}</p>
          <p v-if="purchaseNotice" class="success-box">{{ purchaseNotice }}</p>
          <div class="purchase-details-grid">
            <label class="field-label"
              >{{ isConsumable ? 'Stock on hand' : 'Quantity'
              }}<input
                v-model.number="purchase.quantity"
                class="input"
                type="number"
                :min="isConsumable ? 0 : 1"
                max="999999"
                required
                :readonly="isConsumable || Boolean(asset.serial_number)"
              /><small v-if="asset.serial_number" class="field-help"
                >Quantity is fixed at 1 for serialized assets.</small
              ></label
            >
            <label class="field-label"
              >Unit cost (PHP)<input
                v-model.number="purchase.unit_cost"
                class="input"
                type="number"
                min="0"
                max="9999999999.99"
                step="0.01"
                placeholder="Not recorded"
            /></label>
            <label class="field-label"
              >Purchase date<input v-model="purchase.purchase_date" class="input" type="date"
            /></label>
            <label class="field-label"
              >Warranty until<input v-model="purchase.warranty_until" class="input" type="date"
            /></label>
          </div>
          <div class="purchase-total-line">
            <span>Total value</span
            ><b>{{
              purchase.unit_cost === null || purchase.unit_cost === ''
                ? '—'
                : currency((purchase.quantity ?? 1) * purchase.unit_cost)
            }}</b>
          </div>
          <button class="button button-primary purchase-save-button" :disabled="purchaseBusy">
            <i class="pi pi-check" />{{ purchaseBusy ? 'Saving…' : 'Save purchase details' }}
          </button>
        </form>

        <form
          v-if="auth.can('inventory.manage') && isConsumable"
          class="panel movement-form-panel"
          @submit.prevent="recordStock"
        >
          <div class="section-heading">
            <div>
              <h3>Consumable stock</h3>
              <p>Record received stock or supplies issued to a user.</p>
            </div>
          </div>
          <p v-if="stockError" class="error-box">{{ stockError }}</p>
          <p v-if="stockNotice" class="success-box">{{ stockNotice }}</p>
          <div class="movement-form-grid">
            <label class="field-label full"
              >Action<select v-model="stock.action" class="select">
                <option value="issue">Issue / consume stock</option>
                <option value="restock">Receive / restock</option></select
              ><small class="status-transition-hint">{{
                stock.action === 'issue'
                  ? 'Stock will be reduced after the recipient is recorded.'
                  : 'The received quantity will be added to stock.'
              }}</small></label
            >
            <label class="field-label full"
              >Quantity<input
                v-model.number="stock.quantity"
                class="input"
                type="number"
                min="1"
                :max="stock.action === 'issue' ? asset.quantity : 999999"
                required
            /></label>
            <label v-if="stock.action === 'issue'" class="field-label"
              >Issued to<input
                v-model="stock.recipient"
                class="input"
                placeholder="Employee or requester name"
                required
            /></label>
            <label v-if="stock.action === 'issue'" class="field-label"
              >Employee number<input
                v-model="stock.employee_code"
                class="input"
                placeholder="Optional"
            /></label>
            <label v-if="stock.action === 'issue'" class="field-label"
              >Department<input v-model="stock.department" class="input" placeholder="Optional"
            /></label>
            <label class="field-label"
              >Division<input v-model="stock.division" class="input" placeholder="Optional"
            /></label>
            <label class="field-label"
              >Location<input v-model="stock.location" class="input" placeholder="Optional"
            /></label>
            <label class="field-label"
              >Related ticket<select
                v-model="stock.ticket_id"
                class="select"
                :disabled="ticketsLoading || !auth.can('tickets.view')"
              >
                <option value="">No related ticket</option>
                <option v-for="ticket in tickets" :key="ticket.id" :value="String(ticket.id)">
                  {{ ticket.subject || 'Ticket' }} ({{ ticket.status?.replaceAll('_', ' ') }})
                </option>
              </select></label
            >
            <label class="field-label full"
              >Note<textarea
                v-model="stock.note"
                class="textarea"
                placeholder="e.g. Issued for a new workstation"
                required
              />
            </label>
          </div>
          <button class="button button-primary movement-submit" :disabled="stockBusy">
            <i class="pi pi-check" />{{
              stockBusy ? 'Saving…' : stock.action === 'issue' ? 'Issue stock' : 'Record restock'
            }}
          </button>
        </form>
        <form
          v-else-if="auth.can('inventory.manage') && !isConsumable"
          class="panel movement-form-panel"
          @submit.prevent="record"
        >
          <div class="section-heading">
            <div>
              <h3>Record movement</h3>
              <p>Update the asset status or assignment.</p>
            </div>
          </div>
          <div v-if="availableActions.length" class="movement-form-grid">
            <label class="field-label full"
              >Action<select v-model="action" class="select" :disabled="!availableActions.length">
                <option v-if="!availableActions.length" value="">No actions available</option>
                <option v-for="value in availableActions" :key="value" :value="value">
                  {{ actionLabels[value] }} ·
                  {{ formatAssetStatus(actionResults[value]) }}
                </option></select
              ><small class="status-transition-hint">{{
                action
                  ? `This will set the status to ${formatAssetStatus(actionResults[action])}.`
                  : 'Retired assets cannot be moved.'
              }}</small></label
            >
            <label v-if="['deploy', 'transfer'].includes(action)" class="field-label"
              >Holder<input
                v-model="move.holder_name"
                class="input"
                placeholder="Employee name"
                required
            /></label>
            <label v-if="['deploy', 'transfer'].includes(action)" class="field-label"
              >Department<input
                v-model="move.department"
                class="input"
                placeholder="Department"
                required
            /></label>
            <label v-if="['deploy', 'transfer'].includes(action)" class="field-label"
              >Division<input v-model="move.division" class="input" placeholder="Optional"
            /></label>
            <label class="field-label"
              >Location<input
                v-model="move.location"
                class="input"
                placeholder="Where is the asset?"
                required
            /></label>
            <label class="field-label full"
              >Note<textarea
                v-model="move.note"
                class="textarea"
                placeholder="Add a short note about this change"
                required
              />
            </label>
          </div>
          <div v-else class="movement-no-actions">
            <span><i class="pi pi-lock" /></span>
            <div>
              <b>No actions available</b>
              <p>This asset is retired and can no longer be deployed or transferred.</p>
            </div>
          </div>
          <button
            v-if="availableActions.length"
            class="button button-primary movement-submit"
            :disabled="busy || !action"
          >
            <i class="pi pi-check" />{{ busy ? 'Saving…' : 'Save movement' }}
          </button>
        </form>
        <div v-if="!isConsumable" class="asset-help-card">
          <span><i class="pi pi-info-circle" /></span>
          <div>
            <b>Keep the asset record current</b>
            <p>
              Record a movement whenever equipment is deployed, returned, repaired, or replaced.
            </p>
          </div>
        </div>
      </aside>
    </div>
  </div>
</template>
