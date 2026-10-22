import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { babelParse, compileScript, parse } from '@vue/compiler-sfc'
import * as vue from 'vue'
import { notificationLink } from '../src/utils/notifications.js'
import { navigationGroups } from '../src/config/navigation.js'
import * as forms from '../src/utils/forms.js'

const renderer = vue.createRenderer({
  createElement: () => ({}),
  createText: () => ({}),
  createComment: () => ({}),
  insert() {},
  remove() {},
  setText() {},
  setElementText() {},
  patchProp() {},
  parentNode: () => null,
  nextSibling: () => null,
})

async function mountComponent(path, inbox) {
  const auth = vue.reactive({
    authenticated: true,
    user: { name: 'Test Employee' },
    employee: { id: 7 },
    roles: ['employee'],
    permissions: ['leave.view'],
    can(permission) {
      return this.permissions.includes(permission)
    },
    canAny(permissions) {
      return permissions.some((permission) => this.can(permission))
    },
  })
  const dependencies = new Proxy(
    {
      vue,
      'vue-router': {
        useRoute: () => vue.reactive({ meta: {}, fullPath: '/notifications' }),
        useRouter: () => ({ replace() {} }),
      },
      '@/stores/auth': { useAuthStore: () => auth },
      '@/stores/notifications': { useNotificationsStore: () => inbox },
      '@/stores/ui': { useUiStore: () => vue.reactive({ dark: false, toggleTheme() {} }) },
      '@/config/navigation': { navigationGroups },
      '@/utils/notifications': { notificationLink },
      '@/utils/forms': forms,
      '@/api/http': { apiError: () => ({ message: 'Failed' }) },
      '@/realtime/echo': { connectRealtime() {}, disconnectRealtime() {} },
    },
    { get: (target, key) => target[key] || { default: {} } },
  )
  const { descriptor } = parse(await readFile(new URL(`../src/${path}`, import.meta.url), 'utf8'))
  const compiled = compileScript(descriptor, { id: path })
  const edits = []
  for (const node of babelParse(compiled.content, { sourceType: 'module' }).program.body) {
    if (node.type === 'ImportDeclaration')
      edits.push({
        start: node.start,
        end: node.end,
        replacement: node.specifiers
          .map(
            (specifier) =>
              `const ${specifier.local.name} = dependencies[${JSON.stringify(node.source.value)}][${JSON.stringify(specifier.type === 'ImportDefaultSpecifier' ? 'default' : specifier.imported.name)}];`,
          )
          .join('\n'),
      })
    else if (node.type === 'ExportDefaultDeclaration')
      edits.push({ start: node.start, end: node.declaration.start, replacement: 'return ' })
  }
  let source = compiled.content
  for (const edit of edits.sort((left, right) => right.start - left.start))
    source = source.slice(0, edit.start) + edit.replacement + source.slice(edit.end)
  const component = new Function('dependencies', source)(dependencies)
  let state
  const app = renderer.createApp({
    setup() {
      state = component.setup({}, { expose() {} })
      return () => null
    },
  })
  app.mount({})
  await vue.nextTick()
  return { app, state, auth }
}

test('actual inbox loads on mount, cancels on departure and recomputes permission-checked links', async (context) => {
  const calls = []
  const inbox = { load: () => calls.push('load'), cancelList: () => calls.push('cancel') }
  const view = await mountComponent('views/NotificationsView.vue', inbox)
  context.after(() => view.app.unmount())
  assert.deepEqual(calls, ['load'])
  const item = { source: { module: 'leave', id: 81, scope: 'mine' } }
  assert.deepEqual(view.state.recordLink(item), {
    path: '/my/requests/leave',
    query: { record: '81' },
  })
  view.auth.permissions = []
  assert.equal(view.state.recordLink(item), null)
  view.app.unmount()
  assert.deepEqual(calls, ['load', 'cancel'])
})

test('actual layout owns polling lifecycle and announces exact unread or unavailable count', async (context) => {
  const previousDocument = globalThis.document
  globalThis.document = { documentElement: { classList: { contains: () => false } } }
  context.after(() => {
    if (previousDocument === undefined) delete globalThis.document
    else globalThis.document = previousDocument
  })
  const calls = []
  const inbox = vue.reactive({
    unreadCount: null,
    countError: '',
    startPolling: () => calls.push('start'),
    stopPolling: () => calls.push('stop'),
  })
  const view = await mountComponent('layout/AppLayout.vue', inbox)
  context.after(() => view.app.unmount())
  assert.deepEqual(calls, ['start'])
  assert.equal(view.state.notificationLabel.value, 'Open notifications')
  inbox.unreadCount = 123
  assert.equal(view.state.notificationLabel.value, 'Open notifications, 123 unread')
  inbox.countError = 'Unavailable'
  assert.equal(view.state.notificationLabel.value, 'Open notifications, unread count unavailable')
  view.app.unmount()
  assert.deepEqual(calls, ['start', 'stop'])
})
