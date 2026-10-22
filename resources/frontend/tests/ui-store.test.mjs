import test, { beforeEach, afterEach } from 'node:test'
import assert from 'node:assert/strict'
import { createPinia, setActivePinia, storeToRefs } from 'pinia'
import { reactive } from 'vue'
import { useUiStore } from '../src/stores/ui.js'
import { mountComponent, settle } from './helpers/component-harness.mjs'

let previousDocument
let previousStorage
let classes
let persisted
let writes
let ui

beforeEach(() => {
  previousDocument = globalThis.document
  previousStorage = globalThis.localStorage
  classes = new Set()
  persisted = new Map()
  writes = []
  globalThis.document = {
    documentElement: {
      classList: {
        contains: (value) => classes.has(value),
        toggle: (value, enabled) => (enabled ? classes.add(value) : classes.delete(value)),
      },
    },
  }
  globalThis.localStorage = {
    getItem: (key) => persisted.get(key) ?? null,
    setItem: (key, value) => {
      persisted.set(key, value)
      writes.push([key, value])
    },
  }
  setActivePinia(createPinia())
  ui = useUiStore()
})

afterEach(() => {
  ui.$dispose()
  setActivePinia(undefined)
  if (previousDocument === undefined) delete globalThis.document
  else globalThis.document = previousDocument
  if (previousStorage === undefined) delete globalThis.localStorage
  else globalThis.localStorage = previousStorage
})

test('shared theme initializes from the saved dark preference and applies the existing CSS class', () => {
  persisted.set('hris-theme', 'dark')

  ui.initializeTheme()

  assert.equal(ui.dark, true)
  assert.equal(classes.has('hris-dark'), true)
  assert.deepEqual(writes, [])
})

test('shared theme defaults to light when no preference was saved', () => {
  ui.initializeTheme()

  assert.equal(ui.dark, false)
  assert.equal(classes.has('hris-dark'), false)
  assert.deepEqual(writes, [])
})

test('theme consumers share Pinia state and toggles persist without reinitializing their choice', () => {
  persisted.set('hris-theme', 'dark')
  ui.initializeTheme()
  const consumer = useUiStore()
  const { dark } = storeToRefs(consumer)

  consumer.toggleTheme()

  assert.equal(consumer, ui)
  assert.equal(ui.dark, false)
  assert.equal(dark.value, false)
  assert.equal(classes.has('hris-dark'), false)
  assert.deepEqual(writes, [['hris-theme', 'light']])
  ui.toggleTheme()
  assert.equal(dark.value, true)
  assert.equal(classes.has('hris-dark'), true)
  assert.deepEqual(writes, [
    ['hris-theme', 'light'],
    ['hris-theme', 'dark'],
  ])
  persisted.set('hris-theme', 'light')
  ui.initializeTheme()
  assert.equal(dark.value, true)
})

test('navigation collapse is shared through Pinia without persisting workspace state', () => {
  const consumer = useUiStore()
  const { navCollapsed } = storeToRefs(consumer)

  consumer.toggleNavigation()

  assert.equal(ui.navCollapsed, true)
  assert.equal(navCollapsed.value, true)
  assert.deepEqual(writes, [])
  ui.toggleNavigation()
  assert.equal(navCollapsed.value, false)
  assert.deepEqual(writes, [])
})

test('unavailable storage preserves the current theme and cannot prevent subsequent toggles', () => {
  classes.add('hris-dark')
  globalThis.localStorage = {
    getItem: () => {
      throw new Error('Storage blocked')
    },
    setItem: () => {
      throw new Error('Storage blocked')
    },
  }

  assert.doesNotThrow(() => ui.initializeTheme())
  assert.equal(ui.dark, true)
  assert.doesNotThrow(() => ui.toggleTheme())
  assert.equal(ui.dark, false)
  assert.equal(classes.has('hris-dark'), false)
})

test('theme state works when browser document and storage are absent', () => {
  delete globalThis.document
  delete globalThis.localStorage

  assert.doesNotThrow(() => ui.initializeTheme())
  assert.equal(ui.dark, false)
  assert.doesNotThrow(() => ui.toggleTheme())
  assert.equal(ui.dark, true)
})

test('appearance selections survive a fresh store while open panels and navigation remain transient', () => {
  ui.initializeTheme()
  ui.setPrimaryColor('violet')
  ui.setSurfaceColor('stone')
  ui.setPreset('Nora')
  ui.setDark(true)
  ui.openSettings()
  ui.toggleNavigation()
  ui.$dispose()
  setActivePinia(createPinia())
  ui = useUiStore()

  ui.initializeTheme()

  assert.equal(ui.primaryColor, 'violet')
  assert.equal(ui.surfaceColor, 'stone')
  assert.equal(ui.preset, 'Nora')
  assert.equal(ui.dark, true)
  assert.equal(ui.settingsOpen, false)
  assert.equal(ui.navCollapsed, false)
  assert.equal(classes.has('hris-dark'), true)
})

