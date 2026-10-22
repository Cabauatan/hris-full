import test, { before, beforeEach, afterEach } from 'node:test'
import assert from 'node:assert/strict'
import { registerHooks } from 'node:module'
import { setImmediate } from 'node:timers/promises'
import { createPinia, setActivePinia } from 'pinia'
import { AxiosError } from 'axios'
import { deferred } from './helpers/component-harness.mjs'

let httpModule
let useApplicantStore
let useAuthStore
let useRecruitmentStore
let applicant
let auth
let recruitment
let calls
let oldWindow
const candidate = (id = 1) => ({
  id,
  first_name: 'Candidate',
  last_name: 'Example',
  name: 'Candidate Example',
  email: `candidate-${id}@example.test`,
  is_active: true,
})
const employee = (id = 9) => ({
  id,
  name: 'Reviewer',
  is_active: true,
  permissions: ['recruitment.view', 'recruitment.manage', 'recruitment.grade'],
  roles: ['hr_staff'],
})
const application = (id = 10, overrides = {}) => ({
  id,
  status: 'approved',
  desired_position: 'Analyst',
  applicant: candidate(),
  attempts: [],
  abilities: {
    assign_exam: true,
    approve: false,
    reject: true,
    hire: false,
    schedule_interview: true,
  },
  ...overrides,
})
const attempt = (id = 3, overrides = {}) => ({
  id,
  status: 'assigned',
  title: 'Assessment',
  questions: [{ id: 'written-question', type: 'written', prompt: 'Explain', points: 10 }],
  abilities: { submit: true, grade: false },
  ...overrides,
})
function response(config, data = {}, status = 200) {
  return { config, data, status, statusText: String(status), headers: {}, request: {} }
}
function fail(config, status, errors = {}) {
  const result = response(config, { message: 'Request rejected.', errors }, status)
  throw new AxiosError('Rejected', AxiosError.ERR_BAD_REQUEST, config, result.request, result)
}
function adapter(handler) {
  httpModule.http.defaults.adapter = async (config) => {
    calls.push(config)
    if (config.url.endsWith('/csrf-cookie')) return response(config, '', 204)
    return handler(config)
  }
}
before(async () => {
  const hook = registerHooks({
    resolve(specifier, context, nextResolve) {
      if (specifier.startsWith('@/'))
        return nextResolve(
          new URL(`../src/${specifier.slice(2)}.js`, import.meta.url).href,
          context,
        )
      return nextResolve(specifier, context)
    },
  })
  httpModule = await import('../src/api/http.js')
  ;({ useApplicantStore } = await import('../src/stores/applicant.js'))
  ;({ useAuthStore } = await import('../src/stores/auth.js'))
  ;({ useRecruitmentStore } = await import('../src/stores/recruitment.js'))
  hook.deregister()
})
beforeEach(() => {
  oldWindow = globalThis.window
  globalThis.window = new EventTarget()
  httpModule.resetAuthSession()
  httpModule.resetAuthSession('applicant')
  setActivePinia(createPinia())
  auth = useAuthStore()
  auth.user = employee()
  applicant = useApplicantStore()
  recruitment = useRecruitmentStore()
  calls = []
  adapter((config) => {
    throw new Error(`Unexpected ${config.url}`)
  })
})
afterEach(() => {
  applicant.$dispose()
  recruitment.$dispose()
  auth.$dispose()
  setActivePinia(undefined)
  if (oldWindow === undefined) delete globalThis.window
  else globalThis.window = oldWindow
})

test('applicant registration bootstraps applicant CSRF and leaves employee identity intact', async () => {
  adapter((config) => response(config, { data: candidate() }))
  await applicant.register({
    first_name: 'Candidate',
    last_name: 'Example',
    email: 'candidate@example.test',
    password: 'Test-password',
    password_confirmation: 'Test-password',
  })
  assert.deepEqual(
    calls.map((config) => config.url),
    ['/applicant/auth/csrf-cookie', '/applicant/auth/register'],
  )
  assert.equal(applicant.authenticated, true)
  assert.equal(applicant.user.id, 1)
  assert.equal(auth.user.id, 9)
  assert.equal(applicant.user.password, undefined)
})

