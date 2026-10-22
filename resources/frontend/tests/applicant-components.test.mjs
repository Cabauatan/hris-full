import test from 'node:test'
import assert from 'node:assert/strict'
import * as vue from 'vue'
import { apiError } from '../src/api/http.js'
import * as recruitmentUtils from '../src/utils/recruitment.js'
import { labelFor, statusSeverity } from '../src/utils/forms.js'
import { deferred, httpFailure, mountComponent, settle } from './helpers/component-harness.mjs'

const questions = [
  {
    id: 'mcq-id',
    type: 'multiple_choice',
    prompt: 'Choose',
    options: ['First', 'Second'],
    points: 5,
  },
  { id: 'written-id', type: 'written', prompt: 'Explain', points: 10 },
]
async function mount(context, path, stores, options = {}) {
  const redirects = []
  const route = vue.reactive({ query: options.query || {}, params: { attemptId: '3' } })
  const view = await mountComponent(
    `views/${path}.vue`,
    {
      'vue-router': {
        useRoute: () => route,
        useRouter: () => ({ replace: async (value) => redirects.push(value) }),
      },
      '@/stores/applicant': { useApplicantStore: () => stores.applicant },
      '@/stores/auth': { useAuthStore: () => stores.auth },
      '@/stores/recruitment': { useRecruitmentStore: () => stores.recruitment },
      '@/stores/jobOpenings': { useJobOpeningsStore: () => stores.openings || jobOpeningsStore() },
      '@/api/http': { apiError },
      '@/utils/recruitment': recruitmentUtils,
      '@/utils/forms': { labelFor, statusSeverity },
    },
    options.props,
  )
  context.after(() => view.app.unmount())
  return { ...view, route, redirects }
}
function applicantStore(overrides = {}) {
  return vue.reactive({
    user: { id: 1, first_name: 'Candidate' },
    authenticated: true,
    error: '',
    fieldErrors: {},
    applications: [],
    pagination: { current_page: 1, total: 0 },
    interviews: {},
    hasActiveApplication: false,
    loadApplications() {},
    loadExam() {},
    ...overrides,
  })
}
function recruitmentStore(overrides = {}) {
  return vue.reactive({
    selected: null,
    attempt: null,
    canManage: true,
    loading: false,
    busy: false,
    error: '',
    fieldErrors: {},
    pagination: { current_page: 1, total: 0 },
    applications: [],
    interviews: [],
    exams: [],
    interviewers: [],
    loadApplications() {},
    loadCatalogs() {},
    ...overrides,
  })
}
function jobOpeningsStore(overrides = {}) {
  return vue.reactive({
    options: [{ id: 5, title: 'Analyst', accepting_applications: true }],
    openings: [],
    pagination: { current_page: 1, total: 0 },
    selected: null,
    selectionError: '',
    optionsError: '',
    optionsLoading: false,
    selectionLoading: false,
    clearSelection() {},
    async loadOptions() {
      return this.options
    },
    async loadOpening(id) {
      return this.options.find((opening) => opening.id === Number(id)) || null
    },
    loadOpenings() {},
    ...overrides,
  })
}

test('registration component sends profile/contact fields without employee credentials or storing passwords', async (context) => {
  const calls = []
  const applicant = applicantStore({ register: async (payload) => calls.push(payload) })
  const view = await mount(
    context,
    'ApplicantAuthView',
    { applicant },
    { props: { mode: 'register' } },
  )
  Object.assign(view.state.form.value, {
    first_name: '  Candidate ',
    middle_name: '  ',
    last_name: ' Example ',
    email: ' candidate@example.test ',
    mobile_number: ' 09990000000 ',
    password: ' Test-password ',
    password_confirmation: ' Test-password ',
  })
  await view.state.submit()
  assert.deepEqual(calls, [
    {
      first_name: 'Candidate',
      middle_name: null,
      last_name: 'Example',
      email: 'candidate@example.test',
      mobile_number: '09990000000',
      password: ' Test-password ',
      password_confirmation: ' Test-password ',
    },
  ])
  assert.equal(view.state.form.value.password, '')
  assert.equal(view.state.form.value.password_confirmation, '')
  assert.deepEqual(view.redirects, ['/applicant/applications'])
})

