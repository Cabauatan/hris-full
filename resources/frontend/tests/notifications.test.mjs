import test, { before, beforeEach, afterEach } from 'node:test'
import assert from 'node:assert/strict'
import { registerHooks } from 'node:module'
import { setImmediate } from 'node:timers/promises'
import { createPinia, setActivePinia } from 'pinia'
import { AxiosError } from 'axios'
import { notificationLink } from '../src/utils/notifications.js'
import { navigationGroups } from '../src/config/navigation.js'

let httpModule
let useAuthStore
let useNotificationsStore
let auth
let inbox
let calls
let timers
let previousGlobals

const firstId = '211bf060-03ce-4ba9-971c-7cf7c1c27dc0'
const secondId = 'd2695c94-753b-4396-bb68-ed79607e4dc2'

function user(id = 12) {
  return {
    id,
    is_active: true,
    permissions: ['leave.view', 'payslips.view', 'announcements.view'],
    employee: { id: 22 },
  }
}
function item(id = firstId, overrides = {}) {
  return {
    id,
    title: 'Leave approved',
    message: 'Your request was approved.',
    category: 'request',
    event: 'approved',
    read_at: null,
    created_at: '2026-10-04T10:00:00+08:00',
    source: { module: 'leave', id: 42, scope: 'mine' },
    ...overrides,
  }
}
function page(rows = [item()], overrides = {}) {
  return {
    data: rows,
    meta: {
      current_page: 1,
      last_page: 1,
      total: rows.length,
      unread_count: rows.filter((row) => !row.read_at).length,
      ...overrides,
    },
  }
}
function response(config, data = {}, status = 200) {
  return { data, status, statusText: String(status), headers: {}, config, request: {} }
}
function deferred() {
  let resolve
  const promise = new Promise((yes) => {
    resolve = yes
  })
  return { promise, resolve }
}
function adapter(handler) {
  httpModule.http.defaults.adapter = async (config) => {
    if (config.url === '/auth/csrf-cookie') return response(config, '', 204)
    calls.push(config)
    return handler(config)
  }
}
async function settle() {
  await setImmediate()
  await setImmediate()
}

before(async () => {
  const hooks = registerHooks({
    resolve(specifier, context, nextResolve) {
      if (specifier.startsWith('@/'))
        return nextResolve(
          new URL(
            `../src/${specifier.slice(2)}${specifier.endsWith('.js') ? '' : '.js'}`,
            import.meta.url,
          ).href,
          context,
        )
      return nextResolve(specifier, context)
    },
  })
  try {
    httpModule = await import('../src/api/http.js')
    ;({ useAuthStore } = await import('../src/stores/auth.js'))
    ;({ useNotificationsStore } = await import('../src/stores/notifications.js'))
  } finally {
    hooks.deregister()
  }
})

beforeEach(() => {
  previousGlobals = {
    window: globalThis.window,
    document: globalThis.document,
    setInterval: globalThis.setInterval,
    clearInterval: globalThis.clearInterval,
  }
  globalThis.window = new EventTarget()
  globalThis.document = new EventTarget()
  globalThis.document.hidden = false
  timers = new Map()
  let timerId = 0
  globalThis.setInterval = (callback, delay) => {
    const id = ++timerId
    timers.set(id, { callback, delay })
    return id
  }
  globalThis.clearInterval = (id) => timers.delete(id)
  calls = []
  httpModule.resetCsrf()
  setActivePinia(createPinia())
  auth = useAuthStore()
  auth.user = user()
  inbox = useNotificationsStore()
  adapter((config) => {
    throw new Error(`Unexpected ${config.method} ${config.url}`)
  })
})

afterEach(() => {
  inbox?.$dispose()
  auth?.$dispose()
  setActivePinia(undefined)
  httpModule.resetCsrf()
  for (const [key, value] of Object.entries(previousGlobals)) {
    if (value === undefined) delete globalThis[key]
    else globalThis[key] = value
  }
})

test('inbox unread filter and pagination are server-side with authoritative total', async () => {
  adapter((config) =>
    response(
      config,
      page(config.params.page === 1 ? [item()] : [item(secondId)], {
        current_page: config.params.page,
        last_page: 2,
        total: 25,
        unread_count: 25,
      }),
    ),
  )
  await inbox.load({ filter: 'unread' })
  await inbox.load({ append: true })
  assert.deepEqual(
    calls.map(({ url, params }) => ({ url, params })),
    [
      { url: '/notifications', params: { filter: 'unread', page: 1, per_page: 20 } },
      { url: '/notifications', params: { filter: 'unread', page: 2, per_page: 20 } },
    ],
  )
  assert.equal(inbox.items.length, 2)
  assert.equal(inbox.total, 25)
  assert.equal(inbox.unreadCount, 25)
  assert.equal(inbox.hasMore, false)
})

