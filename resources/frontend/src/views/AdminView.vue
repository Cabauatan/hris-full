<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import Dialog from 'primevue/dialog'
import http from '@/api/http'
import { useAuthStore } from '@/stores/auth'
import { roleLabel } from '@/utils/roleLabel'

const auth = useAuthStore()
const users = ref([]), search = ref(''), audit = ref([]), categories = ref([])
const error = ref(''), success = ref(''), loading = ref(false), savingUser = ref(false), savingCategory = ref(false)
const category = ref({ slug: '', name: '', active: true })
const permissions = ref(null), permissionDraft = ref({}), savingRole = ref('')
const canManageSuperadmins = ref(false), activeTab = ref('users')
const editingUser = ref(null), roleDraft = ref([])
const auditQuery = ref(''), auditSource = ref('all'), auditFrom = ref(''), auditTo = ref('')

const roleOptions = computed(() => ['requester', 'agent', 'dispatcher', 'inventory_manager', 'admin', ...(canManageSuperadmins.value ? ['superadmin'] : [])])
const tabs = computed(() => [
    { id: 'users', label: 'Users', icon: 'pi pi-users', visible: auth.can('users.view') },
    { id: 'categories', label: 'Categories', icon: 'pi pi-sitemap', visible: auth.can('categories.manage') },
    { id: 'permissions', label: 'Permissions', icon: 'pi pi-shield', visible: Boolean(permissions.value) },
    { id: 'audit', label: 'Audit trail', icon: 'pi pi-history', visible: auth.can('audit.view') },
].filter((tab) => tab.visible))
const staffCount = computed(() => users.value.filter((user) => (user.roles || []).some((role) => ['agent', 'dispatcher', 'admin', 'superadmin'].includes(role))).length)
const inventoryCount = computed(() => users.value.filter((user) => (user.roles || []).includes('inventory_manager')).length)
const filteredAudit = computed(() => {
    const query = auditQuery.value.trim().toLocaleLowerCase()
    return audit.value.filter((entry) => {
        if (auditSource.value !== 'all' && entry.source !== auditSource.value) return false
        const date = localDateKey(entry.created_at)
        if (auditFrom.value && (!date || date < auditFrom.value)) return false
        if (auditTo.value && (!date || date > auditTo.value)) return false
        if (!query) return true
        return [entry.actor, entry.action, entry.detail, entry.source]
            .some((value) => String(value || '').toLocaleLowerCase().includes(query))
    })
})
const auditFiltersActive = computed(() => Boolean(auditQuery.value || auditSource.value !== 'all' || auditFrom.value || auditTo.value))

async function load() {
    loading.value = true
    error.value = ''
    try {
        const { data } = await http.get('/api/admin', { params: { search: search.value } })
        users.value = (data.data.users || []).map((user) => ({ ...user, roles: user.roles || [user.role] }))
        audit.value = data.data.audit || []
        categories.value = data.data.categories || []
        canManageSuperadmins.value = Boolean(data.data.canManageSuperadmins)
        if (!tabs.value.some((tab) => tab.id === activeTab.value)) activeTab.value = tabs.value[0]?.id || 'users'
    } catch (failure) {
        error.value = failure.response?.data?.message || 'Could not load the administration workspace.'
    } finally {
        loading.value = false
    }

    try {
        const { data } = await http.get('/api/admin/permissions')
        permissions.value = data.data
        for (const role of ['requester', 'agent', 'dispatcher', 'inventory_manager', 'admin']) {
            permissionDraft.value[role] = data.data.roles.filter((row) => row.role === role && Boolean(row.allowed)).map((row) => row.permission)
        }
    } catch {
        permissions.value = null
    }
}

function openRoleEditor(user) {
    if (user.id === auth.user?.id || (!canManageSuperadmins.value && user.roles.includes('superadmin'))) return
    editingUser.value = user
    roleDraft.value = [...user.roles]
    error.value = ''
    success.value = ''
}

async function saveUserRoles() {
    if (!editingUser.value || !roleDraft.value.length) return
    savingUser.value = true
    error.value = ''
    try {
        await http.patch(`/api/admin/users/${editingUser.value.id}/role`, { roles: roleDraft.value })
        success.value = `Roles updated for ${editingUser.value.name}.`
        editingUser.value = null
        await load()
    } catch (failure) {
        error.value = failure.response?.data?.message || 'Could not update user roles.'
    } finally {
        savingUser.value = false
    }
}

async function saveCategory() {
    savingCategory.value = true
    error.value = ''
    try {
        await http.post('/api/admin/categories', category.value)
        success.value = 'Ticket category created.'
        category.value = { slug: '', name: '', active: true }
        await load()
    } catch (failure) {
        error.value = failure.response?.data?.message || 'Could not save the category.'
    } finally {
        savingCategory.value = false
    }
}

async function savePermissions(role) {
    savingRole.value = role
    error.value = ''
    try {
        const version = permissions.value.roles.find((row) => row.role === role)?.version
        await http.put(`/api/admin/permissions/${role}`, { version, permissions: permissionDraft.value[role] })
        success.value = `${roleLabel(role)} permissions updated.`
        await load()
    } catch (failure) {
        error.value = failure.response?.data?.message || 'Could not save permissions.'
        await load()
    } finally {
        savingRole.value = ''
    }
}

