import test, { before, beforeEach, afterEach } from 'node:test'
import assert from 'node:assert/strict'
import { registerHooks } from 'node:module'
import { setImmediate } from 'node:timers/promises'
import { createPinia, setActivePinia } from 'pinia'
import { AxiosError } from 'axios'
import { deferred } from './helpers/component-harness.mjs'
import { jobOpeningPayload } from '../src/utils/recruitment.js'

let httpModule
let useJobOpeningsStore
let useRecruitmentStore
let useAuthStore
let useApplicantStore
let openings
let recruitment
let auth
let applicant
let calls
let previousWindow
const employee = (id = 9) => ({
  id,
  is_active: true,
  permissions: ['recruitment.view', 'recruitment.manage'],
  roles: ['hr_staff'],
})
const opening = (id = 5, overrides = {}) => ({
  id,
  title: 'Analyst',
  description: 'Review data.',
  requirements: null,
  location: null,
  employment_type: null,
  monthly_salary: '25000.00',
  salary_currency: 'PHP',
  vacancies: 1,
  status: 'open',
  closes_on: null,
  accepting_applications: true,
  ...overrides,
})
function response(config, data, status = 200) {
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
  ;({ useJobOpeningsStore } = await import('../src/stores/jobOpenings.js'))
  ;({ useRecruitmentStore } = await import('../src/stores/recruitment.js'))
  ;({ useAuthStore } = await import('../src/stores/auth.js'))
  ;({ useApplicantStore } = await import('../src/stores/applicant.js'))
  hook.deregister()
})
beforeEach(() => {
  previousWindow = globalThis.window
  globalThis.window = new EventTarget()
  httpModule.resetAuthSession('employee')
  httpModule.resetAuthSession('applicant')
  setActivePinia(createPinia())
  auth = useAuthStore()
  recruitment = useRecruitmentStore()
  openings = useJobOpeningsStore()
  applicant = useApplicantStore()
  calls = []
  adapter((config) => {
    throw new Error(`Unexpected ${config.url}`)
  })
})
afterEach(() => {
  openings.$dispose()
  recruitment.$dispose()
  applicant.$dispose()
  auth.$dispose()
  setActivePinia(undefined)
  if (previousWindow === undefined) delete globalThis.window
  else globalThis.window = previousWindow
})

test('anonymous careers listing preserves pagination and search without authenticating an employee', async () => {
  adapter((config) =>
    response(config, { data: [opening(101)], meta: { current_page: 2, last_page: 3, total: 31 } }),
  )
  await openings.loadOpenings(2, ' Analyst ')
  assert.equal(calls[0].url, '/careers/job-openings')
  assert.deepEqual(calls[0].params, { page: 2, per_page: 15, search: 'Analyst' })
  assert.equal(calls[0].authScope, 'applicant')
  assert.equal(calls[0].skipAuthEvent, true)
  assert.equal(auth.authenticated, false)
  assert.equal(openings.pagination.current_page, 2)
  assert.equal(openings.openings[0].id, 101)
})

test('public listing failures do not expire an applicant or employee session', async () => {
  const events = []
  window.addEventListener('hris:session-expired', () => events.push('employee'))
  window.addEventListener('hris:applicant-session-expired', () => events.push('applicant'))
  adapter((config) => response(config, { data: [opening()], meta: { current_page: 2, total: 20 } }))
  await openings.loadOpenings(2)
  adapter((config) => fail(config, 401))
  await openings.loadOpenings()
  assert.deepEqual(events, [])
  assert.equal(openings.loading, false)
  assert.ok(openings.error)
  assert.deepEqual(openings.openings, [])
  assert.equal(openings.pagination.total, 0)
})

test('an older careers page cannot replace the latest search result', async () => {
  const pending = deferred()
  adapter((config) =>
    config.params.search === 'old'
      ? pending.promise
      : response(config, { data: [opening(6)], meta: { total: 1 } }),
  )
  const first = openings.loadOpenings(1, 'old')
  await setImmediate()
  await openings.loadOpenings(1, 'new')
  pending.resolve(response(calls[0], { data: [opening(5)] }))
  await first
  assert.equal(openings.openings[0].id, 6)
  assert.equal(openings.error, '')
  assert.equal(calls[0].signal.aborted, true)
})