test('switching filter clears old records and ignores an older in-flight list', async () => {
  const delayed = deferred()
  adapter((config) =>
    config.params.filter === 'all' ? delayed.promise : response(config, page([item(secondId)])),
  )
  const old = inbox.load()
  await settle()
  await inbox.load({ filter: 'unread' })
  delayed.resolve(response(calls[0], page([item()])))
  await old
  assert.equal(inbox.filter, 'unread')
  assert.deepEqual(
    inbox.items.map(({ id }) => id),
    [secondId],
  )
  assert.equal(inbox.loading, false)
})

test('logout immediately clears cached inbox and prevents late list or count restoration', async () => {
  adapter((config) => response(config, page()))
  await inbox.load()
  const list = deferred()
  const unread = deferred()
  adapter((config) => (config.url.endsWith('unread-count') ? unread.promise : list.promise))
  const pendingList = inbox.load()
  const pendingCount = inbox.refreshUnreadCount()
  await settle()
  const pendingCalls = [...calls].slice(-2)
  auth.clearSession()
  assert.deepEqual(inbox.items, [])
  assert.equal(inbox.unreadCount, null)
  list.resolve(response(pendingCalls[0], page()))
  unread.resolve(response(pendingCalls[1], { data: { unread_count: 99 } }))
  await Promise.all([pendingList, pendingCount])
  assert.deepEqual(inbox.items, [])
  assert.equal(inbox.unreadCount, null)
  assert.equal(inbox.error, '')
})

test('account change discards a completed old read mutation without fetching the new account inbox', async () => {
  adapter((config) => response(config, page()))
  await inbox.load()
  const write = deferred()
  adapter(() => write.promise)
  const pending = inbox.markRead(inbox.items[0])
  await settle()
  auth.user = user(13)
  assert.deepEqual(inbox.items, [])
  assert.equal(inbox.markingId, null)
  const writeConfig = calls.at(-1)
  write.resolve(
    response(writeConfig, {
      data: item(firstId, { read_at: '2026-10-04T10:10:00+08:00' }),
      meta: { unread_count: 0 },
    }),
  )
  await pending
  assert.deepEqual(inbox.items, [])
  assert.equal(inbox.unreadCount, null)
  assert.equal(calls.at(-1).method, 'patch')
})

test('older count response cannot overwrite a newer inbox unread count', async () => {
  const old = deferred()
  adapter((config) =>
    config.url.endsWith('unread-count')
      ? old.promise
      : response(config, page([item()], { unread_count: 4 })),
  )
  const pending = inbox.refreshUnreadCount()
  await settle()
  await inbox.load()
  old.resolve(response(calls[0], { data: { unread_count: 15 } }))
  await pending
  assert.equal(inbox.unreadCount, 4)
})

test('older inbox response may populate records but cannot overwrite a newer unread count', async () => {
  const old = deferred()
  adapter((config) =>
    config.url === '/notifications' ? old.promise : response(config, { data: { unread_count: 9 } }),
  )
  const pending = inbox.load()
  await settle()
  await inbox.refreshUnreadCount()
  old.resolve(response(calls[0], page([item()], { unread_count: 15 })))
  await pending
  assert.equal(inbox.items.length, 1)
  assert.equal(inbox.unreadCount, 9)
})

test('mark read uses server timestamp and count and invalidates a pending poll', async () => {
  const timestamp = '2026-10-04T11:22:33+08:00'
  const oldPoll = deferred()
  let read = false
  adapter((config) => {
    if (config.url.endsWith('unread-count')) return oldPoll.promise
    if (config.method === 'patch') {
      read = true
      return response(config, {
        data: item(firstId, { read_at: timestamp }),
        meta: { unread_count: 0 },
      })
    }
    return response(config, page([item(firstId, { read_at: read ? timestamp : null })]))
  })
  await inbox.load()
  const poll = inbox.refreshUnreadCount()
  await settle()
  await inbox.markRead(inbox.items[0])
  oldPoll.resolve(
    response(
      calls.find(({ url }) => url.endsWith('unread-count')),
      { data: { unread_count: 8 } },
    ),
  )
  await poll
  assert.equal(inbox.items[0].read_at, timestamp)
  assert.equal(inbox.unreadCount, 0)
  assert.ok(
    calls.some(({ method, url }) => method === 'patch' && url === `/notifications/${firstId}/read`),
  )
  assert.equal(inbox.mutating, false)
})

test('mark all read refreshes selected unread filter and uses server counts', async () => {
  let read = false
  adapter((config) => {
    if (config.method === 'patch') {
      read = true
      return response(config, { data: { updated: 6, unread_count: 0 } })
    }
    return response(
      config,
      page(read ? [] : [item()], { total: read ? 0 : 6, unread_count: read ? 0 : 6 }),
    )
  })
  await inbox.load({ filter: 'unread' })
  await inbox.markAllRead()
  assert.deepEqual(inbox.items, [])
  assert.equal(inbox.total, 0)
  assert.equal(inbox.unreadCount, 0)
  assert.ok(
    calls.some(({ method, url }) => method === 'patch' && url === '/notifications/read-all'),
  )
  assert.equal(calls.at(-1).params.filter, 'unread')
})