function initials(name) { return String(name || '?').split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase() }
function auditAction(value) { return String(value || '').replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()) }
function auditTimestamp(value) {
    if (!value) return null
    const normalized = String(value).includes('T') ? String(value) : String(value).replace(' ', 'T')
    return new Date(/(?:Z|[+-]\d{2}:?\d{2})$/i.test(normalized) ? normalized : `${normalized}Z`)
}
function auditDate(value) {
    const date = auditTimestamp(value)
    return date && !Number.isNaN(date.valueOf()) ? new Intl.DateTimeFormat('en-PH', { year: 'numeric', month: 'short', day: '2-digit' }).format(date) : '—'
}
function auditTime(value) {
    const date = auditTimestamp(value)
    return date && !Number.isNaN(date.valueOf()) ? new Intl.DateTimeFormat('en-PH', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true, timeZoneName: 'short' }).format(date) : '—'
}
function localDateKey(value) {
    const date = auditTimestamp(value)
    if (!date || Number.isNaN(date.valueOf())) return ''
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}
function clearAuditFilters() { auditQuery.value = ''; auditSource.value = 'all'; auditFrom.value = ''; auditTo.value = '' }
function closeRoleEditor() { if (!savingUser.value) editingUser.value = null }
const refresh = () => load()
onMounted(() => { load(); window.addEventListener('supportdesk:refresh', refresh) })
onBeforeUnmount(() => window.removeEventListener('supportdesk:refresh', refresh))
</script>