test('applicant sign-in uses email and limits redirects to applicant destinations', async (context) => {
  const calls = []
  const applicant = applicantStore({ signIn: async (payload) => calls.push(payload) })
  const view = await mount(
    context,
    'ApplicantAuthView',
    { applicant },
    { props: { mode: 'login' }, query: { redirect: '/recruitment' } },
  )
  Object.assign(view.state.form.value, {
    email: ' candidate@example.test ',
    password: 'Test-password',
    remember: true,
  })
  await view.state.submit()
  assert.deepEqual(calls, [
    { email: 'candidate@example.test', password: 'Test-password', remember: true },
  ])
  assert.deepEqual(view.redirects, ['/applicant/applications'])
})

test('applicant sign-in prevents duplicate submits and preserves field errors for a retry', async (context) => {
  const pending = deferred()
  let calls = 0
  const applicant = applicantStore({
    signIn: () => {
      calls++
      return pending.promise
    },
  })
  const view = await mount(
    context,
    'ApplicantAuthView',
    { applicant },
    { props: { mode: 'login' } },
  )
  const first = view.state.submit()
  await view.state.submit()
  assert.equal(calls, 1)
  assert.equal(view.state.busy.value, true)
  pending.reject(httpFailure(422, 'Check your email.', { email: ['Invalid credentials.'] }))
  await first
  assert.deepEqual(view.state.errors.value, { email: ['Invalid credentials.'] })
  assert.equal(view.state.busy.value, false)
  assert.deepEqual(view.redirects, [])
})

test('applicant login opens its applications after a successful session takeover', async (context) => {
  let logins = 0
  const applicant = applicantStore({
    signIn: async () => {
      logins += 1
    },
  })
  const view = await mount(
    context,
    'ApplicantAuthView',
    { applicant },
    { props: { mode: 'login' } },
  )
  Object.assign(view.state.form.value, {
    email: 'candidate@example.test',
    password: 'Test-password',
  })

  await view.state.submit()

  assert.equal(logins, 1)
  assert.equal(view.state.error.value, '')
  assert.deepEqual(view.state.errors.value, {})
  assert.deepEqual(view.redirects, ['/applicant/applications'])
  assert.equal(view.state.busy.value, false)
})

test('application component sends the selected opening ID and clears drafts when the applicant changes', async (context) => {
  const calls = []
  const applicant = applicantStore({
    apply: async (payload) => {
      calls.push(payload)
      return { id: 10 }
    },
  })
  const view = await mount(context, 'ApplicantApplicationsView', { applicant })
  Object.assign(view.state.draft.value, {
    job_opening_id: 5,
    cover_letter: ' I would like to apply. ',
  })
  await view.state.submit()
  assert.deepEqual(calls, [{ job_opening_id: 5, cover_letter: 'I would like to apply.' }])
  assert.equal(view.state.draft.value.job_opening_id, null)
  view.state.draft.value.cover_letter = 'Private draft'
  applicant.user = { id: 2, first_name: 'Another' }
  await settle()
  assert.equal(view.state.draft.value.cover_letter, '')
  assert.equal(view.state.success.value, '')
})

test('application preselects a deep linked opening and shows its public details', async (context) => {
  const selected = {
    id: 101,
    title: 'Engineer',
    description: 'Build tools.',
    requirements: 'Vue experience.',
    accepting_applications: true,
  }
  const calls = []
  const openings = jobOpeningsStore({
    options: [selected],
    async loadOpening(id) {
      calls.push(id)
      return selected
    },
  })
  const view = await mount(
    context,
    'ApplicantApplicationsView',
    { applicant: applicantStore(), openings },
    { query: { opening: '101' } },
  )
  assert.deepEqual(calls, ['101'])
  assert.equal(view.state.draft.value.job_opening_id, 101)
  assert.equal(view.state.selectedOpening.value.requirements, 'Vue experience.')
  assert.equal(view.state.selectedOpening.value.description, 'Build tools.')
})

