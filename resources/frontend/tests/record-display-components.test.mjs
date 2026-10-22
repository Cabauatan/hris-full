import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { compileScript, parse } from '@vue/compiler-sfc'
import * as vue from 'vue'
import * as records from '../src/utils/records.js'
import * as forms from '../src/utils/forms.js'
import { evaluateModule, settle } from './helpers/component-harness.mjs'

function nodes(node) {
  return [node, ...(node.children || []).flatMap(nodes)]
}

function text(node) {
  return nodes(node)
    .map((item) => item.text || '')
    .join(' ')
}

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

const tag = vue.defineComponent({
  props: ['value'],
  setup: (props) => () => vue.h('span', props.value),
})
const button = vue.defineComponent({
  props: ['label'],
  setup:
    (props, { attrs }) =>
    () =>
      vue.h('button', attrs, props.label),
})
const select = vue.defineComponent({
  props: ['modelValue', 'options', 'disabled'],
  emits: ['update:modelValue'],
  setup:
    (props, { emit }) =>
    () =>
      vue.h(
        'select',
        {
          disabled: props.disabled,
          onChange: (event) => emit('update:modelValue', Number(event.target.value)),
        },
        props.options.map((item) => vue.h('option', { value: item.value }, item.label)),
      ),
})
const numeric = vue.defineComponent({
  props: ['modelValue'],
  setup: (props) => () => vue.h('input', { type: 'number', value: props.modelValue }),
})

async function render(context, path, dependencies = {}, props = {}) {
  const { descriptor } = parse(await readFile(new URL(`../src/${path}`, import.meta.url), 'utf8'))
  const component = evaluateModule(
    compileScript(descriptor, { id: path, inlineTemplate: true }).content,
    {
      vue,
      '@/utils/forms': forms,
      '@/utils/records': records,
      'primevue/tag': { default: tag },
      'primevue/select': { default: select },
      'primevue/button': { default: button },
      'primevue/inputnumber': { default: numeric },
      ...dependencies,
    },
  )
  const reactiveProps = vue.reactive(props)
  const app = renderer.createApp({ setup: () => () => vue.h(component, reactiveProps) })
  const root = { children: [] }
  app.mount(root)
  context.after(() => app.unmount())
  await settle()
  return root
}

test('the real details template shows readable names and nested notes without internal IDs', async (context) => {
  const root = await render(
    context,
    'components/RecordDetails.vue',
    {},
    {
      record: {
        id: 9328,
        employee_id: 8271,
        display_labels: { employee_id: 'Ana Cruz · EMP-0091' },
        employee_number: 'EMP-0091',
        status: 'present',
        metadata: { id: 5832, user_id: 7394, note: 'Shift completed' },
      },
      columns: [{ key: 'employee_id', label: 'Employee ID' }],
    },
  )

  const content = text(root)
  assert.ok(content.includes('Ana Cruz'))
  assert.ok(content.includes('EMP-0091'))
  assert.ok(content.includes('Shift completed'))
  assert.ok(content.includes('present'))
  for (const identifier of ['9328', '8271', '5832', '7394'])
    assert.ok(!content.includes(identifier))
  assert.ok(!content.includes('Employee ID'))
  assert.ok(!content.includes('user_id'))
})

test('failed lookup renders a named selection and retry while never exposing numeric ID entry', async (context) => {
  let fail = true
  let calls = 0
  const root = await render(
    context,
    'components/LookupPicker.vue',
    {
      '@/stores/auth': { useAuthStore: () => ({ user: { id: 3 }, authenticated: true }) },
      '@/api/http': {
        default: {
          get: async () => {
            calls += 1
            if (fail) throw new Error('Directory unavailable')
            return {
              data: {
                data: [
                  { id: 8271, first_name: 'Ana', last_name: 'Cruz', employee_number: 'EMP-0091' },
                ],
                meta: { last_page: 1 },
              },
            }
          },
        },
      },
    },
    {
      field: {
        key: 'employee_id',
        label: 'Employee',
        lookup: { endpoint: '/employees', selectedLabel: 'Ana Cruz · EMP-0091' },
      },
      modelValue: 8271,
    },
  )

  assert.equal(calls, 1)
  assert.ok(text(root).includes('Ana Cruz'))
  assert.ok(!text(root).includes('8271'))
  assert.equal(
    nodes(root).some((node) => node.tag === 'input' && node.props.type === 'number'),
    false,
  )
  const retry = nodes(root).find((node) => node.tag === 'button' && /retry/i.test(node.text || ''))
  assert.ok(retry)
  fail = false
  retry.props.onClick()
  await settle()
  assert.equal(calls, 2)
  assert.ok(text(root).includes('EMP-0091'))
})

test('choosing a readable option emits the original numeric key and its separate label', async (context) => {
  const values = []
  const selections = []
  const props = vue.reactive({
    field: {
      key: 'employee_id',
      label: 'Employee',
      lookup: { options: [{ value: 8271, label: 'Ana Cruz · EMP-0091' }] },
    },
    modelValue: '',
    'onUpdate:modelValue': (value) => {
      values.push(value)
      props.modelValue = value
    },
    onSelectionChange: (value) => selections.push(value),
  })
  const root = await render(
    context,
    'components/LookupPicker.vue',
    {
      '@/stores/auth': { useAuthStore: () => ({ user: { id: 3 }, authenticated: true }) },
      '@/api/http': {
        default: {
          get: async () => {
            throw new Error('Own scope must not fetch')
          },
        },
      },
    },
    props,
  )

  nodes(root)
    .find((node) => node.tag === 'select')
    .props.onChange({ target: { value: '8271' } })
  await settle()
  assert.deepEqual(values, [8271])
  assert.deepEqual(selections.at(-1), { value: 8271, label: 'Ana Cruz · EMP-0091' })
})
