/**
 * Checks that the Reverb server accepts a WebSocket connection and that the
 * broadcast endpoint authenticates the session cookie.
 *
 * Run it against a live backend:
 *   node tests/e2e/websocket.mjs http://127.0.0.1:8000
 */
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

const baseUrl = process.argv[2] ?? 'http://127.0.0.1:8000'
const cookie = process.argv[3] ?? ''

globalThis.window = globalThis
globalThis.window.Pusher = Pusher

const echo = new Echo({
  broadcaster: 'reverb',
  key: 'chat-key',
  wsHost: '127.0.0.1',
  wsPort: 8080,
  forceTLS: false,
  enabledTransports: ['ws'],
  authEndpoint: `${baseUrl}/broadcasting/auth`,
  auth: {
    headers: {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      ...(cookie ? { Cookie: cookie } : {}),
    },
  },
})

const timeout = setTimeout(() => {
  console.error('TIMEOUT: the WebSocket did not connect in 10 seconds')
  process.exit(1)
}, 10000)

echo.connector.pusher.connection.bind('connected', () => {
  console.log('OK: the WebSocket connected to Reverb')

  const channel = echo.join('presence.global')

  channel.bind('pusher:subscription_succeeded', (members) => {
    console.log(`OK: subscribed to presence.global with ${members.count} member(s)`)
    clearTimeout(timeout)
    process.exit(0)
  })

  channel.bind('pusher:error', (error) => {
    console.error(`ERROR: the subscription failed: ${JSON.stringify(error)}`)
    clearTimeout(timeout)
    process.exit(1)
  })
})

echo.connector.pusher.connection.bind('error', (error) => {
  console.error(`ERROR: the connection failed: ${String(error)}`)
  clearTimeout(timeout)
  process.exit(1)
})
