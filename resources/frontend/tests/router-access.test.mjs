import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import * as vueRouter from 'vue-router'
import { resources } from '../src/config/resources.js'
import { workflows } from '../src/config/workflows.js'
import { selfService } from '../src/config/selfService.js'
import { canViewCalendar } from '../src/config/workspace.js'
import { reportPermissions } from '../src/config/reports.js'
import { applicantRedirect } from '../src/utils/recruitment.js'
import { deferred, evaluateModule } from './helpers/component-harness.mjs'

const source = (await readFile(new URL('../src/router/index.js', import.meta.url), 'utf8')).replace(
  'import.meta.env.BASE_URL',
  "'/'",
)

function routeComponents(routes) {
  return routes.map((route) => ({
    ...route,
    ...(route.component ? { component: { render: () => null } } : {}),
    ...(route.children ? { children: routeComponents(route.children) } : {}),
  }))
}

async function mountRouter(
  context,
  {
    authenticated = true,
    employee = { id: 7 },
    permissions = [],
    initialization,
    applicantAuthenticated = false,
    applicantInitialization,
  } = {},
) {
  const previousDocument = globalThis.document
  globalThis.document = { title: '' }
  const auth = {
    authenticated,
    employee,
    permissions,
    initializations: 0,
    async initialize() {
      this.initializations += 1
      if (initialization) await initialization
    },
    canAny(values) {
      return values.some((permission) => this.permissions.includes(permission))
    },
  }
  const applicant = {
    authenticated: applicantAuthenticated,
    initializations: 0,
    async initialize() {
      this.initializations += 1
      if (applicantInitialization) await applicantInitialization
    },
  }
  const router = evaluateModule(source, {
    'vue-router': {
      createWebHistory: () => vueRouter.createMemoryHistory(),
      createRouter: (options) =>
        vueRouter.createRouter({ ...options, routes: routeComponents(options.routes) }),
    },
    '@/stores/auth': { useAuthStore: () => auth },
    '@/stores/applicant': { useApplicantStore: () => applicant },
    '@/config/resources': { resources },
    '@/config/workflows': { workflows },
    '@/config/selfService': { selfService },
    '@/config/workspace': { canViewCalendar },
    '@/config/reports': { reportPermissions },
    '@/utils/recruitment': { applicantRedirect },
  })
  context.after(() => {
    router.listening = false
    if (previousDocument === undefined) delete globalThis.document
    else globalThis.document = previousDocument
  })
  return { router, auth, applicant }
}

test('anonymous navigation redirects to sign-in and preserves exact internal record destination', async (context) => {
  const { router } = await mountRouter(context, { authenticated: false })

  await router.push('/my/requests/cto?record=101&status=pending#details')

  assert.equal(router.currentRoute.value.path, '/auth/login')
  assert.equal(
    router.currentRoute.value.query.redirect,
    '/my/requests/cto?record=101&status=pending#details',
  )
  assert.equal(globalThis.document.title, 'Sign in · HRIS')
})

test('route guard waits for session initialization before deciding access', async (context) => {
  const initialization = deferred()
  const { router, auth } = await mountRouter(context, {
    authenticated: false,
    initialization: initialization.promise,
  })

  const navigation = router.push('/dashboard')
  await new Promise((resolve) => setImmediate(resolve))
  assert.equal(router.currentRoute.value.matched.length, 0)
  assert.equal(auth.initializations, 1)
  auth.authenticated = true
  initialization.resolve()
  await navigation

  assert.equal(router.currentRoute.value.path, '/dashboard')
})

test('authenticated sign-in visit redirects into dashboard', async (context) => {
  const { router } = await mountRouter(context)

  await router.push('/auth/login?redirect=/reports')

  assert.equal(router.currentRoute.value.path, '/dashboard')
  assert.equal(globalThis.document.title, 'Dashboard · HRIS')
})