test('concurrent applicant initialization shares one request and ignores a response after logout', async () => {
  const pending = deferred()
  adapter(() => pending.promise)
  const first = applicant.initialize()
  const second = applicant.initialize()
  await setImmediate()
  assert.equal(calls.length, 1)
  applicant.clearSession()
  pending.resolve(response(calls[0], { data: candidate() }))
  await Promise.all([first, second])
  assert.equal(applicant.user, null)
  assert.equal(applicant.initialized, true)
})

test('applicant sign-in invalidates an older profile response', async () => {
  const pending = deferred()
  adapter((config) =>
    config.url.endsWith('/me') ? pending.promise : response(config, { data: candidate(2) }),
  )
  const initializing = applicant.initialize()
  await setImmediate()
  const profileConfig = calls[0]
  await applicant.signIn({ email: 'candidate-2@example.test', password: 'Test-password' })
  pending.resolve(response(profileConfig, { data: candidate(1) }))
  await initializing
  assert.equal(applicant.user.id, 2)
})

test('applicant 401 expires only its own realm while employee 401 keeps its event contract', async () => {
  let applicantEvents = 0
  let employeeEvents = 0
  window.addEventListener('hris:applicant-session-expired', () => applicantEvents++)
  window.addEventListener('hris:session-expired', () => employeeEvents++)
  adapter((config) => fail(config, 401))
  await assert.rejects(httpModule.http.get('/applicant/applications'))
  assert.equal(applicantEvents, 1)
  assert.equal(employeeEvents, 0)
  assert.equal(auth.user.id, 9)
  await assert.rejects(httpModule.http.get('/recruitment/applications'))
  assert.equal(employeeEvents, 1)
})

test('applicant takeover revalidation expires only applicant data and deduplicates simultaneous checks', async () => {
  applicant.user = candidate()
  applicant.applications = [application()]
  const pending = deferred()
  let events = 0
  let employeeEvents = 0
  window.addEventListener('hris:applicant-session-expired', () => events++)
  window.addEventListener('hris:session-expired', () => employeeEvents++)
  adapter(() => pending.promise)
  const first = applicant.revalidateSession()
  const second = applicant.revalidateSession()
  await setImmediate()
  assert.equal(calls.length, 1)
  assert.equal(calls[0].url, '/applicant/auth/me')
  try {
    fail(calls[0], 401)
  } catch (failure) {
    pending.reject(failure)
  }
  await Promise.all([first, second])
  assert.equal(applicant.authenticated, false)
  assert.deepEqual(applicant.applications, [])
  assert.equal(auth.user.id, 9)
  assert.equal(events, 1)
  assert.equal(employeeEvents, 0)
})

test('applicant revalidation preserves the current session on success and network errors', async () => {
  applicant.user = candidate()
  adapter((config) => response(config, { data: candidate() }))
  assert.equal(await applicant.revalidateSession(), true)
  adapter((config) => {
    throw new AxiosError('Offline', AxiosError.ERR_NETWORK, config)
  })
  assert.equal(await applicant.revalidateSession(), false)
  assert.equal(applicant.user.id, 1)
  assert.equal(applicant.authenticated, true)
})

test('late takeover checks cannot clear a fresh applicant login for the same account', async () => {
  applicant.user = candidate()
  const pending = deferred()
  let events = 0
  window.addEventListener('hris:applicant-session-expired', () => events++)
  adapter((config) =>
    config.url.endsWith('/me') ? pending.promise : response(config, { data: candidate() }),
  )
  const old = applicant.revalidateSession()
  await setImmediate()
  const previous = calls[0]
  applicant.clearSession()
  await applicant.signIn({ email: 'candidate-1@example.test', password: 'Test-password' })
  try {
    fail(previous, 401)
  } catch (failure) {
    pending.reject(failure)
  }
  await old
  assert.equal(applicant.user.id, 1)
  assert.equal(applicant.authenticated, true)
  assert.equal(events, 0)
})

test('a stale applicant 401 cannot expire a newer applicant session', async () => {
  const pending = deferred()
  let events = 0
  window.addEventListener('hris:applicant-session-expired', () => events++)
  adapter(() => pending.promise)
  const request = httpModule.http.get('/applicant/applications')
  await setImmediate()
  httpModule.resetAuthSession('applicant')
  try {
    fail(calls[0], 401)
  } catch (failure) {
    pending.reject(failure)
  }
  await assert.rejects(request)
  assert.equal(events, 0)
})