test('application refuses an arbitrary or loading position before calling submission', async (context) => {
  let calls = 0
  const openings = jobOpeningsStore()
  const view = await mount(context, 'ApplicantApplicationsView', {
    applicant: applicantStore({
      apply: async () => {
        calls += 1
      },
    }),
    openings,
  })
  view.state.draft.value.job_opening_id = 999
  await view.state.submit()
  assert.equal(calls, 0)
  assert.match(view.state.selectionWarning.value, /Choose an open position/)
  view.state.draft.value.job_opening_id = 5
  openings.optionsLoading = true
  await view.state.submit()
  assert.equal(calls, 0)
})

test('closed position submission refreshes choices and preserves the applicant cover letter', async (context) => {
  const openings = jobOpeningsStore()
  const applicant = applicantStore({
    async apply() {
      this.fieldErrors = { job_opening_id: ['This position is closed.'] }
      this.error = 'This position is closed.'
      return null
    },
  })
  const view = await mount(context, 'ApplicantApplicationsView', { applicant, openings })
  view.state.draft.value = { job_opening_id: 5, cover_letter: 'My experience matters.' }
  openings.loadOptions = async () => {
    openings.options = []
    return []
  }
  await view.state.submit()
  assert.equal(view.state.draft.value.job_opening_id, null)
  assert.equal(view.state.draft.value.cover_letter, 'My experience matters.')
  assert.match(view.state.selectionWarning.value, /no longer available/)
})

test('a late preselected position cannot replace a newer route selection', async (context) => {
  const first = deferred()
  const second = { id: 6, title: 'New position', accepting_applications: true }
  const openings = jobOpeningsStore({
    options: [{ id: 5, accepting_applications: true }, second],
    loadOpening: (id) => (id === '5' ? first.promise : Promise.resolve(second)),
  })
  const view = await mount(
    context,
    'ApplicantApplicationsView',
    { applicant: applicantStore(), openings },
    { query: { opening: '5' } },
  )
  view.route.query.opening = '6'
  await settle()
  assert.equal(view.state.draft.value.job_opening_id, 6)
  first.resolve({ id: 5, accepting_applications: true })
  await settle()
  assert.equal(view.state.draft.value.job_opening_id, 6)
})

test('choosing an available position clears an outdated deep-link warning', async (context) => {
  const openings = jobOpeningsStore({ selectionError: 'This position is closed.' })
  const view = await mount(context, 'ApplicantApplicationsView', {
    applicant: applicantStore(),
    openings,
  })
  view.state.selectionWarning.value = 'Choose another position.'
  openings.selectionError = 'This position is closed.'
  view.state.draft.value.job_opening_id = 5
  await settle()
  assert.equal(openings.selectionError, '')
  assert.equal(view.state.selectionWarning.value, '')
})

test('careers links applicants to the chosen position through registration or their active session', async (context) => {
  const applicant = applicantStore({ authenticated: false, initialize() {} })
  const calls = []
  const openings = jobOpeningsStore({ loadOpenings: (...args) => calls.push(args) })
  const view = await mount(context, 'CareersView', { applicant, openings })
  assert.deepEqual(calls, [[]])
  assert.deepEqual(view.state.applyRoute({ id: 101 }), {
    path: '/applicant/register',
    query: { redirect: '/applicant/applications?opening=101' },
  })
  applicant.authenticated = true
  assert.equal(view.state.applyRoute({ id: 101 }), '/applicant/applications?opening=101')
})

