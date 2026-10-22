import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { ref } from 'vue'
import { safeRedirect } from '../src/utils/forms.js'
import { buildThemePreset } from '../src/config/appearance.js'
import { evaluateModule, settle } from './helpers/component-harness.mjs'

async function mountMain(context, path, meta) {
  const oldWindow = globalThis.window
  globalThis.window = new EventTarget()
  context.after(() => {
    if (oldWindow === undefined) delete globalThis.window
    else globalThis.window = oldWindow
  })
  const auth = {
    clears: 0,
    clearSession() {
      this.clears++
    },
  }
  const applicant = {
    clears: 0,
    clearSession() {
      this.clears++
    },
  }
  const redirects = []
  const router = {
    currentRoute: ref({ path, fullPath: `${path}?record=7`, meta }),
    replace: async (value) => redirects.push(value),
  }
  const app = { use() {}, mount() {} }
  evaluateModule(await readFile(new URL('../src/main.js', import.meta.url), 'utf8'), {
    vue: { createApp: () => app },
    pinia: { createPinia: () => ({}) },
    'primevue/config': { default: {} },
    'primevue/toastservice': { default: {} },
    './config/appearance': { buildThemePreset },
    './App.vue': { default: {} },
    './router': { default: router },
    './stores/auth': { useAuthStore: () => auth },
    './stores/applicant': { useApplicantStore: () => applicant },
    './stores/ui': { useUiStore: () => ({ initializeTheme() {} }) },
    './utils/forms': { safeRedirect },
  })
  return { auth, applicant, redirects }
}

test('applicant expiration clears only applicant state and redirects only the applicant portal', async (context) => {
  const main = await mountMain(context, '/applicant/exams/3', { applicant: true })
  window.dispatchEvent(new Event('hris:applicant-session-expired'))
  await settle()
  assert.equal(main.applicant.clears, 1)
  assert.equal(main.auth.clears, 0)
  assert.deepEqual(main.redirects, [
    { path: '/applicant/login', query: { redirect: '/applicant/exams/3?record=7', expired: '1' } },
  ])
})

test('applicant expiration while reviewing recruitment preserves the employee route and employee store', async (context) => {
  const main = await mountMain(context, '/recruitment', {})
  window.dispatchEvent(new Event('hris:applicant-session-expired'))
  await settle()
  assert.equal(main.applicant.clears, 1)
  assert.equal(main.auth.clears, 0)
  assert.deepEqual(main.redirects, [])
})

test('employee expiration while visiting the applicant portal does not redirect or clear the applicant', async (context) => {
  const main = await mountMain(context, '/applicant/applications', { applicant: true })
  window.dispatchEvent(new Event('hris:session-expired'))
  await settle()
  assert.equal(main.auth.clears, 1)
  assert.equal(main.applicant.clears, 0)
  assert.deepEqual(main.redirects, [])
})

test('employee session expiration redirects its workspace but keeps public careers visible', async (context) => {
  const main = await mountMain(context, '/careers', { open: true })
  window.dispatchEvent(new Event('hris:session-expired'))
  await settle()
  assert.equal(main.auth.clears, 1)
  assert.deepEqual(main.redirects, [])
})

test('successful employee logout clears applicant state because the backend invalidates their shared session', async (context) => {
  const main = await mountMain(context, '/recruitment', {})
  window.dispatchEvent(new Event('hris:employee-logout'))
  await settle()
  assert.equal(main.applicant.clears, 1)
  assert.equal(main.auth.clears, 0)
})
