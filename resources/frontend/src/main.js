import { createApp } from 'vue'
import { createPinia } from 'pinia'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import 'primeicons/primeicons.css'
import './assets/main.css'
import App from './App.vue'
import router from './router'
import { useAuthStore } from './stores/auth'
import { useApplicantStore } from './stores/applicant'
import { useUiStore } from './stores/ui'
import { buildThemePreset } from './config/appearance'
import { safeRedirect } from './utils/forms'

const app = createApp(App)
const pinia = createPinia()
app.use(pinia)
const ui = useUiStore(pinia)
ui.initializeTheme()
app.use(PrimeVue, {
  theme: { preset: buildThemePreset(ui), options: { darkModeSelector: '.hris-dark' } },
  ripple: true,
})
app.use(ToastService)
app.use(router)
const auth = useAuthStore(pinia)
const applicant = useApplicantStore(pinia)
window.addEventListener('hris:session-expired', () => {
  auth.clearSession()
  if (
    !router.currentRoute.value.meta.applicant &&
    !router.currentRoute.value.meta.open &&
    router.currentRoute.value.path !== '/auth/login'
  ) {
    router.replace({
      path: '/auth/login',
      query: { redirect: safeRedirect(router.currentRoute.value.fullPath), expired: '1' },
    })
  }
})
window.addEventListener('hris:applicant-session-expired', () => {
  applicant.clearSession()
  if (
    router.currentRoute.value.meta.applicant &&
    router.currentRoute.value.path !== '/applicant/login'
  ) {
    router.replace({
      path: '/applicant/login',
      query: { redirect: router.currentRoute.value.fullPath, expired: '1' },
    })
  }
})
window.addEventListener('hris:employee-logout', () => applicant.clearSession())
app.mount('#app')