test('applicant auth mode links and successful authentication retain the safe chosen position', async (context) => {
  const applicant = applicantStore({ register: async () => {}, signIn: async () => {} })
  const view = await mount(
    context,
    'ApplicantAuthView',
    { applicant },
    {
      props: { mode: 'register' },
      query: { redirect: '/applicant/applications?opening=101' },
    },
  )
  assert.deepEqual(view.state.alternateAuthRoute.value, {
    path: '/applicant/login',
    query: { redirect: '/applicant/applications?opening=101' },
  })
  await view.state.submit()
  view.props.mode = 'login'
  await settle()
  assert.deepEqual(view.state.alternateAuthRoute.value, {
    path: '/applicant/register',
    query: { redirect: '/applicant/applications?opening=101' },
  })
  await view.state.submit()
  assert.deepEqual(view.redirects, [
    '/applicant/applications?opening=101',
    '/applicant/applications?opening=101',
  ])
  view.route.query.redirect = '/recruitment'
  assert.equal(view.state.alternateAuthRoute.value.query.redirect, '/applicant/applications')
})

test('HR position editor sends full public fields and closes its saved draft', async (context) => {
  const calls = []
  const recruitment = vue.reactive({
    canManage: true,
    openingPagination: { current_page: 1 },
    loadJobOpenings() {},
    async saveJobOpening(payload, opening) {
      calls.push({ payload, opening })
      return { ...payload, id: 5 }
    },
  })
  const view = await mountComponent('components/JobOpeningsPanel.vue', {
    '@/stores/auth': { useAuthStore: () => vue.reactive({ user: { id: 9 }, authenticated: true }) },
    '@/stores/recruitment': { useRecruitmentStore: () => recruitment },
    '@/utils/recruitment': recruitmentUtils,
  })
  context.after(() => view.app.unmount())
  view.state.beginEdit()
  Object.assign(view.state.draft.value, {
    title: ' Analyst ',
    description: ' Review data. ',
    requirements: ' ',
    location: ' Manila ',
    employment_type: ' Regular ',
    monthly_salary: ' 9999999999999.99 ',
    vacancies: 2,
    closes_on: '2026-12-01',
  })
  await view.state.save()
  assert.deepEqual(calls, [
    {
      payload: {
        title: 'Analyst',
        description: 'Review data.',
        requirements: null,
        location: 'Manila',
        employment_type: 'Regular',
        monthly_salary: '9999999999999.99',
        vacancies: 2,
        status: 'open',
        closes_on: '2026-12-01',
      },
      opening: null,
    },
  ])
  assert.equal(view.state.visible.value, false)
  const existing = {
    id: 5,
    title: 'Analyst',
    description: 'Review data.',
    vacancies: 2,
    monthly_salary: null,
    status: 'open',
    abilities: { update: true },
  }
  view.state.beginEdit(existing)
  assert.equal(view.state.draft.value.monthly_salary, '')
  view.state.draft.value.monthly_salary = '25000.01'
  view.state.draft.value.status = 'closed'
  await view.state.save()
  assert.equal(calls[1].opening.id, 5)
  assert.equal(calls[1].payload.status, 'closed')
  assert.equal(calls[1].payload.monthly_salary, '25000.01')
  assert.match(view.state.success.value, /closed/)
})

test('HR opening permissions and account changes protect and discard editor drafts', async (context) => {
  const auth = vue.reactive({ user: { id: 9 }, authenticated: true })
  const recruitment = vue.reactive({ canManage: false, loadJobOpenings() {} })
  const view = await mountComponent('components/JobOpeningsPanel.vue', {
    '@/stores/auth': { useAuthStore: () => auth },
    '@/stores/recruitment': { useRecruitmentStore: () => recruitment },
    '@/utils/recruitment': recruitmentUtils,
  })
  context.after(() => view.app.unmount())
  view.state.beginEdit()
  assert.equal(view.state.visible.value, false)
  recruitment.canManage = true
  view.state.beginEdit({ id: 5, abilities: { update: false } })
  assert.equal(view.state.visible.value, false)
  view.state.beginEdit()
  view.state.draft.value.title = 'Private draft'
  auth.user = { id: 10 }
  await settle()
  assert.equal(view.state.visible.value, false)
  assert.equal(view.state.draft.value.title, '')
})

