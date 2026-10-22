import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { babelParse, compileScript, parse } from '@vue/compiler-sfc'
import * as vue from 'vue'
import { resources } from '../src/config/resources.js'
import { selfService } from '../src/config/selfService.js'
import { workflows, profileFields } from '../src/config/workflows.js'
import * as forms from '../src/utils/forms.js'
import * as workspace from '../src/utils/workspace.js'
import * as records from '../src/utils/records.js'

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

function deferred() {
  let resolve
  let reject
  const promise = new Promise((yes, no) => {
    resolve = yes
    reject = no
  })
  return { promise, resolve, reject }
}
async function settle() {
  await Promise.resolve()
  await vue.nextTick()
  await Promise.resolve()
  await vue.nextTick()
}
function response(record) {
  return { data: { data: record } }
}
function request(id, employeeId = 7) {
  return {
    id,
    employee_id: employeeId,
    status: 'pending',
    requested_minutes: 60,
    abilities: { approve: true, reject: true, cancel: false },
  }
}

async function mountWorkspace(name, { record = '101', ess = false, http = {} } = {}) {
  const route = vue.reactive({
    params: { module: name === 'WorkflowWorkspace' ? 'cto' : 'announcements' },
    meta: { ess },
    query: record === undefined ? {} : { record },
    fullPath: `/workspace?record=${record}`,
  })
  const gets = []
  const api = {
    get: async (path) => {
      gets.push(path)
      if (http.get) return http.get(path)
      return /\/\d+$/.test(path)
        ? response(request(Number(path.split('/').at(-1))))
        : { data: { data: [], meta: { total: 0 } } }
    },
    post: http.post || (async () => response({})),
    request: http.request || (async () => response({})),
  }
  const auth = { employee: { id: 7 }, user: { id: 70 }, can: () => true, canAny: () => true }
  const dependencies = new Proxy(
    {
      vue,
      'vue-router': { useRoute: () => route },
      'primevue/usetoast': { useToast: () => ({ add() {} }) },
      '@/api/http': {
        default: api,
        apiError: (error) => ({ message: error.message, status: error.status, errors: {} }),
      },
      '@/stores/auth': { useAuthStore: () => auth },
      '@/config/resources': { resources },
      '@/config/selfService': { selfService },
      '@/config/workflows': { workflows, profileFields },
      '@/utils/forms': forms,
      '@/utils/workspace': workspace,
      '@/utils/records': records,
    },
    { get: (target, key) => target[key] || { default: {} } },
  )
  const filename = new URL(`../src/views/${name}.vue`, import.meta.url)
  const { descriptor } = parse(await readFile(filename, 'utf8'))
  const compiled = compileScript(descriptor, { id: name })
  const edits = []
  for (const node of babelParse(compiled.content, { sourceType: 'module' }).program.body) {
    if (node.type === 'ImportDeclaration') {
      const declarations = node.specifiers
        .map((item) => {
          const imported = item.type === 'ImportDefaultSpecifier' ? 'default' : item.imported.name
          return `const ${item.local.name} = dependencies[${JSON.stringify(node.source.value)}][${JSON.stringify(imported)}];`
        })
        .join('\n')
      edits.push({ start: node.start, end: node.end, replacement: declarations })
    } else if (node.type === 'ExportDefaultDeclaration') {
      edits.push({ start: node.start, end: node.declaration.start, replacement: 'return ' })
    }
  }
  let content = compiled.content
  for (const edit of edits.sort((left, right) => right.start - left.start))
    content = content.slice(0, edit.start) + edit.replacement + content.slice(edit.end)
  const component = new Function('dependencies', content)(dependencies)
  let state
  const app = renderer.createApp({
    setup() {
      state = component.setup({}, { expose() {} })
      return () => null
    },
  })
  app.mount({})
  await settle()
  return {
    state,
    gets,
    app,
    route,
    async navigate(id) {
      route.query.record = String(id)
      route.fullPath = `/workspace?record=${id}`
      await settle()
    },
  }
}

test('workflow deep link fetches exact show even when list first page is empty', async (context) => {
  const view = await mountWorkspace('WorkflowWorkspace', { record: '101' })
  context.after(() => view.app.unmount())
  assert.equal(view.state.rows.value.length, 0)
  assert.ok(view.gets.includes('/cto-requests/101'))
  assert.equal(view.state.detail.value.id, 101)
})

test('workflow late show response cannot overwrite a newer query record', async (context) => {
  const old = deferred()
  const view = await mountWorkspace('WorkflowWorkspace', {
    http: {
      get: async (path) =>
        path === '/cto-requests/101'
          ? old.promise
          : /\/\d+$/.test(path)
            ? response(request(202))
            : { data: { data: [], meta: { total: 0 } } },
    },
  })
  context.after(() => view.app.unmount())
  await view.navigate(202)
  assert.equal(view.state.detail.value.id, 202)
  old.resolve(response(request(101)))
  await settle()
  assert.equal(view.state.detail.value.id, 202)
})

test('workflow completed old approval cannot reopen old detail after query navigation', async (context) => {
  const mutation = deferred()
  const view = await mountWorkspace('WorkflowWorkspace', { http: { post: () => mutation.promise } })
  context.after(() => view.app.unmount())
  view.state.openAction('approve')
  const pending = view.state.submitAction()
  await view.navigate(202)
  mutation.resolve(response(request(101)))
  await pending
  await settle()
  assert.equal(view.state.detail.value.id, 202)
  assert.equal(view.gets.filter((path) => path === '/cto-requests/101').length, 1)
  assert.equal(view.state.actionVisible.value, false)
})

test('resource completed old edit cannot reopen old detail after query navigation', async (context) => {
  const mutation = deferred()
  const view = await mountWorkspace('ResourceWorkspace', {
    http: { request: () => mutation.promise },
  })
  context.after(() => view.app.unmount())
  view.state.openForm('edit')
  const pending = view.state.submit()
  await view.navigate(202)
  mutation.resolve(response({ id: 101 }))
  await pending
  await settle()
  assert.equal(view.state.selected.value.id, 202)
  assert.equal(view.gets.filter((path) => path === '/announcements/101').length, 1)
  assert.equal(view.state.formOpen.value, false)
})

test('personal workflow deep link rejects another employee even when show authorizes it', async (context) => {
  const view = await mountWorkspace('WorkflowWorkspace', {
    ess: true,
    http: {
      get: async (path) =>
        /\/\d+$/.test(path)
          ? response(request(101, 8))
          : { data: { data: [], meta: { total: 0 } } },
    },
  })
  context.after(() => view.app.unmount())
  assert.equal(view.state.detail.value, null)
  assert.match(view.state.detailError.value, /outside your personal workspace/)
})

test('invalid record query makes no show request and displays an error', async (context) => {
  const view = await mountWorkspace('WorkflowWorkspace', { record: '../101' })
  context.after(() => view.app.unmount())
  assert.ok(!view.gets.some((path) => path.includes('../101')))
  assert.equal(view.state.detail.value, null)
  assert.match(view.state.detailError.value, /record link is invalid/)
})