test('employee profile and workflow view permission are both required for personal requests', async (context) => {
  const { router, auth } = await mountRouter(context, { permissions: ['cto.view'], employee: null })
  await router.push('/my/requests/cto')
  assert.equal(router.currentRoute.value.path, '/forbidden')
  auth.employee = { id: 7 }
  auth.permissions = ['cto.create']
  await router.push('/my/requests/cto')
  assert.equal(router.currentRoute.value.path, '/forbidden')

  auth.permissions = ['cto.view']
  await router.push('/my/requests/cto?record=101')

  assert.equal(router.currentRoute.value.path, '/my/requests/cto')
  assert.equal(globalThis.document.title, 'CTO requests · HRIS')
})

test('reviewer request workspace accepts a module viewer without requiring an employee profile', async (context) => {
  const { router } = await mountRouter(context, {
    permissions: ['official-business.view'],
    employee: null,
  })

  await router.push('/requests/official-business')

  assert.equal(router.currentRoute.value.path, '/requests/official-business')
  assert.equal(globalThis.document.title, 'Official business · HRIS')
})

test('admin resource access uses its configured alternative view permissions', async (context) => {
  const { router, auth } = await mountRouter(context, { permissions: ['announcements.manage'] })

  await router.push('/manage/announcements')

  assert.equal(router.currentRoute.value.path, '/manage/announcements')
  assert.equal(globalThis.document.title, 'Announcements · HRIS')
  auth.permissions = ['employees.view']
  await router.push('/manage/system-settings')
  assert.equal(router.currentRoute.value.path, '/forbidden')
})

test('self-service routes select the personal resource contract and require the linked profile', async (context) => {
  const { router, auth } = await mountRouter(context, { permissions: ['attendance.view'] })
  await router.push('/my/daily-attendances')
  assert.equal(router.currentRoute.value.path, '/my/daily-attendances')
  assert.equal(router.currentRoute.value.meta.ess, true)
  auth.employee = null

  await router.push('/my/time-logs')

  assert.equal(router.currentRoute.value.path, '/forbidden')
})

test('unknown module keys resolve to page-not-found without granting an admin route', async (context) => {
  const { router } = await mountRouter(context, { permissions: ['employees.view'] })

  await router.push('/manage/does-not-exist')

  assert.equal(router.currentRoute.value.name, 'not-found')
  assert.equal(globalThis.document.title, 'Page not found · HRIS')
})

test('Reports and Calendar require their real domain view permissions rather than create access', async (context) => {
  const { router, auth } = await mountRouter(context, {
    permissions: ['leave.create'],
    employee: null,
  })
  await router.push('/reports')
  assert.equal(router.currentRoute.value.path, '/forbidden')
  await router.push('/calendar')
  assert.equal(router.currentRoute.value.path, '/forbidden')
  auth.permissions = ['leave.view']

  await router.push('/reports')

  assert.equal(router.currentRoute.value.path, '/reports')
  await router.push('/calendar')
  assert.equal(router.currentRoute.value.path, '/calendar')
})

test('calendar permits a linked active employee without module permissions to view their birthday', async (context) => {
  const { router, auth } = await mountRouter(context)
  await router.push('/calendar')
  assert.equal(router.currentRoute.value.path, '/calendar')
  auth.employee = null
  await router.push('/dashboard')
  await router.push('/calendar')
  assert.equal(router.currentRoute.value.path, '/forbidden')
  auth.permissions = ['employees.view-team']
  await router.push('/calendar')
  assert.equal(router.currentRoute.value.path, '/calendar')
  auth.authenticated = false
  await router.push('/dashboard')
  await router.push('/calendar')
  assert.equal(router.currentRoute.value.path, '/auth/login')
})

test('active signed-in accounts may open dashboard, profile, and notifications without module permissions', async (context) => {
  const { router } = await mountRouter(context, { employee: null })

  for (const path of ['/dashboard', '/profile', '/notifications']) {
    await router.push(path)
    assert.equal(router.currentRoute.value.path, path)
  }
})

test('careers remains public for employees and anonymous visitors without employee session initialization', async (context) => {
  const { router, auth, applicant } = await mountRouter(context)
  await router.push('/careers')
  assert.equal(router.currentRoute.value.name, 'careers')
  assert.equal(auth.initializations, 0)
  assert.equal(applicant.initializations, 0)
  auth.authenticated = false
  await router.push('/careers?source=public')
  assert.equal(router.currentRoute.value.path, '/careers')
})