test('current employee logout clears applicant realm but stale employee logout cannot clear a newer employee', async () => {
  let events = 0
  window.addEventListener('hris:employee-logout', () => events++)
  adapter((config) => response(config, '', 204))
  await httpModule.http.post('/auth/logout')
  assert.equal(events, 1)
  const pending = deferred()
  adapter((config) => (config.url === '/auth/logout' ? pending.promise : response(config)))
  const logout = httpModule.http.post('/auth/logout')
  await setImmediate()
  const logoutConfig = calls.at(-1)
  httpModule.resetAuthSession()
  pending.resolve(response(logoutConfig, '', 204))
  await logout
  assert.equal(events, 1)
})

test('delayed employee logout cannot clear an applicant session established after its request', async () => {
  const pending = deferred()
  let events = 0
  window.addEventListener('hris:employee-logout', () => events++)
  adapter((config) =>
    config.url === '/auth/logout' ? pending.promise : response(config, { data: candidate(2) }),
  )
  const logout = httpModule.http.post('/auth/logout')
  await setImmediate()
  const logoutConfig = calls.at(-1)
  await applicant.signIn({ email: 'candidate-2@example.test', password: 'Test-password' })
  pending.resolve(response(logoutConfig, '', 204))
  await logout
  assert.equal(events, 0)
  assert.equal(applicant.user.id, 2)
})

test('applicant logout clears only applicant records and preserves employee authentication', async () => {
  applicant.user = candidate()
  applicant.applications = [application()]
  applicant.attempt = attempt()
  adapter((config) => response(config, '', 204))
  await applicant.signOut()
  assert.equal(applicant.user, null)
  assert.deepEqual(applicant.applications, [])
  assert.equal(applicant.attempt, null)
  assert.equal(auth.authenticated, true)
  assert.equal(auth.user.id, 9)
})

test('new applicant sessions do not cancel an employee mutation waiting for employee CSRF', async () => {
  const pending = deferred()
  httpModule.http.defaults.adapter = (config) => {
    calls.push(config)
    if (config.url === '/auth/csrf-cookie') return pending.promise
    return Promise.resolve(response(config, {}))
  }
  const employeeRequest = httpModule.http.post('/recruitment/applications/1/approve')
  await setImmediate()
  const bootstrap = calls[0]
  httpModule.resetAuthSession('applicant')
  pending.resolve(response(bootstrap, '', 204))
  await employeeRequest
  assert.equal(calls.at(-1).url, '/recruitment/applications/1/approve')
})

test('applicant pages preserve pagination and do not retain records after switching identities', async () => {
  applicant.user = candidate()
  adapter((config) =>
    response(
      config,
      config.url.includes('/interviews')
        ? { data: [] }
        : { data: [application()], meta: { current_page: 2, last_page: 3, total: 31 } },
    ),
  )
  await applicant.loadApplications(2)
  assert.equal(calls[0].params.page, 2)
  assert.equal(applicant.pagination.total, 31)
  assert.equal(applicant.hasActiveApplication, true)
  applicant.user = candidate(2)
  assert.deepEqual(applicant.applications, [])
  assert.deepEqual(applicant.interviews, {})
  assert.equal(applicant.pagination.current_page, 1)
})

test('late applicant records cannot overwrite a newer account', async () => {
  applicant.user = candidate()
  const pending = deferred()
  adapter(() => pending.promise)
  const loading = applicant.loadApplications()
  await setImmediate()
  const config = calls[0]
  applicant.user = candidate(2)
  pending.resolve(response(config, { data: [application()] }))
  await loading
  assert.deepEqual(applicant.applications, [])
  assert.equal(applicant.loading, false)
})

test('applicant application submission blocks duplicate active applications and surfaces field errors', async () => {
  applicant.user = candidate()
  applicant.applications = [application()]
  assert.equal(await applicant.apply({ job_opening_id: 5 }), null)
  assert.equal(calls.length, 0)
  applicant.applications = []
  adapter((config) => fail(config, 422, { job_opening_id: ['Position is required.'] }))
  assert.equal(await applicant.apply({ job_opening_id: null }), null)
  assert.deepEqual(applicant.fieldErrors.job_opening_id, ['Position is required.'])
  assert.equal(applicant.saving, false)
})