test('exam component requires all answers and explicit confirmation, including zero-index MCQ answers', async (context) => {
  const calls = []
  const applicant = applicantStore({
    attempt: { id: 3, questions, abilities: { submit: true } },
    submitExam: async (payload) => {
      calls.push(payload)
      return true
    },
  })
  const view = await mount(context, 'ApplicantExamView', { applicant })
  view.state.answers.value = { 'mcq-id': '0', 'written-id': ' Explanation ' }
  assert.equal(view.state.complete.value, true)
  await view.state.submit()
  assert.equal(calls.length, 0)
  view.state.confirmation.value = true
  await view.state.submit()
  assert.deepEqual(calls, [
    [
      { question_id: 'mcq-id', answer: '0' },
      { question_id: 'written-id', answer: 'Explanation' },
    ],
  ])
  assert.deepEqual(view.state.answers.value, {})
  assert.equal(view.state.confirmation.value, false)
})

test('exam component hides partial grades and refuses submissions after server ability is removed', async (context) => {
  let calls = 0
  const applicant = applicantStore({
    attempt: {
      id: 3,
      status: 'submitted',
      questions,
      score: 5,
      percentage: 33.33,
      max_score: 15,
      grades: [{ question_id: 'mcq-id', score: 5 }],
      abilities: { submit: false },
    },
    submitExam: async () => {
      calls++
      return true
    },
  })
  const view = await mount(context, 'ApplicantExamView', { applicant })
  view.state.answers.value = { 'mcq-id': '0', 'written-id': 'Explanation' }
  view.state.confirmation.value = true
  assert.equal(view.state.result.value, null)
  assert.equal(view.state.grade(questions[0]), null)
  await view.state.submit()
  assert.equal(calls, 0)
  applicant.attempt.status = 'graded'
  applicant.attempt.score = 12.5
  applicant.attempt.percentage = 83.33
  assert.equal(view.state.result.value, '12.5 / 15 · 83.33%')
  assert.equal(view.state.grade(questions[0]).score, 5)
})

test('changing exam routes clears unsent answers and confirmation before loading the next exam', async (context) => {
  const loaded = []
  const applicant = applicantStore({ loadExam: (id) => loaded.push(id) })
  const view = await mount(context, 'ApplicantExamView', { applicant })
  view.state.answers.value = { 'written-id': 'Private answer' }
  view.state.confirmation.value = true
  view.route.params.attemptId = '4'
  await settle()
  assert.deepEqual(loaded, ['3', '4'])
  assert.deepEqual(view.state.answers.value, {})
  assert.equal(view.state.confirmation.value, false)
})

test('assessment editor removes answer-key metadata from written questions and keeps MCQ option zero valid', async (context) => {
  const calls = []
  const recruitment = recruitmentStore({
    createExam: async (payload) => {
      calls.push(payload)
      return true
    },
  })
  const view = await mount(context, 'RecruitmentWorkspace', {
    auth: vue.reactive({ user: { id: 9 }, authenticated: true }),
    recruitment,
  })
  view.state.examDraft.value = {
    title: ' Mixed exam ',
    instructions: ' Instructions ',
    passing_percentage: 65.5,
    questions: [
      {
        key: 1,
        type: 'multiple_choice',
        prompt: ' Choose ',
        points: 5,
        options: [' First ', ' Second '],
        correct_option: 0,
      },
      {
        key: 2,
        type: 'written',
        prompt: ' Explain ',
        points: 10,
        options: ['Old', 'Options'],
        correct_option: 1,
      },
    ],
  }
  await view.state.createExam()
  assert.deepEqual(calls, [
    {
      title: 'Mixed exam',
      instructions: 'Instructions',
      passing_percentage: 65.5,
      is_active: true,
      questions: [
        {
          type: 'multiple_choice',
          prompt: 'Choose',
          points: 5,
          options: ['First', 'Second'],
          correct_option: 0,
        },
        { type: 'written', prompt: 'Explain', points: 10 },
      ],
    },
  ])
  assert.equal(view.state.examVisible.value, false)
})