test('employee authentication grants no applicant session and applicant redirects preserve exam destination', async (context) => {
  const { router, auth, applicant } = await mountRouter(context)
  await router.push('/applicant/exams/9?review=1')
  assert.equal(router.currentRoute.value.path, '/applicant/login')
  assert.equal(router.currentRoute.value.query.redirect, '/applicant/exams/9?review=1')
  assert.equal(auth.initializations, 0)
  assert.ok(applicant.initializations > 0)
})

test('applicant sessions grant access to their portal but do not grant employee workspace access', async (context) => {
  const { router } = await mountRouter(context, {
    authenticated: false,
    applicantAuthenticated: true,
  })
  await router.push('/applicant/register')
  assert.equal(router.currentRoute.value.path, '/applicant/applications')
  await router.push('/applicant/interviews/8')
  assert.equal(router.currentRoute.value.meta.applicant, true)
  assert.equal(router.currentRoute.value.params.interviewId, '8')
  await router.push('/dashboard')
  assert.equal(router.currentRoute.value.path, '/auth/login')
})

test('applicant route guard waits for its own initialization before granting portal access', async (context) => {
  const pending = deferred()
  const { router, auth, applicant } = await mountRouter(context, {
    applicantInitialization: pending.promise,
  })
  const navigation = router.push('/applicant/applications')
  await new Promise((resolve) => setImmediate(resolve))
  assert.equal(router.currentRoute.value.matched.length, 0)
  assert.equal(auth.initializations, 0)
  applicant.authenticated = true
  pending.resolve()
  await navigation
  assert.equal(router.currentRoute.value.path, '/applicant/applications')
})

test('staff recruitment and interview rooms require the recruitment view permission', async (context) => {
  const { router, auth } = await mountRouter(context, { permissions: ['employees.view'] })
  await router.push('/recruitment')
  assert.equal(router.currentRoute.value.path, '/forbidden')
  auth.permissions = ['recruitment.view']
  await router.push('/recruitment')
  assert.equal(router.currentRoute.value.name, 'recruitment')
  await router.push('/recruitment/interviews/10')
  assert.equal(router.currentRoute.value.name, 'recruitment-interview')
  assert.equal(router.currentRoute.value.meta.applicant, undefined)
})

test('the root URL keeps its employee dashboard destination and the applicant root uses its own portal', async (context) => {
  const { router } = await mountRouter(context, { applicantAuthenticated: true })
  await router.push('/')
  assert.equal(router.currentRoute.value.name, 'dashboard')
  await router.push('/applicant')
  assert.equal(router.currentRoute.value.name, 'applicant-applications')
})

test('applicant auth redirects preserve an opening selection before and after authentication', async (context) => {
  const { router, applicant } = await mountRouter(context)
  await router.push('/applicant/applications?opening=101')
  assert.equal(router.currentRoute.value.name, 'applicant-login')
  assert.equal(router.currentRoute.value.query.redirect, '/applicant/applications?opening=101')
  applicant.authenticated = true
  await router.push({
    path: '/applicant/register',
    query: { redirect: '/applicant/applications?opening=101' },
  })
  assert.equal(router.currentRoute.value.fullPath, '/applicant/applications?opening=101')
  await router.push({
    path: '/applicant/login',
    query: { redirect: 'https://external.example.test' },
  })
  assert.equal(router.currentRoute.value.fullPath, '/applicant/applications')
})

test('Telegram applicant settings requires the applicant session and preserves its sign-in destination', async (context) => {
  const { router, applicant, auth } = await mountRouter(context)

  await router.push('/applicant/settings')

  assert.equal(router.currentRoute.value.name, 'applicant-login')
  assert.equal(router.currentRoute.value.query.redirect, '/applicant/settings')
  assert.equal(auth.initializations, 0)
  applicant.authenticated = true
  await router.push({ path: '/applicant/register', query: { redirect: '/applicant/settings' } })
  assert.equal(router.currentRoute.value.name, 'applicant-settings')
  assert.equal(router.currentRoute.value.meta.applicant, true)
  assert.equal(globalThis.document.title, 'Account settings · HRIS')
})