<template>
  <main class="admin-page">
    <header class="admin-hero">
      <div class="admin-hero-copy">
        <span class="admin-eyebrow"><i class="pi pi-sliders-h" /> WORKSPACE CONTROL</span>
        <h2>Administration</h2>
        <p>Manage access, service setup, and review important changes across your support desk.</p>
      </div>
      <button class="button button-secondary admin-refresh" :disabled="loading" @click="load"><i :class="loading ? 'pi pi-spin pi-spinner' : 'pi pi-refresh'" /> Refresh</button>
    </header>

    <div v-if="error && !editingUser" class="admin-alert admin-alert-error" role="alert"><i class="pi pi-exclamation-circle" /><span>{{ error }}</span><button aria-label="Dismiss error" @click="error = ''"><i class="pi pi-times" /></button></div>
    <div v-if="success" class="admin-alert admin-alert-success" role="status"><i class="pi pi-check-circle" /><span>{{ success }}</span><button aria-label="Dismiss message" @click="success = ''"><i class="pi pi-times" /></button></div>

    <section class="admin-metrics" aria-label="Administration summary">
      <article class="admin-metric"><span class="admin-metric-icon blue"><i class="pi pi-users" /></span><div><small>Users shown</small><strong>{{ users.length }}</strong><span>In the current result</span></div></article>
      <article class="admin-metric"><span class="admin-metric-icon violet"><i class="pi pi-headphones" /></span><div><small>Support staff</small><strong>{{ staffCount }}</strong><span>Agents and administrators</span></div></article>
      <article class="admin-metric"><span class="admin-metric-icon green"><i class="pi pi-box" /></span><div><small>Inventory managers</small><strong>{{ inventoryCount }}</strong><span>With inventory access</span></div></article>
      <article class="admin-metric"><span class="admin-metric-icon amber"><i class="pi pi-history" /></span><div><small>Recent audit events</small><strong>{{ audit.length }}</strong><span>Available in this view</span></div></article>
    </section>

    <nav v-if="tabs.length" class="admin-tabs" aria-label="Administration sections">
      <button v-for="tab in tabs" :key="tab.id" class="admin-tab" :class="{ active: activeTab === tab.id }" :aria-current="activeTab === tab.id ? 'page' : undefined" @click="activeTab = tab.id"><i :class="tab.icon" />{{ tab.label }}<span v-if="tab.id === 'audit' && audit.length" class="admin-tab-count">{{ audit.length }}</span></button>
    </nav>

    <section v-if="activeTab === 'users'" class="admin-section">
      <div class="admin-section-heading"><div><span class="admin-section-kicker">ACCESS CONTROL</span><h3>User accounts</h3><p>Assign the roles each person needs. Users can hold multiple roles.</p></div><span class="admin-section-count">{{ users.length }} shown</span></div>
      <div class="admin-table-tools"><label class="admin-search"><i class="pi pi-search" /><input v-model="search" type="search" placeholder="Search by name or email" @keyup.enter="load"></label><button class="button button-primary" :disabled="loading" @click="load"><i class="pi pi-search" /> Search users</button></div>
      <div class="admin-table-wrap">
        <table class="data-table admin-user-table"><thead><tr><th>User</th><th>Account status</th><th>Assigned roles</th><th class="admin-actions-head">Actions</th></tr></thead>
          <tbody>
            <tr v-for="user in users" :key="user.id">
              <td><div class="admin-user-identity"><span class="admin-user-avatar">{{ initials(user.name) }}</span><span><strong>{{ user.name }}</strong><small>{{ user.email }}</small></span></div></td>
              <td><span class="admin-account-status" :class="user.email_verified_at ? 'verified' : 'pending'"><i :class="user.email_verified_at ? 'pi pi-check-circle' : 'pi pi-clock'" />{{ user.email_verified_at ? 'Verified' : 'Pending verification' }}</span></td>
              <td><div class="admin-role-list"><span v-for="value in user.roles" :key="value" class="admin-role-chip" :class="`role-${value}`">{{ roleLabel(value) }}</span></div></td>
              <td class="admin-actions-cell"><button class="admin-edit-button" :disabled="user.id === auth.user?.id || (!canManageSuperadmins && user.roles.includes('superadmin'))" @click="openRoleEditor(user)"><i :class="user.id === auth.user?.id ? 'pi pi-user' : 'pi pi-pencil'" />{{ user.id === auth.user?.id ? 'Your account' : !canManageSuperadmins && user.roles.includes('superadmin') ? 'Protected' : 'Edit roles' }}</button></td>
            </tr>
            <tr v-if="!loading && !users.length"><td colspan="4"><div class="admin-empty"><i class="pi pi-user-minus" /><strong>No users found</strong><span>Try another name or email address.</span></div></td></tr>
            <tr v-if="loading && !users.length"><td colspan="4"><div class="admin-empty"><i class="pi pi-spin pi-spinner" /><span>Loading user accounts…</span></div></td></tr>
          </tbody>
        </table>
      </div>
      <p v-if="!canManageSuperadmins" class="admin-protection-note"><i class="pi pi-lock" /> Superadmin accounts are protected. Only a Superadmin can manage them.</p>
    </section>

    <section v-else-if="activeTab === 'categories'" class="admin-section">
      <div class="admin-section-heading"><div><span class="admin-section-kicker">TICKET SETUP</span><h3>Ticket categories</h3><p>Keep request types clear so tickets reach the right team.</p></div></div>
      <form class="admin-category-form" @submit.prevent="saveCategory">
        <label><span>Category name</span><input v-model="category.name" class="input" placeholder="e.g. Hardware support" maxlength="80" required></label>
        <label><span>Category key</span><input v-model="category.slug" class="input" placeholder="e.g. hardware" maxlength="40" pattern="[a-z][a-z0-9_-]*" required><small>Lowercase letters, numbers, hyphens, or underscores.</small></label>
        <button class="button button-primary" :disabled="savingCategory"><i :class="savingCategory ? 'pi pi-spin pi-spinner' : 'pi pi-plus'" />{{ savingCategory ? 'Adding…' : 'Add category' }}</button>
      </form>
      <div class="admin-section-heading admin-list-heading"><div><h3>Available categories</h3><p>Categories currently offered when creating a ticket.</p></div><span class="admin-section-count">{{ categories.length }}</span></div>
      <div v-if="categories.length" class="admin-category-list"><div v-for="item in categories" :key="item.slug || item.id" class="admin-category-row"><span class="admin-category-icon"><i class="pi pi-tag" /></span><span><strong>{{ item.name }}</strong><small>{{ item.slug }}</small></span><span class="admin-account-status" :class="item.active ? 'verified' : 'pending'">{{ item.active ? 'Active' : 'Inactive' }}</span></div></div>
      <div v-else class="admin-empty"><i class="pi pi-tags" /><strong>No categories available</strong><span>Add the first ticket category above.</span></div>
    </section>

    <section v-else-if="activeTab === 'permissions' && permissions" class="admin-section">
      <div class="admin-section-heading"><div><span class="admin-section-kicker">ROLE POLICY</span><h3>Role permissions</h3><p>Choose which actions each role can perform. Changes take effect on the next request.</p></div><span class="admin-policy-badge"><i class="pi pi-shield" /> Superadmin control</span></div>
      <div class="admin-permission-grid">
        <article v-for="roleName in ['requester','agent','dispatcher','inventory_manager','admin']" :key="roleName" class="admin-permission-card">
          <header><span class="admin-role-mark" :class="`role-${roleName}`"><i class="pi pi-shield" /></span><div><h4>{{ roleLabel(roleName) }}</h4><small>{{ permissionDraft[roleName]?.length || 0 }} permissions enabled</small></div><button class="button button-primary admin-save-permissions" :disabled="savingRole === roleName" @click="savePermissions(roleName)"><i :class="savingRole === roleName ? 'pi pi-spin pi-spinner' : 'pi pi-check'" />{{ savingRole === roleName ? 'Saving' : 'Save' }}</button></header>
          <div class="admin-permission-list"><label v-for="(roles,key) in permissions.catalog" v-show="roles.includes(roleName)" :key="key" class="admin-permission-option"><input v-model="permissionDraft[roleName]" type="checkbox" :value="key"><span><strong>{{ key }}</strong><small>Allow this role to use this feature</small></span></label></div>
        </article>
      </div>
    </section>

    <section v-else-if="activeTab === 'audit'" class="admin-section">
      <div class="admin-section-heading"><div><span class="admin-section-kicker">ACCOUNTABILITY</span><h3>Audit trail</h3><p>Recent administrative, ticket, and inventory changes.</p></div><span class="admin-section-count">{{ auditFiltersActive ? `${filteredAudit.length} of ${audit.length} events` : `${audit.length} events` }}</span></div>
      <div v-if="audit.length" class="admin-audit-filters">
        <label class="admin-audit-search"><i class="pi pi-search" /><input v-model="auditQuery" type="search" placeholder="Search action, user, or details…" aria-label="Search audit trail"></label>
        <label class="admin-filter-field"><span>Area</span><select v-model="auditSource" class="select"><option value="all">All areas</option><option value="admin">Admin</option><option value="ticket">Ticket</option><option value="inventory">Inventory</option></select></label>
        <label class="admin-filter-field"><span>From</span><input v-model="auditFrom" class="input" type="date" aria-label="Filter audit trail from date"></label>
        <label class="admin-filter-field"><span>To</span><input v-model="auditTo" class="input" type="date" aria-label="Filter audit trail to date"></label>
        <button v-if="auditFiltersActive" class="admin-clear-filters" @click="clearAuditFilters"><i class="pi pi-times" /> Clear</button>
      </div>
      <div v-if="filteredAudit.length" class="admin-table-wrap"><table class="data-table admin-audit-table"><thead><tr><th>Date</th><th>Time</th><th>Area</th><th>Performed by</th><th>Action</th><th>Details</th></tr></thead><tbody><tr v-for="entry in filteredAudit" :key="`${entry.source || 'admin'}-${entry.id}`"><td class="admin-date-cell"><time :datetime="entry.created_at">{{ auditDate(entry.created_at) }}</time></td><td class="admin-time-cell"><time :datetime="entry.created_at">{{ auditTime(entry.created_at) }}</time></td><td><span class="admin-area-chip" :class="`area-${entry.source || 'admin'}`">{{ auditAction(entry.source || 'admin') }}</span></td><td>{{ entry.actor || 'System' }}</td><td><strong>{{ auditAction(entry.action) }}</strong></td><td class="admin-audit-detail">{{ entry.detail || '—' }}</td></tr></tbody></table></div>
      <div v-else class="admin-empty"><i :class="audit.length ? 'pi pi-filter-slash' : 'pi pi-history'" /><strong>{{ audit.length ? 'No matching audit events' : 'No audit activity yet' }}</strong><span>{{ audit.length ? 'Adjust your filters or clear them to see more results.' : 'Administrative changes will appear here.' }}</span><button v-if="audit.length && auditFiltersActive" class="button button-secondary" @click="clearAuditFilters">Clear filters</button></div>
    </section>

    <Dialog
      :visible="Boolean(editingUser)"
      modal
      class="admin-role-dialog"
      aria-labelledby="role-dialog-title"
      :show-header="false"
      :draggable="false"
      :close-on-escape="!savingUser"
      :dismissable-mask="!savingUser"
      :content-style="{ padding: '21px' }"
      @update:visible="!$event && closeRoleEditor()"
    >
      <template v-if="editingUser">
        <header class="admin-dialog-heading"><span class="admin-dialog-icon"><i class="pi pi-user-edit" /></span><button class="admin-dialog-close" aria-label="Close dialog" autofocus :disabled="savingUser" @click="closeRoleEditor"><i class="pi pi-times" /></button><div><span class="admin-section-kicker">ACCESS CONTROL</span><h3 id="role-dialog-title">Edit user roles</h3><p>Choose one or more roles for this account.</p></div></header>
        <div class="admin-dialog-user"><span class="admin-user-avatar">{{ initials(editingUser.name) }}</span><span><strong>{{ editingUser.name }}</strong><small>{{ editingUser.email }}</small></span></div>
        <div v-if="error" class="admin-alert admin-alert-error" role="alert"><i class="pi pi-exclamation-circle" /><span>{{ error }}</span></div>
        <div class="admin-role-picker"><label v-for="value in roleOptions" :key="value" class="admin-role-option" :class="{ selected: roleDraft.includes(value) }"><input v-model="roleDraft" type="checkbox" :value="value" :disabled="savingUser"><span class="admin-role-option-icon"><i :class="value === 'inventory_manager' ? 'pi pi-box' : value === 'requester' ? 'pi pi-user' : value === 'superadmin' || value === 'admin' ? 'pi pi-shield' : 'pi pi-headphones'" /></span><span><strong>{{ roleLabel(value) }}</strong><small>{{ value === 'requester' ? 'Create and follow own tickets' : value === 'agent' ? 'Handle assigned tickets' : value === 'dispatcher' ? 'View and assign support tickets' : value === 'inventory_manager' ? 'Manage inventory and stock' : value === 'admin' ? 'Manage users and system setup' : 'Full system access' }}</small></span><i class="pi pi-check-circle admin-role-check" /></label></div>
        <p v-if="canManageSuperadmins" class="admin-dialog-warning"><i class="pi pi-exclamation-triangle" /> Superadmin grants full access to every system feature.</p>
        <footer class="admin-dialog-actions"><button class="button button-secondary" :disabled="savingUser" @click="closeRoleEditor">Cancel</button><button class="button button-primary" :disabled="!roleDraft.length || savingUser" @click="saveUserRoles"><i :class="savingUser ? 'pi pi-spin pi-spinner' : 'pi pi-check'" />{{ savingUser ? 'Saving roles…' : 'Save roles' }}</button></footer>
      </template>
    </Dialog>
  </main>