test('exam selection discards a late answer set belonging to the previously selected exam', async () => {
  applicant.user = candidate()
  const pending = deferred()
  adapter((config) =>
    config.url.endsWith('/3') ? pending.promise : response(config, { data: attempt(4) }),
  )
  const first = applicant.loadExam(3)
  await setImmediate()
  const config = calls[0]
  await applicant.loadExam(4)
  pending.resolve(response(config, { data: attempt(3) }))
  await first
  assert.equal(applicant.attempt.id, 4)
})

test('only server-approved exam attempts can submit and submitted results stay unreleased until graded', async () => {
  applicant.user = candidate()
  applicant.attempt = attempt(3, { abilities: { submit: false } })
  assert.equal(await applicant.submitExam([]), false)
  assert.equal(calls.length, 0)
  applicant.attempt = attempt()
  adapter((config) =>
    response(config, {
      data: attempt(3, {
        status: 'submitted',
        score: null,
        percentage: null,
        grades: null,
        abilities: { submit: false },
      }),
    }),
  )
  assert.equal(
    await applicant.submitExam([{ question_id: 'written-question', answer: 'Explanation' }]),
    true,
  )
  assert.equal(applicant.attempt.status, 'submitted')
  assert.equal(applicant.attempt.score, null)
  assert.equal(await applicant.submitExam([]), false)
})

test('late exam submission cannot overwrite the next exam after selection changes', async () => {
  applicant.user = candidate()
  applicant.attempt = attempt()
  const pending = deferred()
  adapter((config) =>
    config.url.endsWith('/submit') ? pending.promise : response(config, { data: attempt(4) }),
  )
  const submission = applicant.submitExam([
    { question_id: 'written-question', answer: 'Explanation' },
  ])
  await setImmediate()
  const config = calls.at(-1)
  await applicant.loadExam(4)
  pending.resolve(response(config, { data: attempt(3, { status: 'graded' }) }))
  assert.equal(await submission, false)
  assert.equal(applicant.attempt.id, 4)
  assert.equal(applicant.saving, false)
})

test('staff permission denial prevents application actions and exam creation before HTTP', async () => {
  auth.user = employee()
  auth.user.permissions = ['recruitment.view']
  recruitment.selected = application(10, { abilities: { approve: false, hire: false } })
  assert.equal(await recruitment.applicationAction('approve'), false)
  assert.equal(await recruitment.createExam({}), false)
  assert.equal(calls.length, 0)
})

test('staff selected record epochs reject a late application action response', async () => {
  recruitment.selected = application(10)
  const pending = deferred()
  adapter((config) =>
    config.method === 'post'
      ? pending.promise
      : response(
          config,
          config.url.includes('/interviews') ? { data: [] } : { data: application(11) },
        ),
  )
  const operation = recruitment.applicationAction('exams', { recruitment_exam_id: 5 })
  await setImmediate()
  const config = calls.at(-1)
  await recruitment.selectApplication(11)
  pending.resolve(response(config, { data: attempt() }))
  assert.equal(await operation, false)
  assert.equal(recruitment.selected.id, 11)
})

test('written grade submission preserves the final attempt while refreshing server hire ability', async () => {
  recruitment.selected = application(10)
  recruitment.attempt = attempt(3, { status: 'submitted', abilities: { grade: true } })
  adapter((config) =>
    response(config, {
      data:
        config.method === 'post'
          ? attempt(3, {
              status: 'graded',
              score: 7.5,
              percentage: 75,
              grades: [{ question_id: 'written-question', score: 7.5 }],
              abilities: { grade: false },
            })
          : application(10, { abilities: { hire: true } }),
    }),
  )
  assert.equal(
    await recruitment.gradeAttempt([
      { question_id: 'written-question', score: 7.5, feedback: 'Clear explanation.' },
    ]),
    true,
  )
  assert.equal(recruitment.attempt.status, 'graded')
  assert.equal(recruitment.attempt.score, 7.5)
  assert.equal(recruitment.selected.abilities.hire, true)
  assert.deepEqual(JSON.parse(calls.find((config) => config.url.endsWith('/grade')).data), {
    grades: [{ question_id: 'written-question', score: 7.5, feedback: 'Clear explanation.' }],
  })
})