test('applicant position choices include every public catalogue page and exclude unavailable results', async () => {
  adapter((config) =>
    response(
      config,
      config.params.page === 1
        ? { data: [opening(5)], meta: { last_page: 2 } }
        : { data: [opening(101), opening(102, { accepting_applications: false })] },
    ),
  )
  await openings.loadOptions()
  assert.deepEqual(
    openings.options.map((item) => item.id),
    [5, 101],
  )
  assert.deepEqual(
    calls.map((call) => call.params.page),
    [1, 2],
  )
  assert.ok(calls.every((call) => call.params.per_page === 100))
})

test('failed catalogue refresh removes obsolete choices instead of retaining selectable positions', async () => {
  openings.options = [opening()]
  adapter((config) => fail(config, 503))
  assert.equal(await openings.loadOptions(), null)
  assert.deepEqual(openings.options, [])
  assert.equal(openings.optionsError, 'Request rejected.')
  assert.equal(openings.optionsLoading, false)
})

test('a cancelled catalogue request cannot replace freshly loaded position options', async () => {
  const pending = deferred()
  let count = 0
  adapter((config) => (++count === 1 ? pending.promise : response(config, { data: [opening(6)] })))
  const older = openings.loadOptions()
  await setImmediate()
  await openings.loadOptions()
  pending.resolve(response(calls[0], { data: [opening(5)] }))
  await older
  assert.equal(openings.options[0].id, 6)
})

test('a selected opening beyond the listing page resolves through its public detail endpoint', async () => {
  openings.openings = [opening(5)]
  adapter((config) => response(config, { data: opening(101, { requirements: 'Vue experience.' }) }))
  const result = await openings.loadOpening('101')
  assert.equal(calls[0].url, '/careers/job-openings/101')
  assert.equal(result.id, 101)
  assert.equal(openings.selected.requirements, 'Vue experience.')
  assert.equal(openings.openings[0].id, 5)
})

test('malformed selected positions never issue public detail requests', async () => {
  for (const id of ['../1', '1/2', '0', '1.5', ['1', '2'], '9007199254740992']) {
    assert.equal(await openings.loadOpening(id), null)
    assert.match(openings.selectionError, /unavailable/)
  }
  assert.deepEqual(calls, [])
})

test('a closed or expired selected position clears its previous details and explains the missing choice', async () => {
  openings.selected = opening(5)
  adapter((config) => fail(config, 404))
  assert.equal(await openings.loadOpening(5), null)
  assert.equal(openings.selected, null)
  assert.match(openings.selectionError, /no longer accepting/)
})

test('a stale selected detail and clearing selection cannot restore an older position', async () => {
  const pending = deferred()
  adapter((config) =>
    config.url.endsWith('/5') ? pending.promise : response(config, { data: opening(6) }),
  )
  const older = openings.loadOpening(5)
  await setImmediate()
  await openings.loadOpening(6)
  pending.resolve(response(calls[0], { data: opening(5) }))
  await older
  assert.equal(openings.selected.id, 6)
  openings.clearSelection()
  assert.equal(openings.selected, null)
  assert.equal(openings.selectionError, '')
})

test('staff opening list includes closed postings with separate filters and pagination', async () => {
  auth.user = employee()
  adapter((config) =>
    response(config, {
      data: [opening(5, { status: 'closed', accepting_applications: false })],
      meta: { current_page: 2, total: 20 },
    }),
  )
  await recruitment.loadJobOpenings({ status: 'closed', search: ' Manila ' }, 2)
  assert.equal(calls[0].url, '/recruitment/job-openings')
  assert.deepEqual(calls[0].params, { page: 2, per_page: 15, status: 'closed', search: 'Manila' })
  assert.equal(recruitment.jobOpenings[0].status, 'closed')
  assert.equal(recruitment.openingPagination.current_page, 2)
})

test('failed staff opening refresh clears obsolete rows and pagination', async () => {
  auth.user = employee()
  adapter((config) => response(config, { data: [opening()], meta: { current_page: 2, total: 20 } }))
  await recruitment.loadJobOpenings({}, 2)
  adapter((config) => fail(config, 503))
  await recruitment.loadJobOpenings({ search: 'new' })
  assert.deepEqual(recruitment.jobOpenings, [])
  assert.equal(recruitment.openingPagination.total, 0)
  assert.equal(recruitment.openingsError, 'Request rejected.')
})

test('staff create and update permission denials prevent opening mutations before HTTP', async () => {
  auth.user = employee()
  auth.user.permissions = ['recruitment.view']
  assert.equal(await recruitment.saveJobOpening({}), null)
  auth.user.permissions = ['recruitment.view', 'recruitment.manage']
  assert.equal(
    await recruitment.saveJobOpening({}, opening(5, { abilities: { update: false } })),
    null,
  )
  auth.user = null
  await recruitment.loadJobOpenings()
  assert.deepEqual(calls, [])
})

