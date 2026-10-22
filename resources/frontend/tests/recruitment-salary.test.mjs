import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { compileScript, parse } from '@vue/compiler-sfc'
import * as records from '../src/utils/records.js'
import { renderToString } from '@vue/server-renderer'
import * as vue from 'vue'
import * as recruitmentUtils from '../src/utils/recruitment.js'
import { formatValue, labelFor, statusSeverity } from '../src/utils/forms.js'
import { evaluateModule, settle } from './helpers/component-harness.mjs'

const salary = '9999999999999.99'
const label = 'PHP 9,999,999,999,999.99 / month'
const opening = (overrides = {}) => ({
  id: 5,
  title: 'Analyst',
  description: 'Review data.',
  requirements: null,
  location: null,
  employment_type: null,
  monthly_salary: salary,
  salary_currency: 'PHP',
  vacancies: 1,
  status: 'open',
  accepting_applications: true,
  abilities: { update: true },
  ...overrides,
})
function applicantStore(overrides = {}) {
  return {
    user: { id: 1, first_name: 'Candidate' },
    authenticated: true,
    applications: [],
    pagination: { current_page: 1, total: 0 },
    fieldErrors: {},
    interviews: {},
    hasActiveApplication: false,
    loadApplications() {},
    ...overrides,
  }
}
function openingsStore(overrides = {}) {
  return {
    openings: [opening()],
    options: [opening()],
    pagination: { current_page: 1, total: 1 },
    clearSelection() {},
    loadOpenings() {},
    async loadOptions() {
      return this.options
    },
    async loadOpening() {
      return this.options[0]
    },
    ...overrides,
  }
}
function recruitmentStore(overrides = {}) {
  return {
    selected: null,
    attempt: null,
    applications: [],
    interviews: [],
    canManage: true,
    jobOpenings: [],
    openingErrors: {},
    openingPagination: { current_page: 1, total: 0 },
    pagination: { current_page: 1, total: 0 },
    loadJobOpenings() {},
    loadApplications() {},
    loadCatalogs() {},
    ...overrides,
  }
}
const wrapper = {
  props: ['label', 'value'],
  setup(props, { slots }) {
    return () => vue.h('span', slots.default?.() || props.label || props.value)
  },
}
const input = {
  props: ['modelValue'],
  setup(props, { attrs }) {
    return () => vue.h('input', { ...attrs, value: props.modelValue })
  },
}
const column = { name: 'SalaryTestColumn' }
const table = {
  props: ['value'],
  setup(props, { slots }) {
    return () =>
      vue.h(
        'table',
        (props.value || []).map((data) =>
          vue.h(
            'tr',
            (slots.default?.() || [])
              .filter((node) => node.type === column)
              .map((node) =>
                vue.h('td', node.children?.body?.({ data }) || data[node.props?.field]),
              ),
          ),
        ),
      )
  },
}

async function renderView(path, stores = {}, query = {}) {
  const { descriptor } = parse(
    await readFile(new URL(`../src/${path}.vue`, import.meta.url), 'utf8'),
  )
  const dependencies = new Proxy(
    {
      vue,
      'vue-router': { useRoute: () => ({ query }), useRouter: () => ({ replace() {} }) },
      '@/stores/auth': { useAuthStore: () => ({ user: { id: 9 }, authenticated: true }) },
      '@/stores/applicant': { useApplicantStore: () => stores.applicant || applicantStore() },
      '@/stores/jobOpenings': { useJobOpeningsStore: () => stores.openings || openingsStore() },
      '@/stores/recruitment': {
        useRecruitmentStore: () => stores.recruitment || recruitmentStore(),
      },
      '@/utils/recruitment': recruitmentUtils,
      '@/utils/forms': { formatValue, labelFor, statusSeverity },
      '@/utils/records': records,
      'primevue/inputtext': { default: input },
      'primevue/column': { default: column },
      'primevue/datatable': { default: table },
    },
    { get: (target, key) => target[key] || { default: wrapper } },
  )
  const component = evaluateModule(
    compileScript(descriptor, { id: path, inlineTemplate: true }).content,
    dependencies,
  )
  const originalSetup = component.setup
  component.setup = async (...args) => {
    const render = originalSetup(...args)
    await settle()
    return render
  }
  const app = vue.createSSRApp(component)
  app.component('RouterLink', wrapper)
  return renderToString(app)
}

