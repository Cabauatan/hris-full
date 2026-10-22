import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useApplicantStore } from '@/stores/applicant'
import { resources } from '@/config/resources'
import { workflows } from '@/config/workflows'
import { selfService } from '@/config/selfService'
import { canViewCalendar } from '@/config/workspace'
import { reportPermissions } from '@/config/reports'
import { applicantRedirect } from '@/utils/recruitment'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  scrollBehavior: () => ({ top: 0 }),
  routes: [
    {
      path: '/careers',
      component: () => import('@/layout/ApplicantLayout.vue'),
      children: [
        {
          path: '',
          name: 'careers',
          component: () => import('@/views/CareersView.vue'),
          meta: { open: true, title: 'Careers' },
        },
      ],
    },
    {
      path: '/applicant',
      component: () => import('@/layout/ApplicantLayout.vue'),
      meta: { applicant: true },
      children: [
        { path: '', redirect: '/applicant/applications' },
        {
          path: 'login',
          name: 'applicant-login',
          component: () => import('@/views/ApplicantAuthView.vue'),
          props: { mode: 'login' },
          meta: { applicant: true, guestOnly: true, title: 'Applicant sign in' },
        },
        {
          path: 'register',
          name: 'applicant-register',
          component: () => import('@/views/ApplicantAuthView.vue'),
          props: { mode: 'register' },
          meta: { applicant: true, guestOnly: true, title: 'Apply' },
        },
        {
          path: 'applications',
          name: 'applicant-applications',
          component: () => import('@/views/ApplicantApplicationsView.vue'),
          meta: { applicant: true, title: 'My applications' },
        },
        {
          path: 'settings',
          name: 'applicant-settings',
          component: () => import('@/views/ApplicantSettingsView.vue'),
          meta: { applicant: true, title: 'Account settings' },
        },
        {
          path: 'exams/:attemptId',
          name: 'applicant-exam',
          component: () => import('@/views/ApplicantExamView.vue'),
          meta: { applicant: true, title: 'My assessment' },
        },
        {
          path: 'interviews/:interviewId',
          name: 'applicant-interview',
          component: () => import('@/views/InterviewRoomView.vue'),
          meta: { applicant: true, title: 'My interview' },
        },
      ],
    },
    {
      path: '/auth/login',
      name: 'login',
      component: () => import('@/views/auth/LoginView.vue'),
      meta: { public: true, title: 'Sign in' },
    },
    { path: '/login', redirect: '/auth/login' },
    {
      path: '/',
      component: () => import('@/layout/AppLayout.vue'),
      children: [
        { path: '', redirect: '/dashboard' },
        {
          path: 'recruitment',
          name: 'recruitment',
          component: () => import('@/views/RecruitmentWorkspace.vue'),
          meta: { title: 'Recruitment', viewPermissions: ['recruitment.view'] },
        },
        {
          path: 'recruitment/interviews/:interviewId',
          name: 'recruitment-interview',
          component: () => import('@/views/InterviewRoomView.vue'),
          meta: { title: 'Interview', viewPermissions: ['recruitment.view'] },
        },
        {
          path: 'dashboard',
          name: 'dashboard',
          component: () => import('@/views/DashboardView.vue'),
          meta: { title: 'Dashboard' },
        },
        {
          path: 'calendar',
          name: 'calendar',
          component: () => import('@/views/CalendarView.vue'),
          meta: { title: 'Calendar', calendar: true },
        },
        {
          path: 'home',
          name: 'home',
          component: () => import('@/views/HomeView.vue'),
          meta: { title: 'Home' },
        },
        {
          path: 'reports',
          name: 'reports',
          component: () => import('@/views/ReportsView.vue'),
          meta: { title: 'Reports', viewPermissions: reportPermissions },
        },
        {
          path: 'notifications',
          name: 'notifications',
          component: () => import('@/views/NotificationsView.vue'),
          meta: { title: 'Notifications' },
        },
        {
          path: 'profile',
          name: 'profile',
          component: () => import('@/views/ProfileView.vue'),
          meta: { title: 'My profile' },
        },
        {
          path: 'manage/:module',
          name: 'resource',
          component: () => import('@/views/ResourceWorkspace.vue'),
          meta: { kind: 'resource' },
        },
        {
          path: 'my/requests/:module',
          name: 'my-requests',
          component: () => import('@/views/WorkflowWorkspace.vue'),
          meta: { kind: 'workflow', ess: true },
        },
        {
          path: 'requests/:module',
          name: 'requests',
          component: () => import('@/views/WorkflowWorkspace.vue'),
          meta: { kind: 'workflow' },
        },
        {
          path: 'my/:module',
          name: 'self-service',
          component: () => import('@/views/ResourceWorkspace.vue'),
          meta: { kind: 'resource', ess: true },
        },
        {
          path: 'forbidden',
          name: 'forbidden',
          component: () => import('@/views/AccessStateView.vue'),
          props: { kind: 'forbidden' },
          meta: { title: 'Access restricted' },
        },
        {
          path: ':pathMatch(.*)*',
          name: 'not-found',
          component: () => import('@/views/AccessStateView.vue'),
          meta: { title: 'Page not found' },
        },
      ],
    },
  ],
})

router.beforeEach(async (to) => {
  if (to.meta.open) return true
  if (to.meta.applicant) {
    const applicant = useApplicantStore()
    await applicant.initialize()
    if (to.meta.guestOnly)
      return applicant.authenticated ? applicantRedirect(to.query.redirect) : true
    if (!applicant.authenticated)
      return { path: '/applicant/login', query: { redirect: to.fullPath } }
    return true
  }
  const auth = useAuthStore()
  await auth.initialize()
  if (to.meta.public) return auth.authenticated ? '/dashboard' : true
  if (!auth.authenticated) return { path: '/auth/login', query: { redirect: to.fullPath } }
  if (to.meta.calendar && !canViewCalendar(auth)) return '/forbidden'
  if (to.meta.viewPermissions && !auth.canAny(to.meta.viewPermissions)) return '/forbidden'
  if (to.meta.kind) {
    const entry =
      to.meta.kind === 'workflow'
        ? workflows[to.params.module]
        : (to.meta.ess ? selfService : resources)[to.params.module]
    if (!entry) return { name: 'not-found', params: { pathMatch: ['page-not-found'] } }
    const permissions =
      to.meta.kind === 'workflow' ? [`${entry.permissionPrefix}.view`] : entry.viewPermissions
    if (!auth.canAny(permissions) || (to.meta.ess && !auth.employee)) return '/forbidden'
    to.meta.title = entry.title
  }
  return true
})
router.afterEach((to) => {
  document.title = `${to.meta.title || 'Workspace'} · HRIS`
})
export default router