test('staff posts a full position and updates its status without changing application history', async () => {
  auth.user = employee()
  const payload = jobOpeningPayload({
    title: ' Analyst ',
    description: ' Review data. ',
    requirements: ' ',
    location: ' Manila ',
    employment_type: ' Regular ',
    monthly_salary: ' 9999999999999.99 ',
    vacancies: '2',
    status: 'open',
    closes_on: '',
  })
  adapter((config) =>
    response(config, {
      data: opening(5, { ...JSON.parse(config.data), abilities: { update: true } }),
    }),
  )
  const created = await recruitment.saveJobOpening(payload)
  assert.equal(created.id, 5)
  const updated = await recruitment.saveJobOpening({ ...payload, status: 'closed' }, created)
  const mutations = calls.filter((call) => ['post', 'put'].includes(call.method))
  assert.deepEqual(
    mutations.map((call) => [call.method, call.url]),
    [
      ['post', '/recruitment/job-openings'],
      ['put', '/recruitment/job-openings/5'],
    ],
  )
  assert.deepEqual(JSON.parse(mutations[0].data), {
    title: 'Analyst',
    description: 'Review data.',
    requirements: null,
    location: 'Manila',
    employment_type: 'Regular',
    monthly_salary: '9999999999999.99',
    vacancies: 2,
    status: 'open',
    closes_on: null,
  })
  assert.equal(updated.status, 'closed')
  assert.equal(recruitment.openingsBusy, false)
})

test('opening validation errors preserve their fields and do not overwrite application errors', async () => {
  auth.user = employee()
  recruitment.error = 'Application error'
  recruitment.fieldErrors = { remarks: ['Keep these.'] }
  adapter((config) => fail(config, 422, { title: ['A position title is required.'] }))
  assert.equal(await recruitment.saveJobOpening({ title: '' }), null)
  assert.deepEqual(recruitment.openingErrors, { title: ['A position title is required.'] })
  assert.equal(recruitment.error, 'Application error')
  assert.deepEqual(recruitment.fieldErrors, { remarks: ['Keep these.'] })
})

test('late staff opening writes cannot restore results or errors after an account switch', async () => {
  auth.user = employee()
  const pending = deferred()
  adapter(() => pending.promise)
  const request = recruitment.saveJobOpening(jobOpeningPayload(opening()))
  await setImmediate()
  auth.user = employee(10)
  pending.resolve(response(calls.at(-1), { data: opening() }))
  assert.equal(await request, null)
  assert.deepEqual(recruitment.jobOpenings, [])
  assert.equal(recruitment.openingsBusy, false)
  assert.equal(recruitment.openingsError, '')
})

test('a late staff opening page cannot replace results for a newer account or search', async () => {
  auth.user = employee()
  const pending = deferred()
  adapter((config) =>
    config.params.search === 'old' ? pending.promise : response(config, { data: [opening(6)] }),
  )
  const older = recruitment.loadJobOpenings({ search: 'old' })
  await setImmediate()
  auth.user = employee(10)
  await recruitment.loadJobOpenings({ search: 'new' })
  pending.resolve(response(calls[0], { data: [opening(5)] }))
  await older
  assert.equal(recruitment.jobOpenings[0].id, 6)
  assert.equal(recruitment.openingsLoading, false)
})

test('applicant application sends only the chosen opening and cover letter, leaving the title to the server', async () => {
  applicant.user = { id: 1, is_active: true }
  adapter((config) =>
    config.method === 'post'
      ? response(
          config,
          {
            data: {
              id: 10,
              job_opening_id: 5,
              desired_position: 'Server title',
              monthly_salary: '25000.00',
              salary_currency: 'PHP',
            },
          },
          201,
        )
      : response(config, { data: [] }),
  )
  const created = await applicant.apply({
    job_opening_id: 5,
    cover_letter: 'My experience.',
    desired_position: 'Spoofed title',
    monthly_salary: '1.00',
    salary_currency: 'USD',
    status: 'hired',
  })
  const post = calls.find((call) => call.url === '/applicant/applications')
  assert.deepEqual(JSON.parse(post.data), { job_opening_id: 5, cover_letter: 'My experience.' })
  assert.equal(created.desired_position, 'Server title')
  assert.equal(created.monthly_salary, '25000.00')
  assert.equal(created.salary_currency, 'PHP')
})
