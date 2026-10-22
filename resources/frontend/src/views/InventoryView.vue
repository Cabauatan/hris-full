<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import http from '@/api/http'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const rows = ref([])
const search = ref('')
const status = ref('')
const total = ref(0)
const loading = ref(false)
const error = ref('')
const filtersActive = computed(() => Boolean(search.value || status.value))

function currency(value) {
    if (value === null || value === undefined || value === '') return '—'
    return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(
        Number(value),
    )
}

function statusLabel(value) {
    return value?.replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()) || ''
}

async function load() {
    loading.value = true
    error.value = ''
    try {
        const { data } = await http.get('/api/inventory', {
            params: { search: search.value.trim(), status: status.value },
        })
        rows.value = data.data.assets || []
        total.value = data.data.total || 0
    } catch (failure) {
        error.value = failure.response?.data?.message || 'Unable to load inventory.'
    } finally {
        loading.value = false
    }
}

function resetFilters() {
    search.value = ''
    status.value = ''
    load()
}

const refresh = () => load()
onMounted(() => {
    load()
    window.addEventListener('supportdesk:refresh', refresh)
})
onBeforeUnmount(() => window.removeEventListener('supportdesk:refresh', refresh))
</script>

<template>
    <section class="inventory-page">
        <div class="inventory-heading">
            <div>
                <span class="eyebrow">ASSET MANAGEMENT</span>
                <h2>Inventory</h2>
                <p>Track equipment, assignments, condition, and available stock.</p>
            </div>
            <RouterLink
                v-if="auth.can('inventory.manage')"
                class="button button-primary add-asset-button"
                to="/inventory/new"
            >
                <i class="pi pi-plus" /> Add asset
            </RouterLink>
        </div>

        <div class="inventory-summary">
            <span class="summary-icon"><i class="pi pi-desktop" /></span>
            <span
                ><b>{{ total }}</b
                ><small>{{ total === 1 ? 'inventory item' : 'inventory items' }}</small></span
            >
        </div>

        <p v-if="error" class="error-box">{{ error }}</p>

        <section class="panel inventory-panel">
            <div class="inventory-panel-heading">
                <div>
                    <h3>Inventory items</h3>
                    <p>Search by asset tag, name, or serial number.</p>
                </div>
                <span v-if="filtersActive" class="filter-indicator"
                    ><i class="pi pi-filter" /> Filtered</span
                >
            </div>

            <form class="inventory-filters" @submit.prevent="load">
                <label class="search-field">
                    <i class="pi pi-search" />
                    <input
                        v-model="search"
                        class="input"
                        placeholder="Search assets…"
                        aria-label="Search assets"
                    />
                </label>
                <label class="status-filter">
                    <span>Status</span>
                    <select v-model="status" class="select" aria-label="Filter by status">
                        <option value="">All statuses</option>
                        <option
                            v-for="value in [
                                'available',
                                'out_of_stock',
                                'deployed',
                                'returned',
                                'damaged',
                                'replaced',
                                'repair',
                                'retired',
                            ]"
                            :key="value"
                            :value="value"
                        >
                            {{ statusLabel(value) }}
                        </option>
                    </select>
                </label>
                <button class="button button-primary filter-submit" :disabled="loading">
                    {{ loading ? 'Searching…' : 'Search' }}
                </button>
                <button
                    v-if="filtersActive"
                    type="button"
                    class="button button-quiet reset-filter"
                    @click="resetFilters"
                >
                    Clear
                </button>
            </form>

            <div v-if="loading && !rows.length" class="inventory-loading">
                <i class="pi pi-spin pi-spinner" /> Loading assets…
            </div>
            <template v-else-if="rows.length">
                <div class="table-wrap inventory-table-wrap">
                    <table class="data-table inventory-table">
                        <thead>
                            <tr>
                                <th>Asset</th>
                                <th>Type</th>
                                <th>Serial number</th>
                                <th>Qty</th>
                                <th>Unit cost</th>
                                <th>Total value</th>
                                <th>Division</th>
                                <th>Assigned to</th>
                                <th>Status</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="asset in rows" :key="asset.id">
                                <td>
                                    <RouterLink
                                        class="asset-table-link"
                                        :to="`/inventory/${asset.id}`"
                                        ><b>{{ asset.name }}</b
                                        ><small>{{ asset.asset_tag }}</small></RouterLink
                                    >
                                </td>
                                <td>
                                    <span class="asset-type"
                                        ><i
                                            :class="
                                                asset.type === 'consumable'
                                                    ? 'pi pi-box'
                                                    : 'pi pi-desktop'
                                            "
                                        />
                                        {{ asset.type }}</span
                                    >
                                </td>
                                <td>{{ asset.serial_number || '—' }}</td>
                                <td>{{ asset.quantity ?? 1 }}</td>
                                <td>{{ currency(asset.unit_cost) }}</td>
                                <td>
                                    {{
                                        asset.unit_cost === null || asset.unit_cost === undefined
                                            ? '—'
                                            : currency((asset.quantity ?? 1) * asset.unit_cost)
                                    }}
                                </td>
                                <td>{{ asset.division || '—' }}</td>
                                <td>
                                    {{
                                        asset.type === 'consumable'
                                            ? '—'
                                            : asset.holder_name || 'Unassigned'
                                    }}
                                </td>
                                <td>
                                    <span class="badge" :class="`badge-${asset.status}`">{{
                                        statusLabel(asset.status)
                                    }}</span>
                                </td>
                                <td>
                                    <RouterLink
                                        class="asset-open-link"
                                        :to="`/inventory/${asset.id}`"
                                        :aria-label="`Open ${asset.asset_tag}`"
                                        ><i class="pi pi-arrow-up-right"
                                    /></RouterLink>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="inventory-card-list">
                    <RouterLink
                        v-for="asset in rows"
                        :key="asset.id"
                        class="inventory-asset-card"
                        :to="`/inventory/${asset.id}`"
                    >
                        <span class="asset-card-icon"><i class="pi pi-desktop" /></span>
                        <span class="asset-card-main"
                            ><b>{{ asset.name }}</b
                            ><small>{{ asset.asset_tag }} · {{ asset.type }}</small></span
                        >
                        <span class="badge" :class="`badge-${asset.status}`">{{
                            statusLabel(asset.status)
                        }}</span>
                        <span class="asset-card-meta"
                            ><span
                                >Serial <b>{{ asset.serial_number || '—' }}</b></span
                            ><span
                                >{{ asset.type === 'consumable' ? 'In stock' : 'Quantity' }}
                                <b>{{ asset.quantity ?? 1 }}</b></span
                            ><span
                                >Unit cost <b>{{ currency(asset.unit_cost) }}</b></span
                            ><span
                                >Total value
                                <b>{{
                                    asset.unit_cost === null || asset.unit_cost === undefined
                                        ? '—'
                                        : currency((asset.quantity ?? 1) * asset.unit_cost)
                                }}</b></span
                            ><span
                                >Division <b>{{ asset.division || '—' }}</b></span
                            ><span v-if="asset.type !== 'consumable'"
                                >Assigned to <b>{{ asset.holder_name || 'Unassigned' }}</b></span
                            ></span
                        >
                        <i class="pi pi-chevron-right asset-card-chevron" />
                    </RouterLink>
                </div>
            </template>
            <div v-else class="inventory-empty">
                <span class="empty-icon"
                    ><i :class="filtersActive ? 'pi pi-search' : 'pi pi-box'"
                /></span>
                <b>{{ filtersActive ? 'No matching items' : 'No inventory items yet' }}</b>
                <p>
                    {{
                        filtersActive
                            ? 'Try another search or clear the filters.'
                            : 'Add your first equipment or consumable item to get started.'
                    }}
                </p>
                <button v-if="filtersActive" class="button button-secondary" @click="resetFilters">
                    Clear filters
                </button>
                <RouterLink
                    v-else-if="auth.can('inventory.manage')"
                    class="button button-primary"
                    to="/inventory/new"
                    ><i class="pi pi-plus" /> Add first item</RouterLink
                >
            </div>
        </section>
    </section>
</template>
