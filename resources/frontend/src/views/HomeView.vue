<script setup>
import { computed, onMounted, ref } from 'vue'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Skeleton from 'primevue/skeleton'
import Tag from 'primevue/tag'
import http, { apiError } from '@/api/http'
import { useAuthStore } from '@/stores/auth'
import { navigationGroups } from '@/config/navigation'
import { formatValue, statusSeverity } from '@/utils/forms'
import { columnValue } from '@/utils/records'
const auth = useAuthStore()
const announcements = ref([])
const leave = ref([])
const cto = ref([])
const busy = ref(true)
const errors = ref([])
const today = new Intl.DateTimeFormat('en', {
  weekday: 'long',
  month: 'long',
  day: 'numeric',
  year: 'numeric',
}).format(new Date())
const greeting =
  new Date().getHours() < 12
    ? 'Good morning'
    : new Date().getHours() < 18
      ? 'Good afternoon'
      : 'Good evening'
const name = computed(() => auth.employee?.first_name || auth.user?.name?.split(' ')[0] || 'there')
const shortcuts = computed(() => {
  const result = []
  if (auth.employee && auth.can('leave.create') && auth.can('leave.view'))
    result.push({
      to: '/my/requests/leave',
      title: 'Request leave',
      description: 'Plan time away from work.',
      icon: 'pi pi-calendar-plus',
      tone: 'emerald',
    })
  if (
    auth.employee &&
    auth.can('attendance-corrections.create') &&
    auth.can('attendance-corrections.view')
  )
    result.push({
      to: '/my/requests/attendance-corrections',
      title: 'Correct attendance',
      description: 'Keep your time records accurate.',
      icon: 'pi pi-clock',
      tone: 'blue',
    })
  if (auth.employee && auth.can('payslips.view'))
    result.push({
      to: '/my/payslips',
      title: 'View payslips',
      description: 'Access your published payslips.',
      icon: 'pi pi-wallet',
      tone: 'violet',
    })
  result.push({
    to: '/profile',
    title: 'My profile',
    description: 'Your account and employee details.',
    icon: 'pi pi-id-card',
    tone: 'amber',
  })
  return result
})
const administration = computed(() =>
  navigationGroups(auth)
    .filter((group) => !['Workspace', 'Self-service', 'My requests'].includes(group.title))
    .flatMap((group) => group.links)
    .slice(0, 6),
)
onMounted(async () => {
  const jobs = []
  function fetchInto(endpoint, target) {
    jobs.push(
      http
        .get(endpoint, { params: { per_page: 4 } })
        .then(({ data }) => {
          target.value = data.data
        })
        .catch((failure) => {
          errors.value.push(apiError(failure).message)
        }),
    )
  }
  if (auth.canAny(['announcements.view', 'announcements.manage']))
    fetchInto('/announcements/dashboard', announcements)
  if (auth.employee && auth.can('leave.view')) fetchInto('/ess/leave-balances', leave)
  if (auth.employee && auth.can('cto.view')) fetchInto('/ess/cto-balances', cto)
  await Promise.all(jobs)
  busy.value = false
})
</script>

