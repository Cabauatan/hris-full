import test from 'node:test'
import assert from 'node:assert/strict'
import { createPinia, setActivePinia } from 'pinia'
import { reactive } from 'vue'
import * as vue from 'vue'
import { readFile } from 'node:fs/promises'
import { compileScript, parse } from '@vue/compiler-sfc'
import { useUiStore } from '../src/stores/ui.js'
import { navigationGroups } from '../src/config/navigation.js'
import { deferred, evaluateModule, mountComponent, settle } from './helpers/component-harness.mjs'

async function mountShell(context) {
  setActivePinia(createPinia())
  const ui = useUiStore()
  const account = reactive({
    user: { id: 12, name: 'Test Employee' },
    employee: null,
    roles: ['employee'],
    permissions: [],
    get authenticated() {
      return this.user !== null
    },
    can(permission) {
      return this.permissions.includes(permission)
    },
    canAny(permissions) {
      return permissions.some((permission) => this.can(permission))
    },
    async signOut() {
      this.user = null
    },
  })
  const route = reactive({
    name: 'dashboard',
    path: '/dashboard',
    fullPath: '/dashboard',
    meta: { title: 'Dashboard' },
  })
  const paths = []
  const view = await mountComponent('layout/AppLayout.vue', {
    'vue-router': {
      useRoute: () => route,
      useRouter: () => ({
        push: (path) => paths.push(path),
        replace: (path) => paths.push(path),
      }),
    },
    '@/stores/auth': { useAuthStore: () => account },
    '@/stores/notifications': {
      useNotificationsStore: () => ({ startPolling() {}, stopPolling() {} }),
    },
    '@/stores/ui': { useUiStore },
    '@/config/navigation': { navigationGroups },
    '@/realtime/echo': { connectRealtime() {}, disconnectRealtime() {} },
    '@/api/http': { apiError: () => ({ message: 'Failed' }) },
  })
  context.after(() => {
    view.app.unmount()
    ui.$dispose()
    setActivePinia(undefined)
  })
  return { view, account, route, ui, paths }
}

test('layout keeps desktop collapse in the shared store independently from the mobile drawer', async (context) => {
  const { view, ui } = await mountShell(context)

  view.state.ui.toggleNavigation()
  view.state.mobileOpen.value = true

  assert.equal(ui.navCollapsed, true)
  assert.equal(view.state.mobileOpen.value, true)
  view.state.ui.toggleNavigation()
  assert.equal(ui.navCollapsed, false)
  assert.equal(view.state.mobileOpen.value, true)
})

test('navigation search reveals only permitted local pages and responds to changed access', async (context) => {
  const { view, account, paths } = await mountShell(context)
  view.state.searchQuery.value = 'recruitment'
  assert.deepEqual(view.state.searchResults.value, [])
  account.permissions = ['recruitment.view']

  assert.deepEqual(
    view.state.searchResults.value.map(({ to }) => to),
    ['/recruitment'],
  )
  account.permissions = []
  view.state.openFirstSearchResult()

  assert.deepEqual(view.state.searchResults.value, [])
  assert.deepEqual(paths, [])
  account.user = null
  view.state.searchQuery.value = 'dashboard'
  assert.deepEqual(view.state.searchResults.value, [])
})

test('search submit opens the first matching permitted page and clears the dropdown', async (context) => {
  const { view, paths } = await mountShell(context)
  view.state.searchOpen.value = true
  view.state.searchQuery.value = '  DaShBoArD  '

  view.state.openFirstSearchResult()

  assert.deepEqual(paths, ['/dashboard'])
  assert.equal(view.state.searchOpen.value, false)
  assert.equal(view.state.searchQuery.value, '')
})

test('route and account changes clear stale search results and route changes close mobile navigation', async (context) => {
  const { view, account, route } = await mountShell(context)
  view.state.searchQuery.value = 'dashboard'
  view.state.searchOpen.value = true
  view.state.mobileOpen.value = true

  route.fullPath = '/notifications'
  await settle()

  assert.equal(view.state.searchQuery.value, '')
  assert.equal(view.state.searchOpen.value, false)
  assert.equal(view.state.mobileOpen.value, false)
  view.state.searchQuery.value = 'home'
  view.state.searchOpen.value = true
  account.user = { id: 99, name: 'Another employee' }
  assert.equal(view.state.searchQuery.value, '')
  assert.equal(view.state.searchOpen.value, false)
})

test('outside clicks close search while clicks within the search area preserve its results', async (context) => {
  const { view } = await mountShell(context)
  const inside = {}
  view.state.searchArea.value = { contains: (target) => target === inside }
  view.state.searchQuery.value = 'home'
  view.state.searchOpen.value = true

  view.state.closeOutsideSearch({ target: inside })

  assert.equal(view.state.searchOpen.value, true)
  view.state.closeOutsideSearch({ target: {} })
  assert.equal(view.state.searchOpen.value, false)
  assert.equal(view.state.searchQuery.value, '')
})

