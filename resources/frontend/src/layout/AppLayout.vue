<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from 'primevue/button'
import Message from 'primevue/message'
import { useAuthStore } from '@/stores/auth'
import { useNotificationsStore } from '@/stores/notifications'
import { useUiStore } from '@/stores/ui'
import { apiError } from '@/api/http'
import { navigationGroups } from '@/config/navigation'
import { connectRealtime, disconnectRealtime } from '@/realtime/echo'
const auth = useAuthStore()
const inbox = useNotificationsStore()
const ui = useUiStore()
const route = useRoute()
const router = useRouter()
const mobileOpen = ref(false)
const searchQuery = ref('')
const searchOpen = ref(false)
const searchArea = ref(null)
const signingOut = ref(false)
const error = ref('')
const groups = computed(() => navigationGroups(auth))
const searchResults = computed(() => {
  const query = searchQuery.value.trim().toLocaleLowerCase()
  if (!query || !auth.authenticated) return []
  return groups.value
    .flatMap((group) => group.links.map((link) => ({ ...link, group: group.title })))
    .filter((link) => `${link.label} ${link.group}`.toLocaleLowerCase().includes(query))
    .slice(0, 8)
})
const title = computed(() => route.meta.title || 'Workspace')
const workspaceKey = computed(() => `${route.path}:${auth.authenticated ? auth.user?.id : 'guest'}`)
const notificationLabel = computed(() =>
  inbox.countError
    ? 'Open notifications, unread count unavailable'
    : inbox.unreadCount === null
      ? 'Open notifications'
      : `Open notifications, ${inbox.unreadCount} unread`,
)
onMounted(() => {
  inbox.startPolling()
  globalThis.document?.addEventListener?.('pointerdown', closeOutsideSearch)
  globalThis.document?.addEventListener?.('visibilitychange', revalidateVisibleSession)
})
onBeforeUnmount(() => {
  inbox.stopPolling()
  disconnectRealtime()
  globalThis.document?.removeEventListener?.('pointerdown', closeOutsideSearch)
  globalThis.document?.removeEventListener?.('visibilitychange', revalidateVisibleSession)
})
watch(
  () => (auth.authenticated ? auth.user?.id : null),
  () => {
    closeSearch()
    disconnectRealtime()
    if (auth.authenticated)
      connectRealtime(() => {
        inbox.refreshUnreadCount()
        if (route.name === 'notifications') inbox.load()
      })
  },
  { immediate: true, flush: 'sync' },
)
const initials = computed(
  () =>
    auth.user?.name
      ?.split(' ')
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part[0])
      .join('')
      .toUpperCase() || 'HR',
)
const role = computed(() => auth.roles.map((item) => item.replace(/_/g, ' ')).join(' · '))
watch(
  () => route.fullPath,
  () => {
    mobileOpen.value = false
    error.value = ''
    closeSearch()
  },
)
function closeSearch() {
  searchOpen.value = false
  searchQuery.value = ''
}
function closeOutsideSearch(event) {
  if (searchArea.value && !searchArea.value.contains(event.target)) closeSearch()
}
function revalidateVisibleSession() {
  if (globalThis.document?.visibilityState === 'visible') auth.revalidateSession()
}
function openFirstSearchResult() {
  const result = searchResults.value[0]
  if (!result) return
  closeSearch()
  return router.push(result.to)
}
async function signOut() {
  signingOut.value = true
  try {
    await auth.signOut()
    await router.replace('/auth/login')
  } catch (failure) {
    error.value = apiError(failure).message
  } finally {
    signingOut.value = false
  }
}
</script>

