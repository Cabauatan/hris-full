import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { compileScript, parse } from '@vue/compiler-sfc'
import * as vue from 'vue'
import { evaluateModule, mountComponent, settle } from './helpers/component-harness.mjs'

const disconnected = () => ({
  status: { configured: true, bot_username: 'hris_test_bot', connected: false, linked_at: null },
  loading: false,
  busy: false,
  error: '',
  link: null,
  linkExpired: false,
})
function store() {
  const calls = []
  const telegram = vue.reactive({
    states: { employee: disconnected(), applicant: disconnected() },
    accounts: { employee: '12', applicant: '12' },
    identity(scope) {
      return this.accounts[scope]
    },
    load: (scope) => calls.push(['load', scope]),
    watchConnection: (scope) => {
      calls.push(['load', scope])
      return () => calls.push(['close', scope])
    },
    connect: (scope) => calls.push(['connect', scope]),
    disconnect: (scope) => calls.push(['disconnect', scope]),
  })
  return { telegram, calls }
}
function all(node) {
  return [node, ...(node.children || []).flatMap(all)]
}
function text(node) {
  return all(node)
    .map((item) => item.text || '')
    .join(' ')
    .replace(/\s+/g, ' ')
    .trim()
}
async function render(context, path, dependencies, props = {}) {
  const root = { children: [] }
  const renderer = vue.createRenderer({
    createElement: (tag) => ({ tag, props: {}, children: [] }),
    createText: (value) => ({ text: value }),
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
    setText: (node, value) => {
      node.text = value
    },
    setElementText: (node, value) => {
      node.children = [{ text: value }]
    },
    patchProp: (node, key, old, value) => {
      node.props[key] = value
    },
    parentNode: (node) => node.parent,
    nextSibling: (node) => node.parent?.children[node.parent.children.indexOf(node) + 1],
  })
  const wrapper = {
    setup:
      (_, { slots }) =>
      () =>
        vue.h('div', {}, slots.default?.()),
  }
  const button = {
    props: ['label', 'disabled', 'loading'],
    setup:
      (values, { attrs }) =>
      () =>
        vue.h('button', { ...attrs, disabled: values.disabled || values.loading }, values.label),
  }
  const tag = { props: ['value'], setup: (values) => () => vue.h('span', {}, values.value) }
  const modules = new Proxy(
    {
      vue,
      'primevue/button': { default: button },
      'primevue/message': { default: wrapper },
      'primevue/tag': { default: tag },
      ...dependencies,
    },
    { get: (target, key) => target[key] || { default: wrapper } },
  )
  const { descriptor } = parse(await readFile(new URL(`../src/${path}`, import.meta.url), 'utf8'))
  const component = evaluateModule(
    compileScript(descriptor, { id: path, inlineTemplate: true }).content,
    modules,
  )
  const app = renderer.createApp(component, props)
  app.component('RouterLink', {
    props: ['to'],
    setup:
      (values, { slots }) =>
      () =>
        vue.h('a', { href: values.to }, slots.default?.()),
  })
  app.component('RouterView', wrapper)
  app.mount(root)
  context.after(() => app.unmount())
  await settle()
  return root
}

test('actual Telegram card loads the selected realm and reloads when its account identity changes', async (context) => {
  const { telegram, calls } = store()
  const view = await mountComponent(
    'components/TelegramConnectionCard.vue',
    { '@/stores/telegram': { useTelegramStore: () => telegram } },
    { scope: 'applicant' },
  )
  context.after(() => view.app.unmount())

  assert.deepEqual(calls, [['load', 'applicant']])
  telegram.accounts.applicant = '99'
  await settle()
  assert.deepEqual(calls, [
    ['load', 'applicant'],
    ['close', 'applicant'],
    ['load', 'applicant'],
  ])
  view.props.scope = 'employee'
  await settle()
  assert.equal(view.state.state.value, telegram.states.employee)
  assert.deepEqual(calls.at(-1), ['load', 'employee'])
  assert.deepEqual(calls.at(-2), ['close', 'applicant'])
  view.app.unmount()
  assert.deepEqual(calls.at(-1), ['close', 'employee'])
})

test('actual card connects through its store and opens a private link with Start and automatic update instructions', async (context) => {
  const { telegram, calls } = store()
  const root = await render(
    context,
    'components/TelegramConnectionCard.vue',
    { '@/stores/telegram': { useTelegramStore: () => telegram } },
    { scope: 'employee' },
  )
  const connect = all(root).find(
    (node) => node.tag === 'button' && text(node) === 'Connect Telegram',
  )

  connect.props.onClick()

  assert.deepEqual(calls.at(-1), ['connect', 'employee'])
  telegram.states.employee.link = {
    url: `https://t.me/hris_test_bot?start=${'A'.repeat(43)}`,
    expires_at: '2030-01-01T00:10:00Z',
  }
  await settle()
  const link = all(root).find((node) => node.tag === 'a')
  assert.equal(link.props.href, telegram.states.employee.link.url)
  assert.equal(link.props.target, '_blank')
  assert.equal(link.props.rel, 'noopener noreferrer')
  assert.equal(link.props.referrerpolicy, 'no-referrer')
  assert.match(text(root), /Press Start in @hris_test_bot/)
  assert.match(text(root), /connection status updates automatically/)
  const refresh = all(root).find((node) => node.tag === 'button' && text(node) === 'Refresh status')
  refresh.props.onClick()
  assert.deepEqual(calls.at(-1), ['load', 'employee'])
})