</template>

<style scoped>
.admin-page { display: grid; gap: 18px; color: #233249; }
.admin-hero { display: flex; align-items: center; justify-content: space-between; gap: 20px; padding: 26px 28px; border: 1px solid #e4eaf3; border-radius: 16px; background: radial-gradient(circle at 92% 0%, #e9f0ff 0, transparent 31%), linear-gradient(120deg, #fff, #f8faff); box-shadow: 0 8px 28px #22395b08; }
.admin-eyebrow, .admin-section-kicker { color: #6680ad; font-size: 9px; font-weight: 800; letter-spacing: .13em; }
.admin-eyebrow { display: inline-flex; align-items: center; gap: 7px; }
.admin-hero h2 { margin: 9px 0 0; color: #17243b; font-size: 27px; letter-spacing: -.04em; }
.admin-hero p { max-width: 630px; margin: 7px 0 0; color: #78869a; font-size: 12px; line-height: 1.6; }
.admin-refresh { min-height: 39px; white-space: nowrap; }
.admin-metrics { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
.admin-metric { display: flex; align-items: center; gap: 12px; min-width: 0; padding: 15px 16px; border: 1px solid #e7ecf3; border-radius: 13px; background: #fff; box-shadow: 0 4px 14px #233b5b06; }
.admin-metric-icon { display: grid; width: 38px; height: 38px; flex: 0 0 38px; place-items: center; border-radius: 11px; font-size: 15px; }
.admin-metric-icon.blue { color: #3972dc; background: #edf3ff; }.admin-metric-icon.violet { color: #7857cf; background: #f3efff; }.admin-metric-icon.green { color: #278762; background: #eaf8f1; }.admin-metric-icon.amber { color: #bb8120; background: #fff6e5; }
.admin-metric div { display: grid; min-width: 0; gap: 2px; }.admin-metric small { overflow: hidden; color: #7c899b; font-size: 10px; text-overflow: ellipsis; white-space: nowrap; }.admin-metric strong { color: #1f2e44; font-size: 21px; line-height: 1.25; }.admin-metric div span { overflow: hidden; color: #9aa5b4; font-size: 9px; text-overflow: ellipsis; white-space: nowrap; }
.admin-tabs { display: flex; gap: 5px; overflow-x: auto; padding: 5px; border: 1px solid #e7ecf3; border-radius: 12px; background: #f7f9fc; }
.admin-tab { display: inline-flex; align-items: center; justify-content: center; gap: 8px; min-height: 38px; padding: 0 14px; border: 0; border-radius: 9px; background: transparent; color: #738197; font-size: 11px; font-weight: 700; white-space: nowrap; }.admin-tab:hover { color: #355fa9; background: #edf2fa; }.admin-tab.active { color: #2c60c7; background: #fff; box-shadow: 0 2px 7px #1b35520f; }.admin-tab-count { display: grid; min-width: 18px; height: 18px; place-items: center; padding: 0 5px; border-radius: 99px; background: #edf3ff; color: #3f6dbe; font-size: 9px; }
.admin-section { min-width: 0; padding: 22px; border: 1px solid #e5eaf2; border-radius: 15px; background: #fff; box-shadow: 0 5px 18px #233b5b07; }.admin-section-heading { display: flex; align-items: center; justify-content: space-between; gap: 15px; margin-bottom: 18px; }.admin-section-heading h3 { margin: 5px 0 0; color: #202e43; font-size: 17px; letter-spacing: -.025em; }.admin-section-heading p { margin: 5px 0 0; color: #8491a3; font-size: 11px; line-height: 1.5; }.admin-section-count { padding: 6px 10px; border: 1px solid #e5eaf2; border-radius: 99px; color: #6e7d92; background: #f8fafd; font-size: 10px; font-weight: 700; white-space: nowrap; }
.admin-table-tools { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 13px; }.admin-search { display: flex; align-items: center; gap: 9px; width: min(100%, 370px); height: 39px; padding: 0 12px; border: 1px solid #dfe6f0; border-radius: 9px; background: #fff; color: #95a2b4; }.admin-search:focus-within { border-color: #7fa5eb; box-shadow: 0 0 0 3px #3977f615; }.admin-search input { width: 100%; border: 0; outline: 0; color: #2d3c52; background: transparent; font-size: 11px; }.admin-table-tools .button { min-height: 39px; }
.admin-table-wrap { overflow: auto; border: 1px solid #e9edf3; border-radius: 11px; }.admin-user-table { min-width: 780px; }.admin-user-table th { padding-block: 12px; }.admin-user-table td { padding-block: 14px; vertical-align: middle; }.admin-user-identity { display: flex; align-items: center; gap: 10px; min-width: 190px; }.admin-user-avatar { display: grid; width: 35px; height: 35px; flex: 0 0 35px; place-items: center; border-radius: 11px; background: linear-gradient(145deg, #e9f0ff, #dce7fb); color: #4269ad; font-size: 10px; font-weight: 800; }.admin-user-identity > span:last-child, .admin-dialog-user > span:last-child { display: grid; gap: 4px; min-width: 0; }.admin-user-identity strong, .admin-dialog-user strong { color: #29384d; font-size: 11px; }.admin-user-identity small, .admin-dialog-user small { color: #8794a6; font-size: 10px; overflow-wrap: anywhere; }.admin-account-status { display: inline-flex; align-items: center; gap: 5px; padding: 5px 8px; border-radius: 99px; font-size: 9px; font-weight: 700; white-space: nowrap; }.admin-account-status.verified { color: #22805d; background: #eaf7f1; }.admin-account-status.pending { color: #a77a2d; background: #fff5df; }.admin-role-list { display: flex; flex-wrap: wrap; gap: 5px; }.admin-role-chip { display: inline-flex; align-items: center; min-height: 22px; padding: 3px 8px; border: 1px solid #e8edf4; border-radius: 99px; background: #f7f9fc; color: #64738a; font-size: 9px; font-weight: 700; white-space: nowrap; }.admin-role-chip.role-admin, .admin-role-chip.role-superadmin { color: #7255b7; border-color: #e9e0fb; background: #f6f1ff; }.admin-role-chip.role-inventory_manager { color: #278060; border-color: #d9efe5; background: #eff9f4; }.admin-role-chip.role-dispatcher { color: #3d69a8; border-color: #dde9fb; background: #f0f5ff; }.admin-actions-head, .admin-actions-cell { text-align: right !important; }.admin-edit-button { display: inline-flex; align-items: center; gap: 6px; padding: 7px 10px; border: 1px solid #dce5f2; border-radius: 8px; background: #fff; color: #45648f; font-size: 10px; font-weight: 700; white-space: nowrap; }.admin-edit-button:hover:not(:disabled) { border-color: #a9c2ed; background: #f6f9ff; color: #2f61b4; }.admin-edit-button:disabled { color: #98a2b0; cursor: not-allowed; }.admin-protection-note { display: flex; align-items: center; gap: 7px; margin: 12px 0 0; color: #8a96a7; font-size: 10px; }.admin-empty { display: grid; justify-items: center; gap: 7px; padding: 36px 16px; color: #929eae; text-align: center; }.admin-empty > i { margin-bottom: 2px; color: #a8b5c7; font-size: 20px; }.admin-empty strong { color: #53637a; font-size: 12px; }.admin-empty span { font-size: 10px; }
.admin-category-form { display: grid; grid-template-columns: 1fr 1fr auto; align-items: end; gap: 12px; padding: 16px; border: 1px solid #e8edf4; border-radius: 12px; background: #f9fbfe; }.admin-category-form label { display: grid; gap: 7px; color: #56677f; font-size: 10px; font-weight: 700; }.admin-category-form .input { min-height: 39px; font-size: 11px; }.admin-category-form small { color: #929eae; font-size: 9px; font-weight: 400; }.admin-category-form .button { min-height: 39px; }.admin-list-heading { margin: 25px 0 12px; }.admin-list-heading h3 { margin-top: 0; font-size: 13px; }.admin-list-heading p { font-size: 10px; }.admin-category-list { display: grid; gap: 7px; }.admin-category-row { display: flex; align-items: center; gap: 10px; padding: 11px 12px; border: 1px solid #ebeff5; border-radius: 10px; }.admin-category-icon { display: grid; width: 32px; height: 32px; place-items: center; border-radius: 9px; background: #eff4ff; color: #5177bf; font-size: 12px; }.admin-category-row > span:nth-child(2) { display: grid; gap: 3px; flex: 1; }.admin-category-row strong { color: #36465c; font-size: 11px; }.admin-category-row small { color: #8d99a9; font-size: 9px; }
.admin-policy-badge { display: inline-flex; align-items: center; gap: 6px; padding: 7px 10px; border-radius: 99px; background: #f3efff; color: #7356ba; font-size: 9px; font-weight: 700; white-space: nowrap; }.admin-permission-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }.admin-permission-card { min-width: 0; overflow: hidden; border: 1px solid #e7ecf3; border-radius: 12px; }.admin-permission-card > header { display: flex; align-items: center; gap: 9px; padding: 13px; border-bottom: 1px solid #edf0f5; background: #fafbfd; }.admin-role-mark { display: grid; width: 32px; height: 32px; flex: 0 0 32px; place-items: center; border-radius: 10px; background: #eff4ff; color: #4771bb; }.admin-role-mark.role-inventory_manager { color: #278060; background: #eaf7f1; }.admin-role-mark.role-admin { color: #7657bd; background: #f3efff; }.admin-permission-card header > div { min-width: 0; flex: 1; }.admin-permission-card h4 { margin: 0; color: #34445a; font-size: 11px; }.admin-permission-card header small { display: block; margin-top: 3px; color: #8b97a7; font-size: 9px; }.admin-save-permissions { min-height: 30px; padding: 6px 9px; font-size: 9px; }.admin-permission-list { display: grid; gap: 0; padding: 4px 12px; }.admin-permission-option { display: flex; align-items: center; gap: 9px; min-height: 45px; border-bottom: 1px solid #f0f2f6; cursor: pointer; }.admin-permission-option:last-child { border-bottom: 0; }.admin-permission-option input { accent-color: #3972dc; }.admin-permission-option span { display: grid; gap: 3px; }.admin-permission-option strong { color: #4b5b71; font-size: 10px; font-weight: 650; }.admin-permission-option small { color: #98a3b2; font-size: 9px; }
.admin-audit-table { min-width: 1000px; }.admin-audit-table td { vertical-align: top; }.admin-date-cell { min-width: 110px; white-space: nowrap; }.admin-time-cell { min-width: 130px; color: #738198 !important; white-space: nowrap; font-variant-numeric: tabular-nums; }.admin-area-chip { display: inline-flex; padding: 5px 8px; border-radius: 99px; background: #eef3fa; color: #61738d; font-size: 9px; font-weight: 700; }.admin-area-chip.area-inventory { color: #24805d; background: #eaf7f1; }.admin-area-chip.area-ticket { color: #3667b9; background: #edf3ff; }.admin-audit-detail { min-width: 260px; max-width: 420px; white-space: normal; overflow-wrap: anywhere; line-height: 1.5; }
.admin-audit-filters { display: grid; grid-template-columns: minmax(200px, 1.5fr) repeat(3, minmax(125px, .7fr)) auto; align-items: end; gap: 9px; margin-bottom: 13px; }.admin-audit-search { display: flex; align-items: center; gap: 8px; height: 38px; padding: 0 11px; border: 1px solid #dfe6f0; border-radius: 9px; color: #92a0b2; }.admin-audit-search:focus-within { border-color: #7fa5eb; box-shadow: 0 0 0 3px #3977f615; }.admin-audit-search input { width: 100%; min-width: 0; border: 0; outline: 0; background: transparent; color: #2d3c52; font-size: 10px; }.admin-filter-field { display: grid; gap: 5px; color: #77869b; font-size: 9px; font-weight: 700; }.admin-filter-field .select, .admin-filter-field .input { height: 38px; min-width: 0; padding: 0 9px; font-size: 10px; }.admin-clear-filters { display: inline-flex; align-items: center; justify-content: center; gap: 5px; height: 36px; padding: 0 9px; border: 0; border-radius: 8px; background: #f1f4f8; color: #63738a; font-size: 10px; font-weight: 700; }
.admin-alert { display: flex; align-items: flex-start; gap: 9px; padding: 11px 13px; border: 1px solid; border-radius: 10px; font-size: 11px; }.admin-alert > span { flex: 1; line-height: 1.5; }.admin-alert > button { padding: 0; border: 0; background: none; color: inherit; opacity: .65; }.admin-alert-error { border-color: #f2d7d3; background: #fff5f3; color: #ad4b40; }.admin-alert-success { border-color: #d5ecdf; background: #f1faf5; color: #267653; }
.admin-role-dialog { width: min(100%, 550px); max-height: min(90vh, 780px); overflow: hidden; padding: 0; border: 1px solid #e3e9f1; border-radius: 16px; background: #fff; box-shadow: 0 24px 80px #101c3240; }.admin-dialog-heading { position: relative; display: flex; align-items: flex-start; gap: 12px; padding-right: 32px; }.admin-dialog-icon { display: grid; width: 39px; height: 39px; flex: 0 0 39px; place-items: center; border-radius: 12px; background: #edf3ff; color: #4774c1; }.admin-dialog-heading h3 { margin: 5px 0 0; color: #24334a; font-size: 17px; }.admin-dialog-heading p { margin: 5px 0 0; color: #8390a2; font-size: 10px; }.admin-dialog-close { position: absolute; top: 0; right: 0; display: grid; width: 30px; height: 30px; place-items: center; border: 1px solid #e8edf3; border-radius: 9px; background: #fff; color: #758399; }.admin-dialog-user { display: flex; align-items: center; gap: 10px; margin: 18px 0 13px; padding: 11px; border: 1px solid #e8edf4; border-radius: 10px; background: #f9fbfe; }.admin-role-picker { display: grid; gap: 7px; }.admin-role-option { display: flex; align-items: center; gap: 10px; min-height: 56px; padding: 8px 10px; border: 1px solid #e8edf4; border-radius: 10px; cursor: pointer; }.admin-role-option.selected { border-color: #b9ccef; background: #f5f8ff; }.admin-role-option > input { position: absolute; opacity: 0; pointer-events: none; }.admin-role-option-icon { display: grid; width: 31px; height: 31px; flex: 0 0 31px; place-items: center; border-radius: 9px; background: #f0f4fa; color: #6d7f99; font-size: 12px; }.admin-role-option.selected .admin-role-option-icon { background: #e8f0ff; color: #3e6fc5; }.admin-role-option > span:nth-of-type(2) { display: grid; gap: 3px; flex: 1; }.admin-role-option strong { color: #3e4e65; font-size: 10px; }.admin-role-option small { color: #8c98a8; font-size: 9px; }.admin-role-check { color: #b8c2d0; }.admin-role-option.selected .admin-role-check { color: #3972dc; }.admin-dialog-warning { display: flex; gap: 7px; margin: 12px 0 0; color: #9e742d; font-size: 9px; line-height: 1.5; }.admin-dialog-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 18px; padding-top: 14px; border-top: 1px solid #edf0f5; }
.admin-dialog-heading > div,
.admin-role-option > span:nth-of-type(2) {
  min-width: 0;
  overflow-wrap: anywhere;
}
.admin-dialog-user strong {
  overflow-wrap: anywhere;
}
.admin-role-option:focus-within {
  outline: 2px solid var(--accent);
  outline-offset: 2px;
}
.admin-dialog-actions {
  flex-wrap: wrap;
}
@media (max-width: 950px) { .admin-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); }.admin-permission-grid { grid-template-columns: 1fr; }.admin-audit-filters { grid-template-columns: repeat(2, minmax(0, 1fr)); }.admin-audit-search { grid-column: 1 / -1; } }
@media (max-width: 640px) { .admin-page { gap: 13px; }.admin-hero { align-items: flex-start; padding: 19px; }.admin-hero h2 { font-size: 22px; }.admin-hero p { max-width: 320px; font-size: 10px; }.admin-refresh { gap: 6px; padding: 8px 9px; font-size: 10px; }.admin-metrics { gap: 8px; }.admin-metric { align-items: flex-start; gap: 8px; padding: 11px 9px; }.admin-metric-icon { width: 30px; height: 30px; flex-basis: 30px; border-radius: 9px; font-size: 12px; }.admin-metric strong { font-size: 18px; }.admin-metric small { font-size: 9px; }.admin-metric div span { font-size: 8px; }.admin-tabs { margin-inline: -2px; }.admin-tab { padding-inline: 10px; font-size: 10px; }.admin-section { padding: 15px 12px; }.admin-section-heading { align-items: flex-start; }.admin-section-heading h3 { font-size: 15px; }.admin-section-heading p { max-width: 270px; }.admin-table-tools { align-items: stretch; flex-direction: column; }.admin-search { width: 100%; }.admin-table-tools .button { align-self: flex-end; }.admin-category-form { grid-template-columns: 1fr; padding: 12px; }.admin-category-form .button { width: 100%; }.admin-policy-badge { font-size: 0; }.admin-policy-badge i { font-size: 12px; }.admin-audit-filters { grid-template-columns: 1fr 1fr; }.admin-audit-search { grid-column: 1 / -1; }.admin-clear-filters { grid-column: 1 / -1; justify-self: end; }.admin-dialog-actions .button { flex: 1; } }
:global(html[data-theme='dark'] .admin-page){ color: #dce5f2; }
:global(html[data-theme='dark'] .admin-hero){ border-color: #27354a; background: radial-gradient(circle at 92% 0%, #243654 0, transparent 32%), linear-gradient(120deg, #141f30, #172338); }
:global(html[data-theme='dark'] .admin-hero h2), :global(html[data-theme='dark'] .admin-section-heading h3), :global(html[data-theme='dark'] .admin-metric strong), :global(html[data-theme='dark'] .admin-user-identity strong), :global(html[data-theme='dark'] .admin-dialog-user strong){ color: #e4ebf5; }
:global(html[data-theme='dark'] .admin-hero p), :global(html[data-theme='dark'] .admin-section-heading p), :global(html[data-theme='dark'] .admin-metric small), :global(html[data-theme='dark'] .admin-metric div span){ color: #98a8bd; }
:global(html[data-theme='dark'] .admin-metric), :global(html[data-theme='dark'] .admin-section){ border-color: #27354a; background: #141f30; box-shadow: none; }
:global(html[data-theme='dark'] .admin-tabs){ border-color: #27354a; background: #111b2a; }:global(html[data-theme='dark'] .admin-tab){ color: #9eacc0; }:global(html[data-theme='dark'] .admin-tab.active){ color: #a9c7ff; background: #1d2a40; box-shadow: none; }
:global(html[data-theme='dark'] .admin-section-count), :global(html[data-theme='dark'] .admin-table-wrap), :global(html[data-theme='dark'] .admin-category-row), :global(html[data-theme='dark'] .admin-permission-card), :global(html[data-theme='dark'] .admin-role-option), :global(html[data-theme='dark'] .admin-dialog-user){ border-color: #2a394f; background: #172438; }
:global(html[data-theme='dark'] .admin-search), :global(html[data-theme='dark'] .admin-edit-button), :global(html[data-theme='dark'] .admin-dialog-close){ border-color: #34445c; background: #172438; color: #bbc8d9; }:global(html[data-theme='dark'] .admin-search input){ color: #e0e8f2; }
:global(html[data-theme='dark'] .admin-user-identity small), :global(html[data-theme='dark'] .admin-dialog-user small), :global(html[data-theme='dark'] .admin-category-row small), :global(html[data-theme='dark'] .admin-account-status.pending){ color: #aab7c9; }
:global(html[data-theme='dark'] .admin-role-chip){ border-color: #34445a; background: #1b2a40; color: #c1cede; }:global(html[data-theme='dark'] .admin-role-chip.role-admin), :global(html[data-theme='dark'] .admin-role-chip.role-superadmin){ border-color: #473c68; background: #2b2440; color: #c6b4f4; }
:global(html[data-theme='dark'] .admin-category-form), :global(html[data-theme='dark'] .admin-permission-card > header){ border-color: #2a394f; background: #172438; }:global(html[data-theme='dark'] .admin-category-form label), :global(html[data-theme='dark'] .admin-permission-card h4), :global(html[data-theme='dark'] .admin-permission-option strong), :global(html[data-theme='dark'] .admin-category-row strong){ color: #cbd6e5; }
:global(html[data-theme='dark'] .admin-permission-option), :global(html[data-theme='dark'] .admin-dialog-actions){ border-color: #2a394f; }:global(html[data-theme='dark'] .admin-empty strong){ color: #c5d0df; }
:global(html[data-theme='dark'] .admin-role-dialog){ border-color: #2a394f; background: #141f30; }:global(html[data-theme='dark'] .admin-dialog-heading h3){ color: #e4ebf5; }:global(html[data-theme='dark'] .admin-role-option.selected){ border-color: #476694; background: #1a2b45; }:global(html[data-theme='dark'] .admin-role-option strong){ color: #d4deeb; }
:global(html[data-theme='dark'] .admin-time-cell){ color: #aebcd0 !important; }
:global(html[data-theme='dark'] .admin-audit-search), :global(html[data-theme='dark'] .admin-filter-field .input), :global(html[data-theme='dark'] .admin-filter-field .select){ border-color: #34445c; background: #172438; color: #dce5f2; }:global(html[data-theme='dark'] .admin-audit-search input){ color: #e0e8f2; }:global(html[data-theme='dark'] .admin-clear-filters){ background: #24334a; color: #bdcadb; }
:global(html[data-theme='dark'] .admin-alert-error){ border-color: #62413f; background: #342322; color: #f0aaa2; }:global(html[data-theme='dark'] .admin-alert-success){ border-color: #315b49; background: #1d352c; color: #9cddbc; }
:global(html[data-theme='dark'] .admin-eyebrow),
:global(html[data-theme='dark'] .admin-section-kicker){ color: #91a9d0; }
:global(html[data-theme='dark'] .admin-metric-icon.blue),
:global(html[data-theme='dark'] .admin-category-icon),
:global(html[data-theme='dark'] .admin-role-mark){ background: #1b2d48; color: #a9c7fb; }
:global(html[data-theme='dark'] .admin-metric-icon.violet),
:global(html[data-theme='dark'] .admin-role-mark.role-admin){ background: #2b2440; color: #c6b4f4; }
:global(html[data-theme='dark'] .admin-metric-icon.green),
:global(html[data-theme='dark'] .admin-role-mark.role-inventory_manager){ background: #173b32; color: #86d7b4; }
:global(html[data-theme='dark'] .admin-metric-icon.amber){ background: #3a311e; color: #e5ca83; }
:global(html[data-theme='dark'] .admin-tab:hover){ background: #1b2b41; color: #d5e2f5; }
:global(html[data-theme='dark'] .admin-tab-count){ background: #263851; color: #b7cff5; }
:global(html[data-theme='dark'] .admin-section-count){ border-color: #34445c; color: #bdcadb; }
:global(html[data-theme='dark'] .admin-user-avatar){ background: linear-gradient(145deg, #263851, #1c2d46); color: #b7cff5; }
:global(html[data-theme='dark'] .admin-account-status.verified){ background: #173b32; color: #86d7b4; }
:global(html[data-theme='dark'] .admin-account-status.pending){ background: #3a311e; color: #e5ca83; }
:global(html[data-theme='dark'] .admin-role-chip.role-inventory_manager){ border-color: #285343; background: #173b32; color: #86d7b4; }
:global(html[data-theme='dark'] .admin-role-chip.role-dispatcher){ border-color: #304b72; background: #1b2d48; color: #a9c7fb; }
:global(html[data-theme='dark'] .admin-edit-button:hover:not(:disabled)){ border-color: #476694; background: #1d2d46; color: #c4d8fa; }
:global(html[data-theme='dark'] .admin-edit-button:disabled){ color: #7f8da2; }
:global(html[data-theme='dark'] .admin-protection-note),
:global(html[data-theme='dark'] .admin-empty),
:global(html[data-theme='dark'] .admin-empty > i){ color: #9aa9bd; }
:global(html[data-theme='dark'] .admin-empty > .button){ color: #d6dfec; }
:global(html[data-theme='dark'] .admin-category-form small),
:global(html[data-theme='dark'] .admin-permission-card header small),
:global(html[data-theme='dark'] .admin-permission-option small){ color: #9aa9bd; }
:global(html[data-theme='dark'] .admin-policy-badge){ background: #2b2440; color: #c6b4f4; }
:global(html[data-theme='dark'] .admin-role-mark.role-dispatcher){ background: #1b2d48; color: #a9c7fb; }
:global(html[data-theme='dark'] .admin-permission-option input){ accent-color: #7da8f2; }
:global(html[data-theme='dark'] .admin-area-chip){ background: #263851; color: #bdcadb; }
:global(html[data-theme='dark'] .admin-area-chip.area-ticket){ background: #1b2d48; color: #a9c7fb; }
:global(html[data-theme='dark'] .admin-area-chip.area-inventory){ background: #173b32; color: #86d7b4; }
:global(html[data-theme='dark'] .admin-area-chip.area-admin){ background: #2b2440; color: #c6b4f4; }
:global(html[data-theme='dark'] .admin-audit-search input::placeholder){ color: #7f8da2; }
:global(html[data-theme='dark'] .admin-filter-field){ color: #aab8ca; }
:global(html[data-theme='dark'] .admin-clear-filters:hover){ background: #30425c; color: #e5edf7; }
:global(html[data-theme='dark'] .admin-dialog-icon){ background: #1b2d48; color: #a9c7fb; }
:global(html[data-theme='dark'] .admin-dialog-close:hover){ border-color: #476694; background: #1d2d46; color: #e5edf7; }
:global(html[data-theme='dark'] .admin-dialog-heading p){ color: #9aa9bd; }
:global(html[data-theme='dark'] .admin-role-option-icon){ background: #263851; color: #b8c9e0; }
:global(html[data-theme='dark'] .admin-role-option.selected .admin-role-option-icon){ background: #263851; color: #a9c7fb; }
:global(html[data-theme='dark'] .admin-role-check){ color: #728199; }
:global(html[data-theme='dark'] .admin-role-option.selected .admin-role-check){ color: #8fb4f4; }
:global(html[data-theme='dark'] .admin-dialog-warning){ color: #e5ca83; }
:global(html[data-theme='dark'] .admin-category-row .admin-account-status){ border: 0; }
</style>
