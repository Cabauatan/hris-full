import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { compileScript, parse } from '@vue/compiler-sfc'
import * as vue from 'vue'
import * as calendar from '../src/utils/calendar.js'
import * as forms from '../src/utils/forms.js'
import * as workspace from '../src/utils/workspace.js'
import * as records from '../src/utils/records.js'
import { canViewCalendar } from '../src/config/workspace.js'
import { evaluateModule, mountComponent, response, settle } from './helpers/component-harness.mjs'

function nodes(node) {
  return [node, ...(node.children || []).flatMap(nodes)]
}

function text(node) {
  return nodes(node)
    .map((item) => item.text || '')
    .join(' ')
}

test('real calendar template displays birthday age in grid, agenda, day list, and event dialog', async (context) => {
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
    nextSibling(node) {
      return node.parent?.children[node.parent.children.indexOf(node) + 1] || null
    },
  })
  const stub = vue.defineComponent({
    inheritAttrs: false,
    setup:
      (props, { attrs, slots }) =>
      () =>
        vue.h('span', attrs, slots.default?.()),
  })
  const dialog = vue.defineComponent({
    props: ['visible', 'header'],
    setup:
      (props, { slots }) =>
      () =>
        props.visible ? vue.h('section', slots.default?.()) : null,
  })
  const birthday = {
    id: 'birthday:7:2026',
    type: 'birthday',
    title: 'Birthday · Test Employee',
    start: '2026-10-08',
    end: '2026-10-09',
    all_day: true,
    employee: { id: 7, name: 'Test Employee', employee_number: 'EMP-0007' },
    source: { module: 'employees', id: 7 },
    details: { age: 30 },
  }
  const metadata = {
    today: '2026-10-08',
    scope: 'mine',
    available_scopes: ['mine'],
    allowed_types: ['birthday'],
    range: { start: '2026-10-01', end: '2026-10-31', timezone: 'Asia/Manila' },
    events: [birthday],
    meta: {},
  }
  const calls = []
  const http = {
    get: async (path, options) => {
      calls.push({ path, options })
      return response(metadata)
    },
  }
  const { descriptor } = parse(
    await readFile(new URL('../src/views/CalendarView.vue', import.meta.url), 'utf8'),
  )
  const component = evaluateModule(
    compileScript(descriptor, { id: 'CalendarView', inlineTemplate: true }).content,
    new Proxy(
      {
        vue,
        'vue-router': { useRoute: () => ({ query: {} }) },
        '@/api/http': { default: http, apiError: () => ({ message: 'Failed' }) },
        '@/stores/auth': {
          useAuthStore: () => ({
            authenticated: true,
            employee: { id: 7 },
            can: () => false,
            canAny: () => false,
          }),
        },
        '@/utils/calendar': calendar,
        '@/utils/forms': forms,
        '@/utils/workspace': workspace,
        '@/utils/records': records,
        'primevue/dialog': { default: dialog },
      },
      { get: (target, key) => target[key] || { default: stub } },
    ),
  )
  const app = renderer.createApp(component)
  app.component('RouterLink', stub)
  const root = { children: [] }
  app.mount(root)
  context.after(() => app.unmount())
  await settle()
  assert.equal(calls.length, 2)
  assert.deepEqual(calls[1].options.params.types, ['birthday'])
  assert.ok(text(root).includes('Birthdays'))
  const titles = nodes(root).filter((node) => node.text === 'Birthday · Test Employee · Turns 30')
  assert.equal(titles.length, 3)
  const chip = nodes(root).find(
    (node) => node.tag === 'button' && String(node.props.class).includes('calendar-event-chip'),
  )
  assert.ok(chip.props.title.includes('Turns 30'))
  chip.props.onClick()
  await settle()
  assert.ok(text(root).includes('Age on birthday'))
  assert.ok(text(root).includes('30 years old'))
})

test('dashboard calendar action responds to linked profile and active session changes', async (context) => {
  const auth = vue.reactive({ authenticated: true, employee: { id: 7 }, canAny: () => false })
  const view = await mountComponent('views/DashboardView.vue', {
    '@/stores/auth': { useAuthStore: () => auth },
    '@/config/workspace': { canViewCalendar },
    '@/api/http': {
      default: {
        get: async () =>
          response({ available_scopes: [], range: {}, requests: [], approvals: { by_type: [] } }),
      },
      apiError: () => ({ message: 'Failed' }),
    },
    '@/utils/forms': forms,
    '@/utils/workspace': workspace,
  })
  context.after(() => view.app.unmount())
  assert.equal(view.state.canCalendar.value, true)
  auth.employee = null
  assert.equal(view.state.canCalendar.value, false)
  auth.employee = { id: 7 }
  auth.authenticated = false
  assert.equal(view.state.canCalendar.value, false)
})

test('birthday notification calendar links select authorized coworker birthdays and react to route changes', async (context) => {
  const route = vue.reactive({ query: { scope: 'visible', types: 'birthday' } })
  const calls = []
  const view = await mountComponent('views/CalendarView.vue', {
    'vue-router': { useRoute: () => route },
    '@/stores/auth': { useAuthStore: () => ({ employee: { id: 7 } }) },
    '@/utils/forms': forms,
    '@/utils/calendar': calendar,
    '@/utils/workspace': workspace,
    '@/api/http': {
      default: {
        get: async (path, options) => {
          calls.push({ path, params: options.params })
          return response({
            today: '2026-10-17',
            scope: options.params.scope || 'mine',
            available_scopes: ['mine', 'visible'],
            allowed_types: ['birthday', 'holiday'],
            events: [],
          })
        },
      },
      apiError: () => ({ message: 'Failed' }),
    },
  })
  context.after(() => view.app.unmount())
  assert.equal(calls.length, 2)
  assert.deepEqual(calls[0].params, { scope: 'visible', types: ['birthday'] })
  assert.equal(calls[1].params.scope, 'visible')
  assert.deepEqual(calls[1].params.types, ['birthday'])
  assert.deepEqual(view.state.selectedTypes.value, ['birthday'])

  view.state.customRange.value = true
  view.state.appliedRange.value = { start: '2026-09-01', end: '2026-09-05' }
  view.state.mode.value = 'agenda'
  route.query = { scope: 'everyone', types: 'employees' }
  await settle()
  assert.deepEqual(calls[2].params, {})
  assert.equal(calls[3].params.scope, 'mine')
  assert.deepEqual(view.state.selectedTypes.value, ['birthday', 'holiday'])
  assert.equal(view.state.customRange.value, false)
  assert.equal(view.state.mode.value, 'month')

  route.query = { scope: 'visible', types: 'birthday' }
  await settle()
  assert.equal(calls[5].params.scope, 'visible')
  assert.deepEqual(calls[5].params.types, ['birthday'])
})