test('question editor maintains the selected correct answer after removing an earlier option', async (context) => {
  const view = await mount(context, 'RecruitmentWorkspace', {
    auth: vue.reactive({ user: { id: 9 }, authenticated: true }),
    recruitment: recruitmentStore(),
  })
  const question = { options: ['A', 'B', 'C'], correct_option: 2 }
  view.state.removeOption(question, 0)
  assert.deepEqual(question.options, ['B', 'C'])
  assert.equal(question.correct_option, 1)
  view.state.removeOption(question, 0)
  assert.equal(question.options.length, 2)
})

test('grading form submits exactly the written questions with fractional score and optional feedback', async (context) => {
  const calls = []
  const recruitment = recruitmentStore({
    gradeAttempt: async (grades) => {
      calls.push(grades)
      return true
    },
  })
  const view = await mount(context, 'RecruitmentWorkspace', {
    auth: vue.reactive({ user: { id: 9 }, authenticated: true }),
    recruitment,
  })
  recruitment.selected = { id: 10 }
  recruitment.attempt = { id: 3, status: 'submitted', questions, abilities: { grade: true } }
  await settle()
  assert.equal(view.state.gradesComplete.value, false)
  view.state.gradeDraft.value['written-id'] = { score: 7.5, feedback: ' Clear explanation. ' }
  view.state.gradeDraft.value['mcq-id'] = { score: 99, feedback: 'Must be omitted' }
  assert.equal(view.state.gradesComplete.value, true)
  await view.state.submitGrades()
  assert.deepEqual(calls, [
    [{ question_id: 'written-id', score: 7.5, feedback: 'Clear explanation.' }],
  ])
})

test('grading form refuses missing, negative and over-maximum manual scores', async (context) => {
  let calls = 0
  const recruitment = recruitmentStore({
    gradeAttempt: async () => {
      calls++
      return true
    },
  })
  const view = await mount(context, 'RecruitmentWorkspace', {
    auth: vue.reactive({ user: { id: 9 }, authenticated: true }),
    recruitment,
  })
  recruitment.attempt = { id: 3, status: 'submitted', questions, abilities: { grade: true } }
  await settle()
  for (const score of [null, -1, 11, NaN]) {
    view.state.gradeDraft.value['written-id'] = { score }
    await view.state.submitGrades()
  }
  assert.equal(calls, 0)
})

test('interview form converts local datetime to second-precision UTC with exact interviewer IDs', async (context) => {
  const calls = []
  const recruitment = recruitmentStore({
    applicationAction: async (action, payload) => {
      calls.push({ action, payload })
      return true
    },
  })
  const view = await mount(context, 'RecruitmentWorkspace', {
    auth: vue.reactive({ user: { id: 9 }, authenticated: true }),
    recruitment,
  })
  view.state.action.value = 'interviews'
  view.state.schedule.value = {
    round: 'initial',
    scheduled_at: '2026-10-08T09:30:00+08:00',
    duration_minutes: 45,
    interviewer_ids: [9, 10],
    private_notes: ' Screening interview ',
  }
  await view.state.performAction()
  assert.deepEqual(calls, [
    {
      action: 'interviews',
      payload: {
        round: 'initial',
        scheduled_at: '2026-10-08T01:30:00Z',
        duration_minutes: 45,
        interviewer_ids: [9, 10],
        private_notes: 'Screening interview',
      },
    },
  ])
})

test('staff account switches discard grading, exam and selected-action drafts', async (context) => {
  const auth = vue.reactive({ user: { id: 9 }, authenticated: true })
  const recruitment = recruitmentStore()
  const view = await mount(context, 'RecruitmentWorkspace', { auth, recruitment })
  view.state.examVisible.value = true
  view.state.examDraft.value.title = 'Private draft'
  auth.user = { id: 10 }
  await settle()
  assert.equal(view.state.examVisible.value, false)
  assert.equal(view.state.examDraft.value.title, '')
  recruitment.selected = { id: 10 }
  await settle()
  view.state.actionDraft.value.remarks = 'Private notes'
  view.state.schedule.value.private_notes = 'Private interview notes'
  view.state.action.value = 'hire'
  recruitment.selected = { id: 11 }
  await settle()
  assert.equal(view.state.action.value, '')
  assert.equal(view.state.actionDraft.value.remarks, '')
  assert.equal(view.state.schedule.value.private_notes, '')
})