test('connected applicant card offers unlink and removes the linking instructions', async (context) => {
  const { telegram, calls } = store()
  telegram.states.applicant.status.connected = true
  const root = await render(
    context,
    'components/TelegramConnectionCard.vue',
    { '@/stores/telegram': { useTelegramStore: () => telegram } },
    { scope: 'applicant' },
  )

  assert.match(text(root), /Connected/)
  assert.match(text(root), /application, assessment, and interview updates/)
  assert.equal(
    all(root).some((node) => node.tag === 'a'),
    false,
  )
  const unlink = all(root).find((node) => node.tag === 'button' && text(node) === 'Unlink Telegram')
  unlink.props.onClick()
  assert.deepEqual(calls.at(-1), ['disconnect', 'applicant'])
})

for (const scope of ['employee', 'applicant']) {
  test(`${scope} command helper appears after linking and disappears when disconnected`, async (context) => {
    const { telegram } = store()
    const root = await render(
      context,
      'components/TelegramConnectionCard.vue',
      { '@/stores/telegram': { useTelegramStore: () => telegram } },
      { scope },
    )
    const helper = () =>
      all(root).find((node) => node.props?.['aria-label'] === 'Telegram commands')

    assert.equal(helper(), undefined)
    telegram.states[scope].status.connected = true
    await settle()

    assert.deepEqual(
      all(helper())
        .filter((node) => node.tag === 'code')
        .map(text),
      ['/start', '/help', '/status', '/portal', '/unlink', '/stop'],
    )
    assert.match(text(helper()), /chat with @hris_test_bot/)
    assert.match(text(helper()), /Disconnect all HRIS accounts linked to this Telegram chat/)
    telegram.states[scope].status.connected = false
    await settle()

    assert.equal(helper(), undefined)
  })
}

test('unavailable, expired, and failed Telegram states render actionable messages without a stale link', async (context) => {
  const { telegram } = store()
  telegram.states.employee.linkExpired = true
  const root = await render(
    context,
    'components/TelegramConnectionCard.vue',
    { '@/stores/telegram': { useTelegramStore: () => telegram } },
    { scope: 'employee' },
  )
  assert.match(text(root), /connection link expired/)
  assert.ok(all(root).find((node) => node.tag === 'button' && text(node) === 'Connect Telegram'))
  telegram.states.employee.status = {
    configured: false,
    bot_username: null,
    connected: false,
    linked_at: null,
  }
  await settle()
  assert.match(text(root), /currently unavailable/)
  assert.equal(
    all(root).some((node) => node.tag === 'button' && text(node) === 'Connect Telegram'),
    false,
  )
  telegram.states.employee.status = null
  telegram.states.employee.error = 'Too many attempts. Please wait before trying again.'
  await settle()
  assert.match(text(root), /Too many attempts/)
  assert.ok(
    all(root).find((node) => node.tag === 'button' && text(node) === 'Retry connection check'),
  )
})

test('employee profile shows Telegram settings even for an administrator without an employee profile', async (context) => {
  const card = {
    props: ['scope'],
    setup: (props) => () =>
      vue.h('section', { 'data-telegram-scope': props.scope }, 'Telegram settings'),
  }
  const root = await render(context, 'views/ProfileView.vue', {
    '@/stores/auth': {
      useAuthStore: () => ({
        user: { name: 'Administrator', email: 'admin@example.test' },
        employee: null,
        can: () => false,
      }),
    },
    '@/components/TelegramConnectionCard.vue': { default: card },
  })

  assert.ok(all(root).find((node) => node.props?.['data-telegram-scope'] === 'employee'))
  assert.match(text(root), /Administrator account/)
})

test('applicant account settings renders its own account and the applicant Telegram card', async (context) => {
  const card = {
    props: ['scope'],
    setup: (props) => () =>
      vue.h('section', { 'data-telegram-scope': props.scope }, 'Telegram settings'),
  }
  const root = await render(context, 'views/ApplicantSettingsView.vue', {
    '@/stores/applicant': {
      useApplicantStore: () => ({
        user: { name: 'Candidate Example', email: 'candidate@example.test' },
      }),
    },
    '@/components/TelegramConnectionCard.vue': { default: card },
  })

  assert.match(text(root), /Candidate Example/)
  assert.match(text(root), /candidate@example.test/)
  assert.ok(all(root).find((node) => node.props?.['data-telegram-scope'] === 'applicant'))
})

test('applicant navigation displays settings only for its authenticated applicant session', async (context) => {
  const applicant = vue.reactive({ authenticated: false })
  const root = await render(context, 'layout/ApplicantLayout.vue', {
    '@/stores/applicant': { useApplicantStore: () => applicant },
    '@/stores/ui': { useUiStore: () => ({ dark: false }) },
    'vue-router': { useRouter: () => ({ replace() {} }) },
  })

  assert.equal(
    all(root).some((node) => node.props?.href === '/applicant/settings'),
    false,
  )
  assert.ok(all(root).find((node) => node.props?.href === '/applicant/login'))
  applicant.authenticated = true
  await settle()
  assert.ok(all(root).find((node) => node.props?.href === '/applicant/settings'))
  assert.equal(
    all(root).some((node) => node.props?.href === '/applicant/login'),
    false,
  )
})
