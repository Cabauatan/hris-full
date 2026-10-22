import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { compileScript, parse } from '@vue/compiler-sfc'
import * as vue from 'vue'
import { apiError } from '../src/api/http.js'
import { safeRedirect } from '../src/utils/forms.js'
import {
  deferred,
  evaluateModule,
  httpFailure,
  mountComponent,
  settle,
} from './helpers/component-harness.mjs'

async function mountLogin(context, { signIn = async () => {}, redirect } = {}) {
  const calls = []
  const redirects = []
  const dependencies = {
    'vue-router': {
      useRoute: () => ({ query: redirect === undefined ? {} : { redirect } }),
      useRouter: () => ({ replace: async (path) => redirects.push(path) }),
    },
    '@/stores/auth': {
      useAuthStore: () => ({
        connectionError: '',
        signIn: async (payload) => {
          calls.push({ ...payload })
          return signIn(payload)
        },
      }),
    },
    '@/api/http': { apiError },
    '@/utils/forms': { safeRedirect },
  }
  const mounted = await mountComponent('views/auth/LoginView.vue', dependencies)
  context.after(() => mounted.app.unmount())
  return { ...mounted, calls, redirects, dependencies }
}

test('actual login sends a trimmed employee number, password and remember choice without email', async (context) => {
  const view = await mountLogin(context, { redirect: '/reports?type=attendance' })
  assert.deepEqual(view.state.form.value, { employee_number: '', password: '', remember: false })
  Object.assign(view.state.form.value, {
    employee_number: '  TEST-0001  ',
    password: ' Test-password ',
    remember: true,
  })

  await view.state.submit()

  assert.deepEqual(view.calls, [
    { employee_number: 'TEST-0001', password: ' Test-password ', remember: true },
  ])
  assert.deepEqual(view.redirects, ['/reports?type=attendance'])
  assert.equal(view.state.busy.value, false)
})

test('actual login stays busy and preserves the default remember choice until authentication finishes', async (context) => {
  const pending = deferred()
  const view = await mountLogin(context, { signIn: () => pending.promise })
  Object.assign(view.state.form.value, { employee_number: 'TEST-0002', password: 'Test-password' })

  const submitting = view.state.submit()

  assert.equal(view.state.busy.value, true)
  assert.deepEqual(view.redirects, [])
  assert.equal(view.calls[0].remember, false)
  pending.resolve()
  await submitting
  assert.equal(view.state.busy.value, false)
  assert.deepEqual(view.redirects, ['/dashboard'])
})

test('actual login retains employee-number and password validation messages without navigating', async (context) => {
  const fields = {
    employee_number: ['These credentials do not match our records.'],
    password: ['The password field is required.'],
  }
  const view = await mountLogin(context, {
    signIn: async () => {
      throw httpFailure(422, 'The given data was invalid.', fields)
    },
  })
  Object.assign(view.state.form.value, { employee_number: 'TEST-0003', password: 'Test-password' })

  await view.state.submit()

  assert.deepEqual(view.state.errors.value, fields)
  assert.equal(view.state.error.value, 'The given data was invalid.')
  assert.equal(view.state.form.value.employee_number, 'TEST-0003')
  assert.equal(view.state.busy.value, false)
  assert.deepEqual(view.redirects, [])
})

test('actual login clears old validation errors during an explicit retry', async (context) => {
  let attempts = 0
  const pending = deferred()
  const view = await mountLogin(context, {
    signIn: () => {
      attempts += 1
      if (attempts === 1)
        throw httpFailure(422, 'Invalid credentials.', {
          employee_number: ['Invalid credentials.'],
        })
      return pending.promise
    },
  })
  Object.assign(view.state.form.value, { employee_number: 'TEST-0004', password: 'Test-password' })
  await view.state.submit()
  assert.equal(view.state.errors.value.employee_number.length, 1)

  const retry = view.state.submit()

  assert.deepEqual(view.state.errors.value, {})
  assert.equal(view.state.error.value, '')
  assert.equal(view.state.busy.value, true)
  pending.resolve()
  await retry
  assert.deepEqual(view.redirects, ['/dashboard'])
})

test('employee login displays a same-browser authentication conflict and accepts a successful retry', async (context) => {
  const message = 'You are already authenticated.'
  let attempts = 0
  const view = await mountLogin(context, {
    signIn: async () => {
      if (++attempts === 1) throw httpFailure(409, message)
    },
  })
  Object.assign(view.state.form.value, { employee_number: 'TEST-0008', password: 'Test-password' })

  await view.state.submit()

  assert.equal(view.state.error.value, message)
  assert.deepEqual(view.state.errors.value, {})
  assert.deepEqual(view.redirects, [])
  assert.equal(view.state.busy.value, false)

  await view.state.submit()

  assert.equal(view.state.error.value, '')
  assert.deepEqual(view.redirects, ['/dashboard'])
})