test('invalid stored colors and presets fall back while a valid legacy dark preference remains', () => {
  persisted.set('hris-theme', 'dark')
  persisted.set(
    'hris-appearance',
    JSON.stringify({
      primaryColor: 'url(https://invalid.example)',
      surfaceColor: '__proto__',
      preset: 'Unknown',
    }),
  )

  ui.initializeTheme()

  assert.equal(ui.primaryColor, 'blue')
  assert.equal(ui.surfaceColor, 'slate')
  assert.equal(ui.preset, 'Aura')
  assert.equal(ui.dark, true)
  assert.deepEqual(writes, [])
  ui.setPrimaryColor('invalid')
  ui.setSurfaceColor('invalid')
  ui.setPreset('invalid')
  ui.setDark('false')
  assert.equal(ui.dark, true)
  assert.deepEqual(writes, [])
})

test('malformed appearance storage does not prevent initialization or future color changes', () => {
  persisted.set('hris-appearance', '{broken')
  persisted.set('hris-theme', 'dark')

  assert.doesNotThrow(() => ui.initializeTheme())
  ui.setPrimaryColor('emerald')
  ui.setSurfaceColor('zinc')
  ui.setPreset('Lara')

  assert.equal(ui.dark, true)
  assert.equal(ui.primaryColor, 'emerald')
  assert.deepEqual(JSON.parse(persisted.get('hris-appearance')), {
    primaryColor: 'emerald',
    surfaceColor: 'zinc',
    preset: 'Lara',
  })
})

test('color choices apply to custom page styles and remain usable when storage fails', () => {
  const styles = new Map()
  globalThis.document.documentElement.style = {
    setProperty: (key, value) => styles.set(key, value),
  }
  globalThis.localStorage.setItem = () => {
    throw new Error('Quota exceeded')
  }

  assert.doesNotThrow(() => ui.setPrimaryColor('amber'))
  const lightPage = styles.get('--page')
  const lightPanel = styles.get('--panel')
  ui.setSurfaceColor('stone')
  assert.notEqual(styles.get('--page'), lightPage)
  ui.setDark(true)

  assert.equal(ui.primaryColor, 'amber')
  assert.notEqual(styles.get('--panel'), lightPanel)
  assert.equal(classes.has('hris-dark'), true)
  assert.ok(styles.get('--hero-contrast'))
})

test('reset restores appearance without closing settings or altering navigation', () => {
  ui.setPrimaryColor('rose')
  ui.setSurfaceColor('neutral')
  ui.setPreset('Lara')
  ui.setDark(true)
  ui.toggleNavigation()
  ui.openSettings()

  ui.resetAppearance()

  assert.equal(ui.primaryColor, 'blue')
  assert.equal(ui.surfaceColor, 'slate')
  assert.equal(ui.preset, 'Aura')
  assert.equal(ui.dark, false)
  assert.equal(ui.settingsOpen, true)
  assert.equal(ui.navCollapsed, true)
  assert.equal(persisted.get('hris-theme'), 'light')
  ui.closeSettings()
  assert.equal(ui.settingsOpen, false)
})

test('the actual app layout consumes the shared theme store through account and route updates', async (context) => {
  persisted.set('hris-theme', 'dark')
  ui.initializeTheme()
  const account = reactive({ user: null, authenticated: false, roles: [] })
  const route = reactive({ name: 'dashboard', meta: {}, fullPath: '/dashboard' })
  const mounted = await mountComponent('layout/AppLayout.vue', {
    'vue-router': { useRoute: () => route, useRouter: () => ({}) },
    '@/stores/auth': { useAuthStore: () => account },
    '@/stores/notifications': {
      useNotificationsStore: () => ({ startPolling() {}, stopPolling() {} }),
    },
    '@/stores/ui': { useUiStore },
    '@/config/navigation': { navigationGroups: () => [] },
    '@/realtime/echo': { connectRealtime() {}, disconnectRealtime() {} },
  })
  context.after(() => mounted.app.unmount())
  assert.equal(mounted.state.ui, ui)
  assert.equal(mounted.state.ui.dark, true)

  mounted.state.ui.toggleTheme()
  account.user = { id: 99, name: 'Test account' }
  account.authenticated = true
  route.fullPath = '/reports'
  await settle()

  assert.equal(mounted.state.ui.dark, false)
  assert.equal(classes.has('hris-dark'), false)
  assert.deepEqual(writes, [['hris-theme', 'light']])
})
