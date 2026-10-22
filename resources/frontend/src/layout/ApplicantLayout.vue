<script setup>
import { onBeforeUnmount, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import Button from 'primevue/button'
import Message from 'primevue/message'
import { useApplicantStore } from '@/stores/applicant'
import { useUiStore } from '@/stores/ui'
import { apiError } from '@/api/http'
import { connectAccountRealtime } from '@/realtime/account'
const applicant = useApplicantStore()
const ui = useUiStore()
const router = useRouter()
const busy = ref(false)
const error = ref('')
let connection
watch(
  () => (applicant.authenticated ? applicant.user?.id : null),
  (account) => {
    connection?.close()
    connection = undefined
    if (!account) return
    const current = () => applicant.authenticated && applicant.user?.id === account
    connection = connectAccountRealtime({
      scope: 'applicant',
      account,
      isCurrent: current,
      onSessionChanged: () => applicant.revalidateSession(),
      onReady: () => applicant.revalidateSession(),
    })
  },
  { immediate: true, flush: 'sync' },
)
function revalidateVisibleSession() {
  if (globalThis.document?.visibilityState === 'visible') applicant.revalidateSession()
}
globalThis.document?.addEventListener?.('visibilitychange', revalidateVisibleSession)
onBeforeUnmount(() => {
  connection?.close()
  globalThis.document?.removeEventListener?.('visibilitychange', revalidateVisibleSession)
})
async function signOut() {
  busy.value = true
  error.value = ''
  try {
    await applicant.signOut()
    await router.replace('/applicant/login')
  } catch (failure) {
    error.value = apiError(failure).message
  } finally {
    busy.value = false
  }
}
</script>
<template>
  <div class="applicant-shell">
    <header class="applicant-header">
      <RouterLink class="applicant-brand" to="/careers"
        ><img src="/hris-icon.png" alt="" width="44" height="44" /><span
          ><b>HRIS Careers</b><small>Applicant portal</small></span
        ></RouterLink
      >
      <nav class="flex flex-wrap items-center gap-3" aria-label="Applicant navigation">
        <RouterLink to="/careers">Careers</RouterLink>
        <RouterLink v-if="applicant.authenticated" to="/applicant/applications"
          >My applications</RouterLink
        >
        <RouterLink v-else to="/applicant/login">Applicant sign in</RouterLink>
        <RouterLink v-if="applicant.authenticated" to="/applicant/settings">Settings</RouterLink>
        <Button
          :icon="ui.dark ? 'pi pi-sun' : 'pi pi-moon'"
          text
          rounded
          :aria-label="ui.dark ? 'Use light theme' : 'Use dark theme'"
          @click="ui.toggleTheme"
        />
        <Button
          icon="pi pi-palette"
          text
          rounded
          aria-label="Appearance settings"
          :aria-expanded="ui.settingsOpen"
          @click="ui.openSettings"
        />
        <Button
          v-if="applicant.authenticated"
          label="Sign out"
          icon="pi pi-sign-out"
          text
          :loading="busy"
          @click="signOut"
        />
      </nav>
    </header>
    <main class="applicant-content">
      <Message v-if="error" severity="error" :closable="false">{{ error }}</Message>
      <RouterView />
    </main>
    <footer class="applicant-footer">
      <span>HRIS · Your next opportunity</span
      ><RouterLink to="/auth/login">Employee sign in</RouterLink>
    </footer>
  </div>
</template>
<style scoped>
.applicant-shell {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}
.applicant-header {
  background: var(--panel);
  border-bottom: 1px solid var(--line);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  padding: 18px max(5vw, 20px);
  flex-wrap: wrap;
}
.applicant-brand {
  display: flex;
  align-items: center;
  gap: 12px;
}
.applicant-brand b,
.applicant-brand small {
  display: block;
}
.applicant-brand b {
  font-size: 20px;
}
.applicant-brand small {
  color: var(--muted);
  margin-top: 3px;
}
.applicant-content {
  width: min(1100px, 100%);
  padding: 36px 24px;
  margin: 0 auto;
  flex: 1;
}
.applicant-footer {
  padding: 20px max(5vw, 20px);
  color: var(--muted);
  border-top: 1px solid var(--line);
  display: flex;
  gap: 20px;
  justify-content: space-between;
  flex-wrap: wrap;
  font-size: 12px;
}
</style>