<template>
  <div
    class="app-shell"
    :class="{ 'nav-collapsed': ui.navCollapsed }"
    @keydown.esc="mobileOpen = false"
  >
    <a class="skip-link" href="#main-content">Skip to content</a>
    <button
      v-if="mobileOpen"
      class="mobile-nav-backdrop"
      aria-label="Close navigation"
      @click="mobileOpen = false"
    />
    <aside id="workspace-navigation" class="sidebar" :class="{ 'mobile-open': mobileOpen }">
      <RouterLink class="brand" to="/home" aria-label="HRIS home"
        ><img class="brand-mark" src="/hris-icon.png" alt="" width="48" height="48" /><span
          class="brand-copy"
          ><b>HRIS</b><small>People & workplace</small></span
        ></RouterLink
      >
      <nav class="sidebar-navigation" aria-label="Main navigation">
        <section v-for="group in groups" :key="group.title" class="nav-group">
          <p class="nav-caption">{{ group.title }}</p>
          <RouterLink
            v-for="link in group.links"
            :key="link.to"
            :to="link.to"
            class="nav-link"
            active-class="active"
            :title="link.label"
            :aria-label="link.label"
            ><i :class="link.icon" aria-hidden="true" /><span class="nav-link-label">{{
              link.label
            }}</span></RouterLink
          >
        </section>
      </nav>
      <div class="sidebar-bottom">
        <RouterLink
          class="sidebar-user"
          to="/profile"
          :title="auth.user?.name"
          :aria-label="`Profile for ${auth.user?.name}`"
          ><span class="avatar">{{ initials }}</span
          ><span class="sidebar-user-copy"
            ><b>{{ auth.user?.name }}</b
            ><small>{{ role || 'Employee workspace' }}</small></span
          ><i class="pi pi-angle-right" /></RouterLink
        ><button
          class="nav-link signout"
          :disabled="signingOut"
          title="Sign out"
          aria-label="Sign out"
          @click="signOut"
        >
          <i class="pi pi-sign-out" aria-hidden="true" /><span class="nav-link-label">{{
            signingOut ? 'Signing out…' : 'Sign out'
          }}</span>
        </button>
      </div>
    </aside>
    <div class="workspace">
      <header class="topbar">
        <div class="topbar-title">
          <Button
            class="desktop-menu-button nav-toggle-button"
            icon="pi pi-bars"
            text
            rounded
            :aria-label="ui.navCollapsed ? 'Expand navigation' : 'Collapse navigation'"
            aria-controls="workspace-navigation"
            :aria-expanded="!ui.navCollapsed"
            @click="ui.toggleNavigation"
          />
          <Button
            class="mobile-menu-button"
            icon="pi pi-bars"
            text
            aria-label="Open navigation"
            aria-controls="workspace-navigation"
            :aria-expanded="mobileOpen"
            @click="mobileOpen = !mobileOpen"
          /><span class="topbar-heading">{{ title }}</span>
        </div>
        <div class="top-actions">
          <form
            ref="searchArea"
            class="navigation-search"
            role="search"
            aria-label="Search workspace pages"
            @submit.prevent="openFirstSearchResult"
            @keydown.esc="closeSearch"
          >
            <i class="pi pi-search" aria-hidden="true" />
            <label class="sr-only" for="workspace-search">Search pages</label>
            <input
              id="workspace-search"
              v-model="searchQuery"
              class="navigation-search-input"
              type="search"
              placeholder="Search pages"
              autocomplete="off"
              aria-controls="workspace-search-results"
              @focus="searchOpen = true"
              @input="searchOpen = true"
            />
            <div
              v-if="searchOpen && searchQuery.trim()"
              id="workspace-search-results"
              class="navigation-search-results"
              aria-label="Search results"
            >
              <RouterLink
                v-for="result in searchResults"
                :key="result.to"
                class="navigation-search-result"
                :to="result.to"
                @click="closeSearch"
                ><i :class="result.icon" aria-hidden="true" /><span
                  ><b>{{ result.label }}</b
                  ><small>{{ result.group }}</small></span
                ></RouterLink
              >
              <p v-if="!searchResults.length" class="navigation-search-empty" role="status">
                No matching pages.
              </p>
            </div>
          </form>
          <RouterLink
            to="/notifications"
            class="notification-bell"
            :aria-label="notificationLabel"
            :title="
              inbox.countError
                ? 'Notification count unavailable. Open your inbox to retry.'
                : notificationLabel
            "
          >
            <i class="pi pi-bell" aria-hidden="true" />
            <span v-if="inbox.countError" class="notification-count-error" aria-hidden="true"
              >!</span
            >
            <span v-else-if="inbox.unreadCount > 0" class="notification-count" aria-hidden="true">{{
              inbox.unreadCount > 99 ? '99+' : inbox.unreadCount
            }}</span>
          </RouterLink>
          <Button
            :icon="ui.dark ? 'pi pi-sun' : 'pi pi-moon'"
            text
            rounded
            :aria-label="ui.dark ? 'Use light theme' : 'Use dark theme'"
            @click="ui.toggleTheme"
          /><Button
            icon="pi pi-palette"
            text
            rounded
            aria-label="Appearance settings"
            :aria-expanded="ui.settingsOpen"
            @click="ui.openSettings"
          /><RouterLink
            to="/profile"
            class="avatar top-avatar"
            :aria-label="`Profile for ${auth.user?.name}`"
            >{{ initials }}</RouterLink
          >
        </div>
      </header>
      <nav class="workspace-breadcrumb" aria-label="Breadcrumb">
        <RouterLink to="/dashboard" aria-label="Dashboard"
          ><i class="pi pi-home" aria-hidden="true"
        /></RouterLink>
        <i class="pi pi-angle-right" aria-hidden="true" />
        <span aria-current="page">{{ title }}</span>
      </nav>
      <main id="main-content" class="page-content" tabindex="-1">
        <Message v-if="error" severity="error" :closable="false">{{ error }}</Message
        ><RouterView :key="workspaceKey" />
      </main>
      <footer class="workspace-footer">
        <span>HRIS · People & workplace</span><span>Secure session</span>
      </footer>
    </div>
  </div>
</template>

<style scoped>
.notification-bell {
  display: inline-grid;
  place-items: center;
  position: relative;
  width: 36px;
  height: 36px;
  flex-shrink: 0;
  border-radius: 50%;
  color: var(--muted);
}
.notification-bell:hover {
  background: var(--soft);
  color: var(--accent);
}
.notification-count {
  position: absolute;
  top: -1px;
  right: -6px;
  min-width: 17px;
  height: 17px;
  display: grid;
  place-items: center;
  padding: 0 4px;
  border-radius: 8px;
  background: var(--accent);
  color: var(--accent-contrast, #fff);
  font-size: 9px;
  font-weight: 750;
}
.notification-count-error {
  position: absolute;
  top: 0;
  right: 0;
  color: var(--muted);
  font-size: 10px;
  font-weight: 700;
}
</style>