test('actual login refuses an external redirect after accepting employee-number credentials', async (context) => {
  const view = await mountLogin(context, { redirect: '//external.example.test' })
  Object.assign(view.state.form.value, { employee_number: 'TEST-0005', password: 'Test-password' })

  await view.state.submit()

  assert.deepEqual(view.redirects, ['/dashboard'])
})

test('actual login resets busy state and exposes connectivity failures without navigating', async (context) => {
  const view = await mountLogin(context, {
    signIn: async () => {
      throw new Error('Test network failure')
    },
  })
  Object.assign(view.state.form.value, { employee_number: 'TEST-0006', password: 'Test-password' })

  await view.state.submit()

  assert.equal(view.state.error.value, 'Unable to reach the server. Please try again.')
  assert.deepEqual(view.state.errors.value, {})
  assert.equal(view.state.busy.value, false)
  assert.deepEqual(view.redirects, [])
})

test('actual login template renders a bounded employee-number input and displays its server errors', async (context) => {
  const view = await mountLogin(context, {
    signIn: async () => {
      throw httpFailure(422, 'Invalid credentials.', {
        employee_number: ['Employee number is invalid.'],
      })
    },
  })
  const root = { children: [] }
  const renderer = vue.createRenderer({
    createElement: (tag) => ({ tag, props: {}, children: [] }),
    createText: (text) => ({ text }),
    createComment: () => ({ text: '' }),
    insert(node, parent, anchor) {
      node.parent = parent
      const index = parent.children.indexOf(anchor)
      parent.children.splice(index === -1 ? parent.children.length : index, 0, node)
    },
    remove(node) {
      const index = node.parent?.children.indexOf(node)
      if (index >= 0) node.parent.children.splice(index, 1)
    },
    setText: (node, text) => {
      node.text = text
    },
    setElementText: (node, text) => {
      node.children = [{ text }]
    },
    patchProp: (node, key, old, value) => {
      node.props[key] = value
    },
    parentNode: (node) => node.parent,
    nextSibling(node) {
      return node.parent?.children[node.parent.children.indexOf(node) + 1]
    },
  })
  const wrap = {
    setup:
      (_, { slots }) =>
      () =>
        vue.h('div', {}, slots.default?.()),
  }
  const input = {
    props: ['modelValue'],
    emits: ['update:modelValue'],
    setup:
      (props, { attrs, emit }) =>
      () =>
        vue.h('input', {
          ...attrs,
          value: props.modelValue,
          onInput: (event) => emit('update:modelValue', event.target.value),
        }),
  }
  const modules = new Proxy(
    {
      vue,
      ...view.dependencies,
      '@/layout/AuthFrame.vue': { default: wrap },
      'primevue/inputtext': { default: input },
      'primevue/password': { default: input },
      'primevue/checkbox': { default: input },
      'primevue/message': { default: wrap },
      'primevue/button': { default: wrap },
    },
    { get: (target, key) => target[key] || { default: {} } },
  )
  const source = await readFile(new URL('../src/views/auth/LoginView.vue', import.meta.url), 'utf8')
  const { descriptor } = parse(source)
  const component = evaluateModule(
    compileScript(descriptor, { id: 'login', inlineTemplate: true }).content,
    modules,
  )
  const app = renderer.createApp(component)
  app.component('RouterLink', wrap)
  app.mount(root)
  context.after(() => app.unmount())
  const all = (node) => [node, ...(node.children || []).flatMap(all)]
  const field = all(root).find(
    (node) => node.tag === 'input' && node.props.id === 'login-employee-number',
  )

  assert.ok(field)
  assert.equal(field.props.type, 'text')
  assert.equal(field.props.autocomplete, 'username')
  assert.equal(Number(field.props.maxlength), 50)
  assert.equal(field.props.required, '')
  assert.equal(field.props.placeholder, 'EMP-0001')
  const label = all(root).find(
    (node) => node.tag === 'label' && node.props.for === 'login-employee-number',
  )
  assert.match(
    all(label)
      .map((node) => node.text || '')
      .join(''),
    /Employee number/,
  )
  field.props.onInput({ target: { value: 'TEST-0007' } })
  await settle()
  const form = all(root).find((node) => node.tag === 'form')
  await form.props.onSubmit({ preventDefault() {} })
  await settle()
  assert.equal(view.calls.at(-1).employee_number, 'TEST-0007')
  assert.ok(
    all(root).some(
      (node) =>
        node.tag === 'small' &&
        all(node).some((child) => child.text === 'Employee number is invalid.'),
    ),
  )
})