test('applicant redirect accepts owned portal paths and rejects employee, external, traversal and malformed paths', () => {
  assert.equal(
    recruitmentUtils.applicantRedirect('/applicant/exams/3?review=1'),
    '/applicant/exams/3?review=1',
  )
  for (const value of [
    '//other.example.test',
    '/dashboard',
    '/applicant/login',
    '/applicant/exams/3/../login',
    '/applicant/exams/3%5c',
    '/applicant/exams/3%',
  ])
    assert.equal(recruitmentUtils.applicantRedirect(value), '/applicant/applications')
})

test('final grades require completed grading and numeric final results, preserving a zero grade', () => {
  assert.equal(
    recruitmentUtils.finalResult({ status: 'submitted', score: 10, percentage: 100 }),
    null,
  )
  assert.equal(
    recruitmentUtils.finalResult({ status: 'graded', score: undefined, percentage: undefined }),
    null,
  )
  assert.equal(
    recruitmentUtils.finalResult({ status: 'graded', score: 0, max_score: 10, percentage: 0 }),
    '0 / 10 · 0%',
  )
})

test('inactive-session recovery uses applicant logout and returns to applicant sign-in', async (context) => {
  let signOuts = 0
  const applicant = applicantStore({
    sessionBlocked: true,
    signOut: async () => {
      signOuts++
    },
  })
  const view = await mount(
    context,
    'ApplicantAuthView',
    { applicant },
    { props: { mode: 'login' } },
  )
  await view.state.signOutBlockedSession()
  assert.equal(signOuts, 1)
  assert.deepEqual(view.redirects, ['/applicant/login'])
  assert.equal(view.state.busy.value, false)
})

test('reopening recruitment initializes empty grading inputs for an already selected submitted attempt', async (context) => {
  const recruitment = recruitmentStore({
    attempt: { id: 3, status: 'submitted', questions, abilities: { grade: true } },
  })
  const view = await mount(context, 'RecruitmentWorkspace', {
    auth: vue.reactive({ user: { id: 9 }, authenticated: true }),
    recruitment,
  })
  assert.deepEqual(view.state.gradeDraft.value, { 'written-id': { score: null, feedback: '' } })
  assert.equal(view.state.gradesComplete.value, false)
})

test('reused applicant auth view clears passwords and field errors when switching between login and registration', async (context) => {
  const applicant = applicantStore({
    signIn: async () => {
      throw httpFailure(422, 'Login failed.', { email: ['Invalid credentials.'] })
    },
    register: async () => {
      throw httpFailure(422, 'Registration failed.', { password: ['Confirmation does not match.'] })
    },
  })
  const view = await mount(
    context,
    'ApplicantAuthView',
    { applicant },
    { props: { mode: 'login' } },
  )
  Object.assign(view.state.form.value, {
    email: 'candidate@example.test',
    password: 'Login-password',
    remember: true,
  })
  await view.state.submit()
  assert.equal(view.state.error.value, 'Login failed.')

  view.props.mode = 'register'
  await settle()
  assert.equal(view.state.registering.value, true)
  assert.equal(view.state.form.value.password, '')
  assert.equal(view.state.form.value.password_confirmation, '')
  assert.equal(view.state.form.value.remember, false)
  assert.equal(view.state.form.value.email, 'candidate@example.test')
  assert.equal(view.state.error.value, '')
  assert.deepEqual(view.state.errors.value, {})

  Object.assign(view.state.form.value, {
    password: 'New-password',
    password_confirmation: 'Confirmation-password',
  })
  await view.state.submit()
  assert.equal(view.state.error.value, 'Registration failed.')
  view.props.mode = 'login'
  await settle()
  assert.equal(view.state.registering.value, false)
  assert.equal(view.state.form.value.password, '')
  assert.equal(view.state.form.value.password_confirmation, '')
  assert.equal(view.state.error.value, '')
  assert.deepEqual(view.state.errors.value, {})
})
