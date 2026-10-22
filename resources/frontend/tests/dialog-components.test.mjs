import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { compileScript, parse } from '@vue/compiler-sfc'
import * as vue from 'vue'
import { resources } from '../src/config/resources.js'
import { selfService } from '../src/config/selfService.js'
import { workflows, profileFields } from '../src/config/workflows.js'
import * as forms from '../src/utils/forms.js'
import * as workspace from '../src/utils/workspace.js'
import * as records from '../src/utils/records.js'
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

function content(node) {
  return nodes(node)
    .map((item) => item.text || '')
    .join(' ')
}

const renderer = vue.createRenderer({
  createElement: (tag) => ({ tag, props: {}, children: [] }),
  createText: (text) => ({ text }),
  createComment: () => ({}),
  insert(node, parent, anchor) {
    if (node.parent) {
      const previous = node.parent.children.indexOf(node)
      if (previous >= 0) node.parent.children.splice(previous, 1)
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
  setText: (node, text) => {
    node.text = text
  },
  setElementText(node, text) {
    node.text = text
    node.children = []
  },
  patchProp: (node, key, previous, value) => {
    node.props[key] = value
  },
  parentNode: (node) => node.parent,
  nextSibling: (node) => node.parent?.children[node.parent.children.indexOf(node) + 1] || null,
})

const button = vue.defineComponent({
  props: ['label', 'disabled', 'loading'],
  setup:
    (props, { attrs }) =>
    () =>
      vue.h(
        'button',
        {
          ...attrs,
          disabled: Boolean(props.disabled || props.loading),
        },
        props.label,
      ),
})

function input(tag = 'input', numeric = false) {
  return vue.defineComponent({
    inheritAttrs: false,
    props: ['modelValue', 'inputId', 'disabled'],
    emits: ['update:modelValue'],
    setup:
      (props, { attrs, emit }) =>
      () =>
        vue.h(tag, {
          ...attrs,
          id: props.inputId || attrs.id,
          value: props.modelValue,
          disabled: props.disabled,
          onInput: (event) =>
            emit('update:modelValue', numeric ? Number(event.target.value) : event.target.value),
        }),
  })
}

const select = vue.defineComponent({
  inheritAttrs: false,
  props: ['modelValue', 'inputId', 'options', 'disabled'],
  emits: ['update:modelValue'],
  setup:
    (props, { attrs, emit }) =>
    () =>
      vue.h(
        'select',
        {
          ...attrs,
          id: props.inputId,
          value: props.modelValue,
          disabled: props.disabled,
          onChange(event) {
            const option = props.options?.find((item) => String(item.value) === event.target.value)
            emit('update:modelValue', option?.value ?? null)
          },
        },
        (props.options || []).map((item) => vue.h('option', { value: item.value }, item.label)),
      ),
})

const toggle = vue.defineComponent({
  inheritAttrs: false,
  props: ['modelValue', 'inputId', 'disabled'],
  emits: ['update:modelValue'],
  setup:
    (props, { attrs, emit }) =>
    () =>
      vue.h('input', {
        ...attrs,
        id: props.inputId,
        type: 'checkbox',
        checked: props.modelValue,
        disabled: props.disabled,
        onChange: (event) => emit('update:modelValue', event.target.checked),
      }),
})

const wrapper = vue.defineComponent({
  setup:
    (props, { attrs, slots }) =>
    () =>
      vue.h('div', attrs, slots.default?.()),
})
const empty = vue.defineComponent({ setup: () => () => null })

async function mount(context, name, { module, record, http = {} } = {}) {
  const route = vue.reactive({
    params: { module },
    meta: {},
    query: record === undefined ? {} : { record: String(record) },
    fullPath: `/workspace/${module}${record === undefined ? '' : `?record=${record}`}`,
  })
  const auth = vue.reactive({
    authenticated: true,
    user: { id: 70 },
    employee: { id: 7 },
    can: () => true,
    canAny: () => true,
  })
  const calls = []
  const messages = []
  const api = {}
  for (const method of ['get', 'post', 'request']) {
    api[method] = async (...args) => {
      calls.push({ method, args })
      if (http[method]) return http[method](...args)
      return response([], { total: 0, current_page: 1, last_page: 1 })
    }
  }
  const escapeHandlers = new Set()
  // PrimeVue registers a document listener for every visible dialog. Escape uses
  // closeOnEscape independently of the closable prop that controls its X button.
  const dialog = vue.defineComponent({
    props: {
      visible: Boolean,
      header: String,
      closable: { type: Boolean, default: true },
      closeOnEscape: { type: Boolean, default: true },
    },
    emits: ['update:visible'],
    setup(props, { emit, slots }) {
      const close = () => emit('update:visible', false)
      const escape = () => {
        if (props.visible && props.closeOnEscape) close()
      }
      vue.onMounted(() => escapeHandlers.add(escape))
      vue.onBeforeUnmount(() => escapeHandlers.delete(escape))
      return () =>
        props.visible
          ? vue.h('section', { role: 'dialog', 'aria-label': props.header }, [
              vue.h('h2', props.header),
              ...(props.closable
                ? [
                    vue.h(
                      'button',
                      {
                        'aria-label': `Close dialog: ${props.header}`,
                        onClick: close,
                      },
                      '×',
                    ),
                  ]
                : []),
              slots.default?.(),
            ])
          : null
    },
  })
  const dependencies = new Proxy(
    {
      vue,
      'vue-router': { useRoute: () => route },
      'primevue/usetoast': { useToast: () => ({ add: (message) => messages.push(message) }) },
      '@/api/http': { default: api, apiError },
      '@/stores/auth': { useAuthStore: () => auth },
      '@/config/resources': { resources },
      '@/config/selfService': { selfService },
      '@/config/workflows': { workflows, profileFields },
      '@/utils/forms': forms,
      '@/utils/workspace': workspace,
      '@/utils/records': records,
      'primevue/dialog': { default: dialog },
      'primevue/button': { default: button },
      'primevue/inputtext': { default: input() },
      'primevue/inputnumber': { default: input('input', true) },
      'primevue/textarea': { default: input('textarea') },
      'primevue/select': { default: select },
      'primevue/toggleswitch': { default: toggle },
      'primevue/checkbox': { default: toggle },
      'primevue/tag': {
        default: vue.defineComponent({
          props: ['value'],
          setup: (props) => () => vue.h('span', props.value),
        }),
      },
      'primevue/datatable': { default: empty },
      'primevue/column': { default: empty },
      'primevue/paginator': { default: empty },
    },
    { get: (target, key) => target[key] || { default: wrapper } },
  )
  async function compile(path) {
    const { descriptor } = parse(await readFile(new URL(`../src/${path}`, import.meta.url), 'utf8'))
    return evaluateModule(
      compileScript(descriptor, { id: path, inlineTemplate: true }).content,
      dependencies,
    )
  }
  dependencies['./LookupPicker.vue'] = { default: await compile('components/LookupPicker.vue') }
  dependencies['./PayrollItemsEditor.vue'] = {
    default: await compile('components/PayrollItemsEditor.vue'),
  }
  dependencies['@/components/FieldForm.vue'] = {
    default: await compile('components/FieldForm.vue'),
  }
  dependencies['@/components/RecordDetails.vue'] = {
    default: await compile('components/RecordDetails.vue'),
  }
  const component = await compile(`views/${name}.vue`)
  const app = renderer.createApp(component)
  app.component('RouterLink', wrapper)
  const root = { children: [] }
  app.mount(root)
  context.after(() => app.unmount())
  await settle()
  return {
    root,
    route,
    calls,
    messages,
    async escape() {
      for (const handler of [...escapeHandlers]) handler()
      await settle()
    },
  }
}

function dialogs(root) {
  return nodes(root).filter((node) => node.props?.role === 'dialog')
}

function findDialog(root, title) {
  const dialog = dialogs(root).find((node) => node.props['aria-label'] === title)
  assert.ok(dialog, `Expected dialog ${title}`)
  return dialog
}

function findButton(root, label) {
  const match = nodes(root).find(
    (node) => node.tag === 'button' && (node.text === label || node.props['aria-label'] === label),
  )
  assert.ok(match, `Expected button ${label}`)
  return match
}

async function click(root, label) {
  const button = findButton(root, label)
  assert.equal(button.props.disabled, false, `Button ${label} should be enabled`)
  button.props.onClick()
  await settle()
}

function field(root, labelText) {
  const label = nodes(root).find(
    (node) =>
      node.tag === 'label' &&
      content(node)
        .trim()
        .replace(/\s*\*$/, '') === labelText,
  )
  assert.ok(label, `Expected field label ${labelText}`)
  const control = nodes(root).find(
    (node) =>
      ['input', 'textarea', 'select'].includes(node.tag) && node.props.id === label.props.for,
  )
  assert.ok(control, `Expected associated control ${labelText}`)
  return control
}

async function change(root, label, value) {
  const control = field(root, label)
  const event = { target: { value: String(value) } }
  ;(control.props.onChange || control.props.onInput)(event)
  await settle()
}

function submit(dialog) {
  const form = nodes(dialog).find((node) => node.tag === 'form')
  assert.ok(form)
  return form.props.onSubmit({ preventDefault() {}, stopPropagation() {} })
}

function writes(view) {
  return view.calls.filter(({ method }) => method !== 'get')
}

const longSchedule =
  'Regular weekday schedule for regional operations and customer support teams with extended coverage and rotating assignment groups'
function scheduleDay() {
  return {
    id: 41,
    work_schedule_id: 12,
    day_of_week: 1,
    is_working_day: true,
    shift_id: 9,
    work_schedule: { id: 12, code: 'REGULAR', name: longSchedule },
    shift: { id: 9, code: 'DAY', name: 'Day shift', start_time: '08:00:00', end_time: '17:00:00' },
  }
}

async function scheduleView(context, request) {
  const record = scheduleDay()
  return mount(context, 'ResourceWorkspace', {
    module: 'work-schedule-days',
    record: 41,
    http: {
      get: (path) =>
        response(
          path === '/work-schedule-days/41'
            ? record
            : path === '/work-schedules'
              ? [record.work_schedule]
              : path === '/shifts'
                ? [record.shift]
                : [record],
          { total: 1, last_page: 1 },
        ),
      request,
    },
  })
}

test('schedule edit renders the long selected label beside Weekday and saves the original relation keys', async (context) => {
  const view = await scheduleView(context)
  await click(view.root, 'Edit record')
  const edit = findDialog(view.root, 'Edit Schedule day')
  assert.ok(content(field(edit, 'Work schedule')).includes(longSchedule))
  assert.ok(field(edit, 'Work schedule').props.title.includes(longSchedule))
  assert.equal(field(edit, 'Work schedule').props.value, 12)
  assert.equal(field(edit, 'Shift').props.value, 9)
  await change(edit, 'Weekday', 5)
  await submit(edit)
  await settle()

  assert.deepEqual(writes(view)[0].args[0], {
    method: 'put',
    url: '/work-schedule-days/41',
    data: { work_schedule_id: 12, day_of_week: 5, is_working_day: true, shift_id: 9 },
  })
  assert.equal(dialogs(view.root).length, 1)
  assert.ok(content(findDialog(view.root, 'Schedule day details')).includes(longSchedule))
})

test('a pending resource save blocks repeated submit and Escape, then retains its draft and server errors', async (context) => {
  const mutation = deferred()
  const view = await scheduleView(context, () => mutation.promise)
  await click(view.root, 'Edit record')
  const edit = findDialog(view.root, 'Edit Schedule day')
  await change(edit, 'Weekday', 5)
  const pending = submit(edit)
  await settle()
  const duplicate = submit(edit)
  await settle()
  await view.escape()

  assert.equal(writes(view).length, 1)
  assert.equal(dialogs(view.root).length, 2)
  assert.equal(field(edit, 'Weekday').props.disabled, true)
  assert.equal(findButton(edit, 'Cancel').props.disabled, true)
  mutation.reject(
    httpFailure(422, 'This weekday cannot be assigned.', {
      day_of_week: ['Another shift already uses this weekday.'],
    }),
  )
  await Promise.all([pending, duplicate])
  await settle()

  const recovered = findDialog(view.root, 'Edit Schedule day')
  assert.equal(field(recovered, 'Weekday').props.value, 5)
  assert.equal(field(recovered, 'Work schedule').props.value, 12)
  assert.ok(content(recovered).includes('Another shift already uses this weekday.'))
  assert.equal(findButton(recovered, 'Save record').props.disabled, false)
  assert.equal(view.messages.length, 0)
})

test('Escape closes only the idle resource edit and the reopened form resets the cancelled draft', async (context) => {
  const view = await scheduleView(context)
  await click(view.root, 'Edit record')
  await change(findDialog(view.root, 'Edit Schedule day'), 'Weekday', 7)
  await view.escape()
  assert.equal(dialogs(view.root).length, 1)
  assert.ok(findDialog(view.root, 'Schedule day details'))
  await click(view.root, 'Edit record')
  assert.equal(field(findDialog(view.root, 'Edit Schedule day'), 'Weekday').props.value, 1)
})

test('open filters and a resource create dialog give each repeated field its own label association', async (context) => {
  const view = await mount(context, 'ResourceWorkspace', {
    module: 'employee-schedule-assignments',
  })
  await click(view.root, 'Filters')
  await click(view.root, 'Add Schedule assignment')
  const form = findDialog(view.root, 'Add Schedule assignment')
  const labels = nodes(view.root).filter((node) => node.tag === 'label' && node.props.for)
  const ids = nodes(view.root)
    .map((node) => node.props?.id)
    .filter(Boolean)
  assert.equal(new Set(ids).size, ids.length)
  const employeeLabels = labels.filter(
    (node) =>
      content(node)
        .trim()
        .replace(/\s*\*$/, '') === 'Employee',
  )
  assert.equal(employeeLabels.length, 2)
  for (const label of labels) {
    const control = nodes(view.root).find((node) => node.props?.id === label.props.for)
    assert.ok(control)
    if (control.tag === 'select') assert.equal(control.props['aria-labelledby'], label.props.id)
  }
  assert.notEqual(
    field(form, 'Employee').props.id,
    field(view.root.children[0], 'Employee').props.id,
  )
})

function ctoRequest() {
  return {
    id: 101,
    employee_id: 7,
    status: 'pending',
    requested_minutes: 60,
    abilities: { approve: true, reject: true },
  }
}

test('workflow action Escape preserves the parent, and a busy rejection keeps remarks after failure', async (context) => {
  const mutation = deferred()
  const view = await mount(context, 'WorkflowWorkspace', {
    module: 'cto',
    record: 101,
    http: {
      get: (path) => response(path.endsWith('/101') ? ctoRequest() : [], { total: 0 }),
      post: () => mutation.promise,
    },
  })
  await click(view.root, 'Approve')
  await view.escape()
  assert.equal(dialogs(view.root).length, 1)
  assert.ok(findDialog(view.root, 'CTO request'))
  await click(view.root, 'Reject')
  const action = findDialog(view.root, 'Reject cto request')
  await change(action, 'Remarks (optional)', 'Please select another time window.')
  const pending = submit(action)
  await settle()
  const duplicate = submit(action)
  await settle()
  await view.escape()
  assert.equal(writes(view).length, 1)
  assert.equal(dialogs(view.root).length, 2)
  mutation.reject(
    httpFailure(422, 'Please explain the decision.', {
      remarks: ['Add more detail to the remarks.'],
    }),
  )
  await Promise.all([pending, duplicate])
  await settle()
  const recovered = findDialog(view.root, 'Reject cto request')
  assert.equal(
    field(recovered, 'Remarks (optional)').props.value,
    'Please select another time window.',
  )
  assert.ok(content(recovered).includes('Add more detail to the remarks.'))
})

test('workflow creation blocks Escape while posting and preserves the entered date range after failure', async (context) => {
  const mutation = deferred()
  const view = await mount(context, 'WorkflowWorkspace', {
    module: 'cto',
    http: { post: () => mutation.promise },
  })
  await click(view.root, 'New request')
  const create = findDialog(view.root, 'New cto request')
  await change(create, 'Start date and time', '2026-10-13T09:00:00')
  await change(create, 'End date and time', '2026-10-13T10:00:00')
  await change(create, 'Reason', 'Personal appointment')
  const pending = submit(create)
  await settle()
  const duplicate = submit(create)
  await settle()
  await view.escape()
  assert.equal(writes(view).length, 1)
  assert.equal(dialogs(view.root).length, 1)
  mutation.reject(
    httpFailure(422, 'Not enough credits.', {
      start_at: ['The requested date has no available credit.'],
    }),
  )
  await Promise.all([pending, duplicate])
  await settle()
  const recovered = findDialog(view.root, 'New cto request')
  assert.equal(field(recovered, 'Start date and time').props.value, '2026-10-13T09:00:00')
  assert.equal(field(recovered, 'End date and time').props.value, '2026-10-13T10:00:00')
  assert.ok(content(recovered).includes('The requested date has no available credit.'))
})

test('closing and reopening profile creation prevents an old initialization from overwriting the new draft', async (context) => {
  const oldProfile = deferred()
  const newProfile = deferred()
  let requests = 0
  const view = await mount(context, 'WorkflowWorkspace', {
    module: 'profile-changes',
    http: {
      get: (path) =>
        path === '/ess/profile'
          ? ++requests === 1
            ? oldProfile.promise
            : newProfile.promise
          : response([], { total: 0 }),
    },
  })
  await click(view.root, 'New request')
  await view.escape()
  assert.equal(dialogs(view.root).length, 0)
  await click(view.root, 'New request')
  newProfile.resolve(
    response({ first_name: 'Current', last_name: 'Employee', country: 'Philippines' }),
  )
  await settle()
  await change(findDialog(view.root, 'New profile change request'), 'First name', 'Proposed name')
  oldProfile.resolve(
    response({ first_name: 'Outdated', last_name: 'Employee', country: 'Philippines' }),
  )
  await settle()
  assert.equal(
    field(findDialog(view.root, 'New profile change request'), 'First name').props.value,
    'Proposed name',
  )
  assert.equal(requests, 2)
})

test('an old leave initialization cannot finish or populate a reopened form while its new load is pending', async (context) => {
  const oldTypes = deferred()
  const newTypes = deferred()
  let requests = 0
  const view = await mount(context, 'WorkflowWorkspace', {
    module: 'leave',
    http: {
      get: (path) =>
        path === '/leave-types'
          ? ++requests === 1
            ? oldTypes.promise
            : newTypes.promise
          : response([], { total: 0 }),
    },
  })
  await click(view.root, 'New request')
  await view.escape()
  await click(view.root, 'New request')
  oldTypes.resolve(
    response([{ id: 1, name: 'Outdated leave type', code: 'OLD', is_active: true }], {
      last_page: 1,
    }),
  )
  await settle()
  const pending = findDialog(view.root, 'New leave request')
  assert.ok(content(pending).includes('Loading your form'))
  assert.equal(nodes(pending).filter((node) => node.tag === 'form').length, 0)
  newTypes.resolve(
    response([{ id: 2, name: 'Current leave type', code: 'NEW', is_active: true }], {
      last_page: 1,
    }),
  )
  await settle()
  const control = field(findDialog(view.root, 'New leave request'), 'Leave type')
  assert.ok(content(control).includes('Current leave type'))
  assert.ok(!content(control).includes('Outdated leave type'))
  assert.equal(requests, 2)
})
