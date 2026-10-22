import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { compileScript, parse } from '@vue/compiler-sfc'
import * as vue from 'vue'
import * as recruitmentUtils from '../src/utils/recruitment.js'
import * as forms from '../src/utils/forms.js'
import { roleLabel } from '../src/utils/roleLabel.js'
import { apiError } from '../src/api/http.js'
import {
  deferred,
  evaluateModule,
  httpFailure,
  response,
  settle,
} from './helpers/component-harness.mjs'

function nodes(node) {
  return [node, ...(node.children || []).flatMap(nodes)]
}

function text(node) {
  return nodes(node)
    .map((item) => item.text || '')
    .join(' ')
}

const renderer = vue.createRenderer({
  createElement: (tag) => ({
    tag,
    tagName: tag.toUpperCase(),
    props: {},
    children: [],
    style: {},
    addEventListener() {},
    removeEventListener() {},
  }),
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

const wrapper = vue.defineComponent({
  inheritAttrs: false,
  props: ['label', 'value'],
  setup:
    (props, { attrs, slots }) =>
    () =>
      vue.h('span', attrs, slots.default?.() || props.label || props.value),
})
const button = vue.defineComponent({
  inheritAttrs: false,
  props: ['label'],
  setup:
    (props, { attrs }) =>
    () =>
      vue.h('button', { ...attrs, 'data-label': props.label }, props.label),
})
const select = vue.defineComponent({
  inheritAttrs: false,
  setup:
    (props, { attrs }) =>
    () =>
      vue.h('span', { ...attrs, role: 'combobox' }),
})

function recruitmentStore(overrides = {}) {
  return vue.reactive({
    busy: false,
    openingsBusy: false,
    canManage: true,
    selected: null,
    attempt: null,
    applications: [],
    interviews: [],
    jobOpenings: [],
    exams: [],
    error: '',
    fieldErrors: {},
    openingsError: '',
    openingErrors: {},
    pagination: { current_page: 1, total: 0 },
    openingPagination: { current_page: 1, total: 0 },
    loadJobOpenings() {},
    loadApplications() {},
    loadCatalogs() {},
    ...overrides,
  })
}

async function mountView(path, dependencies = {}) {
  const dialogs = []
  const dialog = vue.defineComponent({
    inheritAttrs: false,
    props: {
      visible: Boolean,
      header: String,
      modal: Boolean,
      showHeader: { default: true },
      closable: { default: true },
      closeOnEscape: { default: true },
      dismissableMask: Boolean,
    },
    emits: ['update:visible'],
    setup(props, { attrs, emit, slots }) {
      dialogs.push({
        props,
        attrs,
        escape: () => {
          if (props.closeOnEscape) emit('update:visible', false)
        },
        dismiss: () => {
          if (props.dismissableMask) emit('update:visible', false)
        },
        emitClose: () => emit('update:visible', false),
      })
      return () =>
        props.visible
          ? vue.h('section', { ...attrs, 'data-header': props.header }, slots.default?.())
          : null
    },
  })
  const auth = vue.reactive({
    user: { id: 9, name: 'Staff' },
    authenticated: true,
    employee: null,
    can: () => true,
  })
  const { descriptor } = parse(await readFile(new URL(`../src/${path}`, import.meta.url), 'utf8'))
  const component = evaluateModule(
    compileScript(descriptor, { id: path, inlineTemplate: true }).content,
    new Proxy(
      {
        vue,
        '@/stores/auth': { useAuthStore: () => auth },
        '@/utils/forms': forms,
        '@/utils/recruitment': recruitmentUtils,
        '@/utils/roleLabel': { roleLabel },
        'primevue/dialog': { default: dialog },
        'primevue/button': { default: button },
        'primevue/select': { default: select },
        ...dependencies,
      },
      { get: (target, key) => target[key] || { default: wrapper } },
    ),
  )
  const app = renderer.createApp(component)
  app.component('RouterLink', wrapper)
  const root = { children: [] }
  app.mount(root)
  await settle()
  return { app, root, dialogs }
}

function submit(form) {
  return form.props.onSubmit({ preventDefault() {} })
}

test('position dialog keeps its draft open during saving and labels its status combobox', async (context) => {
  const pending = deferred()
  const recruitment = recruitmentStore({
    async saveJobOpening() {
      this.openingsBusy = true
      await pending.promise
      this.openingsBusy = false
      return null
    },
  })
  const view = await mountView('components/JobOpeningsPanel.vue', {
    '@/stores/recruitment': { useRecruitmentStore: () => recruitment },
  })
  context.after(() => view.app.unmount())
  nodes(view.root)
    .find((node) => node.props?.['data-label'] === 'Post a position')
    .props.onClick()
  await settle()
  const dialog = view.dialogs[0]
  assert.ok(
    nodes(view.root).some(
      (node) => node.props?.role === 'combobox' && node.props['aria-label'] === 'Posting status',
    ),
  )
  const request = submit(
    nodes(view.root)
      .find((node) => node.tag === 'section' && node.props['data-header'])
      .children.find((node) => node.tag === 'form'),
  )
  await settle()
  assert.equal(dialog.props.closable, false)
  assert.equal(dialog.props.closeOnEscape, false)
  dialog.escape()
  assert.equal(dialog.props.visible, true)
  pending.resolve()
  await request
  await settle()
  assert.equal(dialog.props.closeOnEscape, true)
  dialog.escape()
  await settle()
  assert.equal(dialog.props.visible, false)
})

test('assessment creation and grading dialogs block busy Escape and preserve a grading attempt', async (context) => {
  const recruitment = recruitmentStore({
    attempt: { id: 4, title: 'Assessment', questions: [], answers: [], abilities: {} },
  })
  const view = await mountView('views/RecruitmentWorkspace.vue', {
    '@/stores/recruitment': { useRecruitmentStore: () => recruitment },
  })
  context.after(() => view.app.unmount())
  nodes(view.root)
    .find((node) => node.props?.['data-label'] === 'Create assessment')
    .props.onClick()
  await settle()
  const [creation, grading] = view.dialogs
  const labels = nodes(view.root)
    .filter((node) => node.props?.role === 'combobox')
    .map((node) => node.props['aria-label'])
  assert.ok(labels.includes('Question 1 type'))
  assert.ok(labels.includes('Question 1 correct option'))
  recruitment.busy = true
  await settle()
  assert.equal(creation.props.closeOnEscape, false)
  assert.equal(grading.props.closeOnEscape, false)
  creation.escape()
  grading.escape()
  grading.emitClose()
  await settle()
  assert.equal(creation.props.visible, true)
  assert.equal(recruitment.attempt.id, 4)
  recruitment.busy = false
  await settle()
  creation.escape()
  grading.escape()
  await settle()
  assert.equal(creation.props.visible, false)
  assert.equal(recruitment.attempt, null)
})

test('password dialog blocks Escape while the password request is pending and permits retry', async (context) => {
  const pending = deferred()
  const view = await mountView('views/ProfileView.vue', {
    '@/stores/auth': {
      useAuthStore: () => ({ user: {}, employee: null, changePassword: () => pending.promise }),
    },
    '@/api/http': { default: {}, apiError },
  })
  context.after(() => view.app.unmount())
  nodes(view.root)
    .find((node) => node.props?.['data-label'] === 'Change password')
    .props.onClick()
  await settle()
  const request = submit(nodes(view.root).find((node) => node.tag === 'form'))
  await settle()
  const dialog = view.dialogs[0]
  assert.equal(dialog.props.closeOnEscape, false)
  dialog.escape()
  assert.equal(dialog.props.visible, true)
  pending.reject(httpFailure(422, 'Current password is incorrect.'))
  await request
  await settle()
  assert.ok(text(view.root).includes('Current password is incorrect.'))
  assert.equal(dialog.props.visible, true)
  assert.equal(dialog.props.closeOnEscape, true)
  dialog.escape()
  await settle()
  assert.equal(dialog.props.visible, false)
})

test('role editor uses a modal with initial focus, freezes choices during save, and shows errors inside', async (context) => {
  const previousWindow = globalThis.window
  globalThis.window = new EventTarget()
  const pending = deferred()
  const user = {
    id: 12,
    name: 'Long user name '.repeat(10),
    email: `${'long'.repeat(40)}@example.test`,
    roles: ['agent'],
  }
  const view = await mountView('views/AdminView.vue', {
    '@/api/http': {
      default: {
        get: async (path) =>
          response(
            path.endsWith('/permissions')
              ? { roles: [], catalog: {} }
              : { users: [user], categories: [], audit: [] },
          ),
        patch: () => pending.promise,
      },
    },
  })
  context.after(() => {
    view.app.unmount()
    if (previousWindow === undefined) delete globalThis.window
    else globalThis.window = previousWindow
  })
  nodes(view.root)
    .find((node) => node.props?.class === 'admin-edit-button')
    .props.onClick()
  await settle()
  const dialog = view.dialogs[0]
  assert.equal(dialog.props.modal, true)
  assert.equal(dialog.attrs['aria-labelledby'], 'role-dialog-title')
  assert.ok(
    nodes(view.root).some((node) => node.tag === 'button' && node.props.autofocus !== undefined),
  )
  const request = nodes(view.root)
    .find((node) => node.tag === 'button' && text(node).includes('Save roles'))
    .props.onClick()
  await settle()
  const choices = nodes(view.root).filter(
    (node) => node.tag === 'input' && node.props.type === 'checkbox',
  )
  assert.equal(choices.length, 5)
  assert.ok(choices.every((node) => node.props.disabled))
  assert.equal(dialog.props.closeOnEscape, false)
  assert.equal(dialog.props.dismissableMask, false)
  dialog.escape()
  dialog.dismiss()
  dialog.emitClose()
  await settle()
  assert.equal(dialog.props.visible, true)
  pending.reject(httpFailure(422, 'Role changes could not be saved.'))
  await request
  await settle()
  const section = nodes(view.root).find(
    (node) => node.tag === 'section' && node.props.class === 'admin-role-dialog',
  )
  assert.ok(text(section).includes('Role changes could not be saved.'))
  assert.equal(nodes(view.root).filter((node) => node.props?.role === 'alert').length, 1)
  assert.equal(dialog.props.closeOnEscape, true)
  dialog.escape()
  await settle()
  assert.equal(dialog.props.visible, false)
})