test('the real layout remounts its workspace on account changes and logout so private labels cannot survive', async (context) => {
  setActivePinia(createPinia())
  const ui = useUiStore()
  const account = reactive({
    user: { id: 12, name: 'First Employee' },
    employee: null,
    roles: ['employee'],
    get authenticated() {
      return this.user !== null
    },
    can: () => false,
    canAny: () => false,
  })
  const route = reactive({
    name: 'dashboard',
    path: '/dashboard',
    fullPath: '/dashboard',
    meta: { title: 'Dashboard' },
  })
  const pending = deferred()
  const mounted = []
  const unmounted = []
  const child = vue.defineComponent({
    setup() {
      const identity = account.user?.id ?? 'guest'
      const label = vue.ref(
        identity === 'guest' ? 'No employee details' : `Private employee label ${identity}`,
      )
      let active = true
      mounted.push(identity)
      if (identity === 12)
        pending.promise.then(() => {
          if (active) label.value = 'Late private response from first employee'
        })
      vue.onBeforeUnmount(() => {
        active = false
        unmounted.push(identity)
      })
      return () => vue.h('article', { 'data-workspace': true }, label.value)
    },
  })
  const wrapper = vue.defineComponent({
    inheritAttrs: false,
    setup:
      (props, { slots }) =>
      () =>
        vue.h('span', slots.default?.()),
  })
  const renderer = vue.createRenderer({
    createElement: (tag) => ({ tag, props: {}, children: [] }),
    createText: (value) => ({ text: value }),
    createComment: () => ({}),
    insert(node, parent, anchor) {
      if (node.parent) {
        const index = node.parent.children.indexOf(node)
        if (index >= 0) node.parent.children.splice(index, 1)
      }
      node.parent = parent
      const index = anchor ? parent.children.indexOf(anchor) : -1
      if (index >= 0) parent.children.splice(index, 0, node)
      else parent.children.push(node)
    },
    remove(node) {
      const index = node.parent?.children.indexOf(node)
      if (index >= 0) node.parent.children.splice(index, 1)
    },
    setText: (node, value) => {
      node.text = value
    },
    setElementText(node, value) {
      node.text = value
      node.children = []
    },
    patchProp: (node, key, previous, value) => {
      node.props[key] = value
    },
    parentNode: (node) => node.parent,
    nextSibling: (node) => node.parent?.children[node.parent.children.indexOf(node) + 1] || null,
  })
  const { descriptor } = parse(
    await readFile(new URL('../src/layout/AppLayout.vue', import.meta.url), 'utf8'),
  )
  const component = evaluateModule(
    compileScript(descriptor, { id: 'AppLayout', inlineTemplate: true }).content,
    {
      vue: { ...vue, vModelText: {} },
      'vue-router': { useRoute: () => route, useRouter: () => ({ push() {}, replace() {} }) },
      'primevue/button': { default: wrapper },
      'primevue/message': { default: wrapper },
      '@/stores/auth': { useAuthStore: () => account },
      '@/stores/notifications': {
        useNotificationsStore: () => ({ unreadCount: 0, startPolling() {}, stopPolling() {} }),
      },
      '@/stores/ui': { useUiStore },
      '@/config/navigation': { navigationGroups },
      '@/realtime/echo': { connectRealtime() {}, disconnectRealtime() {} },
      '@/api/http': { apiError: () => ({ message: 'Failed' }) },
    },
  )
  const app = renderer.createApp(component)
  app.component('RouterView', child)
  app.component('RouterLink', wrapper)
  const root = { children: [] }
  app.mount(root)
  context.after(() => {
    app.unmount()
    ui.$dispose()
    setActivePinia(undefined)
  })
  const contents = (node = root) =>
    [node.text || '', ...(node.children || []).map(contents)].join(' ')
  await settle()
  assert.ok(contents().includes('Private employee label 12'))
  assert.deepEqual(mounted, [12])

  account.user = { id: 49, name: 'Second Employee' }
  await settle()
  assert.equal(route.path, '/dashboard')
  assert.deepEqual(mounted, [12, 49])
  assert.deepEqual(unmounted, [12])
  assert.ok(contents().includes('Private employee label 49'))
  assert.ok(!contents().includes('Private employee label 12'))
  pending.resolve()
  await settle()
  assert.ok(!contents().includes('Late private response from first employee'))

  account.user = { id: 49, name: 'Updated Same Employee' }
  await settle()
  assert.deepEqual(mounted, [12, 49])
  account.user = null
  await settle()
  assert.deepEqual(mounted, [12, 49, 'guest'])
  assert.deepEqual(unmounted, [12, 49])
  assert.ok(contents().includes('No employee details'))
  assert.ok(!contents().includes('Private employee label'))
})