test('failed read action leaves unread notification intact and exposes retry error', async () => {
  adapter((config) => {
    if (config.method === 'patch') {
      const failed = response(config, { message: 'Please retry later.' }, 503)
      throw new AxiosError('Unavailable', AxiosError.ERR_BAD_RESPONSE, config, {}, failed)
    }
    return response(config, page())
  })
  await inbox.load()
  await inbox.markRead(inbox.items[0])
  assert.equal(inbox.items[0].read_at, null)
  assert.equal(inbox.unreadCount, 1)
  assert.equal(inbox.error, 'Please retry later.')
  assert.equal(inbox.mutating, false)
})

test('read action rejects invalid UUID paths without a request', async () => {
  for (const id of ['../read-all', firstId + '/read', '', null]) await inbox.markRead(item(id))
  assert.equal(calls.length, 0)
})

test('polling runs once per visible minute, pauses while hidden and stops on layout removal', async () => {
  adapter((config) => response(config, { data: { unread_count: 3 } }))
  inbox.startPolling()
  inbox.startPolling()
  await settle()
  assert.equal(timers.size, 1)
  const timer = [...timers.values()][0]
  assert.equal(timer.delay, 60000)
  assert.equal(calls.length, 1)
  document.hidden = true
  timer.callback()
  window.dispatchEvent(new Event('focus'))
  await settle()
  assert.equal(calls.length, 1)
  document.hidden = false
  document.dispatchEvent(new Event('visibilitychange'))
  await settle()
  assert.equal(calls.length, 2)
  timer.callback()
  await settle()
  assert.equal(calls.length, 3)
  inbox.stopPolling()
  assert.equal(timers.size, 0)
  document.dispatchEvent(new Event('visibilitychange'))
  window.dispatchEvent(new Event('focus'))
  timer.callback()
  await settle()
  assert.equal(calls.length, 3)
})

test('polling makes no unauthenticated requests and resumes for a new active account', async () => {
  auth.clearSession()
  adapter((config) => response(config, { data: { unread_count: 2 } }))
  inbox.startPolling()
  await settle()
  assert.equal(calls.length, 0)
  auth.user = user(13)
  await settle()
  assert.equal(calls.length, 1)
  assert.equal(inbox.unreadCount, 2)
  auth.clearSession()
  assert.equal(inbox.unreadCount, null)
  for (const { callback } of timers.values()) callback()
  await settle()
  assert.equal(calls.length, 1)
})

test('notification sources resolve only known permission-checked record links', () => {
  assert.deepEqual(notificationLink(item(), auth), {
    path: '/my/requests/leave',
    query: { record: '42' },
  })
  assert.equal(
    notificationLink(item(firstId, { source: { module: 'leave', id: 42, scope: 'visible' } }), auth)
      .path,
    '/requests/leave',
  )
  assert.equal(
    notificationLink(item(firstId, { source: { module: 'payslips', id: 42, scope: 'mine' } }), auth)
      .path,
    '/my/payslips',
  )
  assert.equal(
    notificationLink(
      item(firstId, { source: { module: 'announcements', id: 42, scope: 'visible' } }),
      auth,
    ).path,
    '/manage/announcements',
  )
  for (const source of [
    null,
    { module: 'users', id: 42, scope: 'visible' },
    { module: 'https://unsafe.test', id: 42, scope: 'visible' },
    { module: 'leave', id: '../42', scope: 'mine' },
    { module: 'leave', id: 42, scope: 'unexpected' },
  ])
    assert.equal(notificationLink(item(firstId, { source }), auth), null)
  auth.user = user(13)
  auth.user.employee = null
  assert.equal(notificationLink(item(), auth), null)
  auth.user.permissions = []
  assert.equal(notificationLink(item(), auth), null)
  assert.ok(navigationGroups(auth)[0].links.some(({ to }) => to === '/notifications'))
})

test('birthday notifications open the authorized coworker calendar without employee record access', () => {
  auth.user.permissions = []
  const birthday = item(firstId, {
    category: 'birthday',
    event: 'birthday',
    source: { module: 'calendar', scope: 'visible' },
  })
  assert.deepEqual(notificationLink(birthday, auth), {
    path: '/calendar',
    query: { scope: 'visible', types: 'birthday' },
  })
  for (const overrides of [
    { category: 'request' },
    { event: 'approved' },
    { source: { module: 'calendar', scope: 'mine' } },
    { source: { module: 'https://unsafe.test', scope: 'visible' } },
  ])
    assert.equal(notificationLink({ ...birthday, ...overrides }, auth), null)

  auth.user.employee = null
  assert.equal(notificationLink(birthday, auth), null)
  auth.user.permissions = ['employees.view-team']
  assert.equal(notificationLink(birthday, auth).path, '/calendar')
  auth.clearSession()
  assert.equal(notificationLink(birthday, auth), null)
})
