import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import http from '@/api/http'

const settings = import.meta.env || {}

export function connectAccountRealtime({
  scope,
  account,
  isCurrent,
  onChanged = () => {},
  onReady = () => {},
  onSessionChanged = () => {},
}) {
  const id = Number(account)
  const port = Number(settings.VITE_REVERB_PORT || 8082)
  if (
    !['employee', 'applicant'].includes(scope) ||
    !Number.isSafeInteger(id) ||
    id < 1 ||
    !isCurrent() ||
    !settings.VITE_REVERB_APP_KEY ||
    !Number.isInteger(port) ||
    port < 1 ||
    port > 65535 ||
    typeof window === 'undefined'
  )
    return

  let closed = false
  const current = () => !closed && isCurrent()
  const pending = new Set()
  const secure = settings.VITE_REVERB_SCHEME === 'https'
  const echo = new Echo({
    broadcaster: 'reverb',
    Pusher,
    key: settings.VITE_REVERB_APP_KEY,
    wsHost: settings.VITE_REVERB_HOST || globalThis.window?.location?.hostname || '127.0.0.1',
    wsPort: port,
    wssPort: port,
    forceTLS: secure,
    // Pusher's ws transport also carries TLS; forceTLS selects the wss URL.
    enabledTransports: ['ws'],
    authorizer: (channel) => ({
      authorize(socketId, callback) {
        if (!current()) return
        const controller = new AbortController()
        pending.add(controller)
        http
          .post(
            `${scope === 'applicant' ? '/applicant' : ''}/broadcasting/auth`,
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
          .finally(() => pending.delete(controller))
      },
    }),
  })
  echo
    .private(`App.Models.${scope === 'applicant' ? 'Applicant' : 'User'}.${id}`)
    .listen('.telegram.connection.updated', () => {
      if (current()) onChanged()
    })
    .listen('.account.session.changed', (event) => {
      if (current() && event?.guard === (scope === 'applicant' ? 'applicant' : 'web'))
        onSessionChanged()
    })
    .subscribed(() => {
      if (current()) onReady()
    })

  return {
    close() {
      if (closed) return
      closed = true
      for (const controller of pending) controller.abort()
      pending.clear()
      echo.disconnect()
    },
  }
}