test('salary formatting preserves exact monthly PHP cents including the maximum amount', () => {
  assert.equal(recruitmentUtils.formatMonthlySalary(salary), label)
  assert.equal(recruitmentUtils.formatMonthlySalary('25000.01'), 'PHP 25,000.01 / month')
  assert.equal(recruitmentUtils.formatMonthlySalary('0.01'), 'PHP 0.01 / month')
  assert.equal(recruitmentUtils.formatMonthlySalary('25000'), 'PHP 25,000.00 / month')
  for (const value of [null, undefined, '', '0.00', '1.001', '10000000000000.00', 25000]) {
    assert.equal(recruitmentUtils.formatMonthlySalary(value), 'Salary not recorded')
  }
})

test('posting salary payload retains decimal strings and leaves missing salary empty for required validation', () => {
  const draft = opening({ monthly_salary: ` ${salary} ` })
  assert.equal(recruitmentUtils.jobOpeningPayload(draft).monthly_salary, salary)
  assert.equal(
    recruitmentUtils.jobOpeningPayload(opening({ monthly_salary: null })).monthly_salary,
    '',
  )
  assert.equal(
    recruitmentUtils.jobOpeningPayload(opening({ monthly_salary: 25000 })).monthly_salary,
    '',
  )
})

test('actual careers cards render the exact advertised monthly PHP salary', async () => {
  const html = await renderView('views/CareersView')
  assert.ok(html.includes(label), html)
  assert.ok(html.includes('Analyst'), html)
})

test('actual selected-position details render its salary while applicant history keeps its own recorded amount', async () => {
  const applicant = applicantStore({
    applications: [
      {
        id: 10,
        desired_position: 'Previous analyst role',
        status: 'rejected',
        monthly_salary: '25000.01',
        salary_currency: 'PHP',
      },
    ],
  })
  const html = await renderView('views/ApplicantApplicationsView', { applicant }, { opening: '5' })
  assert.ok(html.includes(label), html)
  assert.ok(html.includes('PHP 25,000.01 / month'), html)
})

test('actual legacy applicant history states salary was not recorded instead of inventing zero', async () => {
  const applicant = applicantStore({
    applications: [
      {
        id: 10,
        desired_position: 'Legacy role',
        status: 'hired',
        monthly_salary: null,
        start_date: '2026-12-01',
      },
    ],
    hasActiveApplication: true,
  })
  const html = await renderView('views/ApplicantApplicationsView', { applicant })
  assert.ok(html.includes('Salary not recorded'), html)
  assert.equal(html.includes('PHP 0.00'), false)
})

test('actual HR opening table identifies missing salary and renders a required decimal-text salary field', async () => {
  const recruitment = recruitmentStore({
    jobOpenings: [opening({ monthly_salary: null, accepting_applications: false })],
  })
  const html = await renderView('components/JobOpeningsPanel', { recruitment })
  assert.ok(html.includes('Salary required'), html)
  assert.ok(html.includes('Fixed monthly salary (PHP)'), html)
  assert.match(html, /<input[^>]*inputmode="decimal"[^>]*required/)
  assert.equal(html.includes('Expired'), false)
})

test('actual HR application rows and detail show recorded salary independent of current opening salary', async () => {
  const selected = {
    id: 10,
    desired_position: 'Snapshot role',
    status: 'approved',
    monthly_salary: '25000.01',
    salary_currency: 'PHP',
    applicant: { name: 'Candidate' },
    abilities: {},
    attempts: [],
  }
  const recruitment = recruitmentStore({ selected, applications: [selected] })
  const html = await renderView('views/RecruitmentWorkspace', { recruitment })
  assert.equal(html.split('PHP 25,000.01 / month').length - 1, 2)
  assert.equal(html.includes(label), false)
})
