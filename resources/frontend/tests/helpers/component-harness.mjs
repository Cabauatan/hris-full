import { readFile } from 'node:fs/promises'
import { babelParse, compileScript, parse } from '@vue/compiler-sfc'
import * as vue from 'vue'
import { resources } from '../../src/config/resources.js'
import { selfService } from '../../src/config/selfService.js'
import { workflows, profileFields } from '../../src/config/workflows.js'
import * as forms from '../../src/utils/forms.js'
import * as workspace from '../../src/utils/workspace.js'
import * as records from '../../src/utils/records.js'
import { apiError } from '../../src/api/http.js'

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

export function deferred() {
  let resolve
  let reject
  const promise = new Promise((yes, no) => {
    resolve = yes
    reject = no
  })
  return { promise, resolve, reject }
}

export async function settle() {
  for (let turn = 0; turn < 4; turn += 1) {
    await Promise.resolve()
    await vue.nextTick()
  }
}

export function response(data, meta) {
  return { data: { data, ...(meta ? { meta } : {}) } }
}

export function httpFailure(status, message, errors = {}) {
  return { response: { status, data: { message, errors } } }
}

// Compile the real component setup and replace imports with isolated external boundaries.
// Vue's real refs, computed values, watchers, and mount/unmount hooks remain active.
export function evaluateModule(source, dependencies) {
  const edits = []
  for (const node of babelParse(source, { sourceType: 'module' }).program.body) {
    if (node.type === 'ImportDeclaration') {
      const replacement = node.specifiers
        .map((item) => {
          const imported = item.type === 'ImportDefaultSpecifier' ? 'default' : item.imported.name
          return `const ${item.local.name} = dependencies[${JSON.stringify(node.source.value)}][${JSON.stringify(imported)}];`
        })
        .join('\n')
      edits.push({ start: node.start, end: node.end, replacement })
    } else if (node.type === 'ExportDefaultDeclaration') {
      edits.push({ start: node.start, end: node.declaration.start, replacement: 'return ' })
    }
  }
  for (const edit of edits.sort((left, right) => right.start - left.start))
    source = source.slice(0, edit.start) + edit.replacement + source.slice(edit.end)
  return new Function('dependencies', source)(dependencies)
}

export async function mountComponent(relativePath, dependencies, props = {}) {
  const filename = new URL(`../../src/${relativePath}`, import.meta.url)
  const { descriptor } = parse(await readFile(filename, 'utf8'))
  const component = evaluateModule(
    compileScript(descriptor, { id: relativePath }).content,
    new Proxy(
      { vue, '@/utils/records': records, ...dependencies },
      { get: (target, key) => target[key] || { default: {} } },
    ),
  )
  const reactiveProps = vue.reactive(props)
  const events = []
  let state
  const app = renderer.createApp({
    setup() {
      state = component.setup(reactiveProps, {
        expose() {},
        emit: (...event) => events.push(event),
      })
      return () => null
    },
  })
  app.mount({})
  await settle()
  return { state, props: reactiveProps, events, app }
}

export async function mountWorkspace(
  name,
  {
    module = 'cto',
    ess = false,
    record,
    permissions = ['*'],
    employee = { id: 7 },
    http = {},
  } = {},
) {
  const route = vue.reactive({
    params: { module },
    meta: { ess },
    query: record === undefined ? {} : { record: String(record) },
    fullPath: `/${ess ? 'my/' : ''}workspace/${module}${record === undefined ? '' : `?record=${record}`}`,
  })
  const auth = vue.reactive({
    user: { id: 70 },
    employee,
    permissions,
    can(permission) {
      return this.permissions.includes('*') || this.permissions.includes(permission)
    },
    canAny(values) {
      return values.some((permission) => this.can(permission))
    },
  })
  const calls = []
  const messages = []
  const api = {}
  for (const method of ['get', 'post', 'request']) {
    api[method] = async (...args) => {
      calls.push({ method, args })
      if (http[method]) return http[method](...args)
      if (method === 'get') return response([], { total: 0, current_page: 1 })
      return response({})
    }
  }
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
    },
    { get: (target, key) => target[key] || { default: {} } },
  )
  const filename = new URL(`../../src/views/${name}.vue`, import.meta.url)
  const { descriptor } = parse(await readFile(filename, 'utf8'))
  const component = evaluateModule(compileScript(descriptor, { id: name }).content, dependencies)
  let state
  const app = renderer.createApp({
    setup() {
      state = component.setup({}, { expose() {} })
      return () => null
    },
  })
  app.mount({})
  await settle()
  return { state, route, auth, calls, messages, app }
}
