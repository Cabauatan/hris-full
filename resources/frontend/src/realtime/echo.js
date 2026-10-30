import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { useAuthStore } from '@/stores/auth'
import http from '@/api/http'

let echo
let accountId
let version = 0
const authorizations = new Set()
const settings = import.meta.env || {}

export function connectRealtime(onNotification = () => {}) {
  const auth = useAuthStore()
  const id = Number(auth.user?.id)
  if (
    !auth.authenticated ||
    !Number.isSafeInteger(id) ||
    id < 1 ||
    !settings.VITE_REVERB_APP_KEY ||
    typeof window === 'undefined'
  ) {
    disconnectRealtime()
    return
  }
  if (echo && accountId === id) return echo
  disconnectRealtime()
  const port = Number(settings.VITE_REVERB_PORT || 8082)
  if (!Number.isInteger(port) || port < 1 || port > 65535) return
  const currentVersion = ++version
  accountId = id
  const current = () =>
    currentVersion === version && auth.authenticated && Number(auth.user?.id) === id
  window.Pusher = Pusher
  const secure = settings.VITE_REVERB_SCHEME === 'https'
  echo = new Echo({
    broadcaster: 'reverb',
    key: settings.VITE_REVERB_APP_KEY,
    wsHost: settings.VITE_REVERB_HOST || globalThis.window?.location?.hostname || '127.0.0.1',
    wsPort: port,
    wssPort: port,
    forceTLS: secure,
    // Pusher's ws transport also carries TLS; forceTLS selects the wss URL.
    enabledTransports: ['ws'],
    authorizer: (channel) => ({
      authorize(socketId, callback) {
        const controller = new AbortController()
        authorizations.add(controller)
        http
          .post(
            '/broadcasting/auth',
            { socket_id: socketId, channel_name: channel.name },
            { signal: controller.signal },
          )
          .then(
            ({ data }) => {
              if (current()) callback(null, data)
            },
            (error) => {
              if (current()) callback(error, null)
            },
          )
          .finally(() => authorizations.delete(controller))
      },
    }),
  })
  echo
    .private(`App.Models.User.${id}`)
    .notification(() => {
      if (current()) onNotification()
    })
    .listen('.account.session.changed', (event) => {
      if (current() && event?.guard === 'web') auth.revalidateSession()
    })
    .subscribed(() => {
      if (current()) auth.revalidateSession()
    })
  return echo
}
export function ticketChannel(id) {
  return echo?.private(`tickets.${id}`)
}
export function ticketStaffChannel(id) {
  return echo?.private(`tickets.${id}.staff`)
}
export function disconnectRealtime() {
  version += 1
  accountId = undefined
  for (const controller of authorizations) controller.abort()
  authorizations.clear()
  const previous = echo
  echo = undefined
  previous?.disconnect()
}