test('staff exam catalogs include every API page and active interviewer options', async () => {
  adapter((config) =>
    response(
      config,
      config.url.endsWith('/interviewers')
        ? { data: [{ id: 9, name: 'Reviewer' }] }
        : config.params?.page === 2
          ? { data: [{ id: 101, title: 'Latest exam', is_active: true }] }
          : { data: [{ id: 1, title: 'First exam', is_active: true }], meta: { last_page: 2 } },
    ),
  )
  await recruitment.loadCatalogs()
  assert.deepEqual(
    recruitment.exams.map((exam) => exam.id),
    [1, 101],
  )
  assert.equal(recruitment.interviewers[0].id, 9)
  assert.ok(calls.some((config) => config.params?.page === 2))
})

test('employee identity changes clear recruitment drafts and suppress late catalogs', async () => {
  recruitment.selected = application()
  recruitment.attempt = attempt()
  const pending = deferred()
  adapter(() => pending.promise)
  const loading = recruitment.loadCatalogs()
  await setImmediate()
  auth.user = employee(10)
  pending.resolve(response(calls[0], { data: [] }))
  await loading
  assert.equal(recruitment.selected, null)
  assert.equal(recruitment.attempt, null)
  assert.deepEqual(recruitment.exams, [])
})

test('hiring sends only the confirmed start date and remarks without creating employee records', async () => {
  recruitment.selected = application(10, { abilities: { hire: true } })
  adapter((config) =>
    response(
      config,
      config.url.endsWith('/interviews')
        ? { data: [] }
        : {
            data: application(10, {
              status: 'hired',
              start_date: '2026-11-02',
              abilities: { hire: false },
            }),
          },
    ),
  )
  assert.equal(
    await recruitment.applicationAction('hire', { start_date: '2026-11-02', remarks: 'Welcome.' }),
    true,
  )
  const post = calls.find((config) => config.method === 'post')
  assert.equal(post.url, '/recruitment/applications/10/hire')
  assert.deepEqual(JSON.parse(post.data), { start_date: '2026-11-02', remarks: 'Welcome.' })
  assert.equal(recruitment.selected.start_date, '2026-11-02')
  assert.equal(
    calls.some((config) => config.url.includes('/employees')),
    false,
  )
  assert.equal(await recruitment.applicationAction('hire', {}), false)
})

test('terminal interview responses surface their server error and cannot silently update application state', async () => {
  recruitment.selected = application(10)
  adapter((config) => fail(config, 409))
  assert.equal(
    await recruitment.interviewAction({ id: 4, abilities: { complete: true } }, 'complete'),
    false,
  )
  assert.equal(recruitment.error, 'Request rejected.')
  assert.equal(recruitment.selected.id, 10)
  assert.equal(recruitment.busy, false)
})

test('late final grading cannot overwrite an assessment selected after grading began', async () => {
  recruitment.selected = application(10)
  recruitment.attempt = attempt(3, { status: 'submitted', abilities: { grade: true } })
  const pending = deferred()
  adapter((config) =>
    config.method === 'post'
      ? pending.promise
      : response(config, { data: attempt(4, { abilities: { grade: false } }) }),
  )
  const grading = recruitment.gradeAttempt([{ question_id: 'written-question', score: 8 }])
  await setImmediate()
  const config = calls.at(-1)
  await recruitment.loadAttempt(4)
  pending.resolve(response(config, { data: attempt(3, { status: 'graded' }) }))
  assert.equal(await grading, false)
  assert.equal(recruitment.attempt.id, 4)
  assert.equal(recruitment.busy, false)
})

test('an inactive applicant session offers scoped logout without affecting the employee account', async () => {
  adapter((config) => (config.url.endsWith('/me') ? fail(config, 403) : response(config, '', 204)))
  await applicant.initialize()
  assert.equal(applicant.authenticated, false)
  assert.equal(applicant.sessionBlocked, true)
  assert.match(applicant.connectionError, /inactive/)
  assert.equal(auth.user.id, 9)
  await applicant.signOut()
  assert.equal(applicant.sessionBlocked, false)
  assert.equal(applicant.connectionError, '')
  assert.equal(auth.authenticated, true)
  assert.equal(calls.at(-1).url, '/applicant/auth/logout')
})