<template>
  <div class="home-page">
    <div class="page-heading">
      <div>
        <span class="eyebrow">YOUR WORKSPACE</span>
        <h1>{{ greeting }}, {{ name }} <span class="greeting-dot">.</span></h1>
        <p>Everything you need for a more organized workday.</p>
      </div>
      <span class="today-label"><i class="pi pi-calendar" />{{ today }}</span>
    </div>
    <section class="welcome-banner">
      <div>
        <span class="banner-kicker">PEOPLE FIRST</span>
        <h2>Your workday, in one place.</h2>
        <p>Stay on top of your time, requests, and employee records.</p>
        <RouterLink to="/profile"
          ><Button
            label="View my profile"
            icon="pi pi-arrow-right"
            icon-pos="right"
            severity="contrast"
        /></RouterLink>
      </div>
      <div class="banner-art" aria-hidden="true">
        <div class="art-circle"><i class="pi pi-sitemap" /></div>
        <span class="art-orbit one"><i class="pi pi-check" /></span
        ><span class="art-orbit two"><i class="pi pi-calendar" /></span
        ><span class="art-orbit three"><i class="pi pi-users" /></span>
      </div>
    </section>
    <section aria-label="Quick actions" class="quick-grid">
      <RouterLink v-for="item in shortcuts" :key="item.to" :to="item.to" class="quick-card"
        ><span class="quick-icon" :class="item.tone"><i :class="item.icon" /></span
        ><span
          ><b>{{ item.title }}</b
          ><small>{{ item.description }}</small></span
        ><i class="pi pi-arrow-up-right"
      /></RouterLink>
    </section>
    <Message v-if="errors.length" severity="warn" :closable="false"
      >Some records could not be loaded. Refresh the page to try again.</Message
    >
    <div class="home-columns">
      <section class="panel announcement-panel">
        <div class="panel-heading">
          <div>
            <span class="eyebrow">FROM YOUR ORGANIZATION</span>
            <h2>Announcements</h2>
          </div>
          <i class="pi pi-megaphone" />
        </div>
        <div v-if="busy" class="skeleton-stack">
          <Skeleton height="5rem" /><Skeleton height="5rem" />
        </div>
        <div v-else-if="!announcements.length" class="empty-state">
          <span class="empty-icon"><i class="pi pi-megaphone" /></span><b>You're all caught up</b>
          <p>Published announcements will appear here.</p>
        </div>
        <article v-for="item in announcements.slice(0, 5)" :key="item.id" class="announcement-item">
          <div>
            <Tag
              v-if="item.priority !== 'normal'"
              :value="item.priority"
              :severity="statusSeverity(item.priority)"
            /><small>{{ formatValue(item.published_at, 'datetime') }}</small>
          </div>
          <h3>{{ item.title }}</h3>
          <p>{{ item.content }}</p>
        </article>
      </section>
      <div class="home-side">
        <section
          v-if="auth.employee && (auth.can('leave.view') || auth.can('cto.view'))"
          class="panel"
        >
          <div class="panel-heading">
            <div>
              <span class="eyebrow">YOUR AVAILABLE TIME</span>
              <h2>Leave & CTO credits</h2>
            </div>
            <i class="pi pi-calendar-clock" />
          </div>
          <Skeleton v-if="busy" height="6rem" /><template v-else
            ><div v-for="balance in leave" :key="balance.id" class="credit-row">
              <span
                >{{ columnValue(balance, 'leave_type_id')
                }}<small>{{ balance.year }} balance</small></span
              ><b>{{ balance.balance }} <small>units</small></b>
            </div>
            <div v-for="balance in cto" :key="balance.id" class="credit-row">
              <span>Compensatory time off</span
              ><b>{{ balance.balance_minutes }} <small>min</small></b>
            </div>
            <p v-if="!leave.length && !cto.length" class="muted">
              No credit balances are available yet.
            </p></template
          >
        </section>
        <section class="panel">
          <div class="panel-heading">
            <div>
              <span class="eyebrow">GET STARTED</span>
              <h2>{{ administration.length ? 'Workspace tools' : 'Employee essentials' }}</h2>
            </div>
          </div>
          <RouterLink v-for="link in administration" :key="link.to" :to="link.to" class="tool-link"
            ><i :class="link.icon" /><span>{{ link.label }}</span
            ><i class="pi pi-angle-right" /></RouterLink
          ><RouterLink v-if="!administration.length" to="/profile" class="tool-link"
            ><i class="pi pi-user" /><span>Profile & account security</span
            ><i class="pi pi-angle-right"
          /></RouterLink>
        </section>
      </div>
    </div>
  </div>
</template>
