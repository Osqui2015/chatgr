import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

import type { Message, PresencePayload, TypingPayload } from '@/types'

/**
 * The payload of a reaction broadcast.
 */
interface ReactionBroadcast {
  message_id: number
  conversation_id: number
  user_id: number
  emoji: string
}

/**
 * The payload of a deletion broadcast.
 */
interface MessageDeletedBroadcast {
  message_id: number
  conversation_id: number
}

/**
 * The callbacks the application registers for the real-time channels.
 */
export interface RealtimeHandlers {
  onMessage: (message: Message) => void
  onMessageUpdated: (message: Message) => void
  onMessageDeleted: (messageId: number) => void
  onReactionAdded: (payload: ReactionBroadcast) => void
  onReactionRemoved: (payload: ReactionBroadcast) => void
  onTyping: (payload: TypingPayload) => void
  onPresence: (payload: PresencePayload) => void
}

let echo: Echo<'reverb'> | null = null
let handlers: RealtimeHandlers | null = null

/**
 * Build the Echo instance.
 *
 * The authentication request carries the session cookie, so the server can
 * resolve the user without exposing a token to the browser.
 */
function createEcho(): Echo<'reverb'> {
  const key = import.meta.env.VITE_REVERB_APP_KEY as string
  const host = import.meta.env.VITE_REVERB_HOST as string
  const port = Number(import.meta.env.VITE_REVERB_PORT as string)
  const scheme = (import.meta.env.VITE_REVERB_SCHEME as string) ?? 'http'

  window.Pusher = Pusher

  return new Echo({
    broadcaster: 'reverb',
    key,
    wsHost: host,
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    authEndpoint: '/broadcasting/auth',
    auth: {
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
    },
  })
}

/**
 * Connect to the realtime service and register the global handlers.
 */
export function connectRealtime(next: RealtimeHandlers): void {
  disconnectRealtime()

  handlers = next
  echo = createEcho()

  joinPresence()
}

/**
 * Subscribe to the channel of a conversation.
 */
export function joinConversation(conversationId: number): void {
  if (!echo) {
    return
  }

  leaveConversation(conversationId)

  echo
    .join(`conversation.${conversationId}`)
    .listen('.message.sent', (event: { message: Message }) => {
      handlers?.onMessage(event.message)
    })
    .listen('.message.updated', (event: { message: Message }) => {
      handlers?.onMessageUpdated(event.message)
    })
    .listen('.message.deleted', (event: MessageDeletedBroadcast) => {
      handlers?.onMessageDeleted(event.message_id)
    })
    .listen('.message.reaction.added', (event: ReactionBroadcast) => {
      handlers?.onReactionAdded(event)
    })
    .listen('.message.reaction.removed', (event: ReactionBroadcast) => {
      handlers?.onReactionRemoved(event)
    })
    .listen('.typing.indicator', (event: TypingPayload) => {
      handlers?.onTyping(event)
    })
}

/**
 * Leave the channel of a conversation.
 */
export function leaveConversation(conversationId: number): void {
  if (!echo) {
    return
  }

  echo.leave(`conversation.${conversationId}`)
}

/**
 * Subscribe to the global presence channel.
 */
export function joinPresence(): void {
  if (!echo) {
    return
  }

  echo
    .join('presence.global')
    .here((members: Array<{ id: number; status: string }>) => {
      for (const member of members) {
        handlers?.onPresence({
          user_id: member.id,
          status: member.status as PresencePayload['status'],
          last_seen_at: null,
        })
      }
    })
    .joining((member: { id: number; status: string }) => {
      handlers?.onPresence({
        user_id: member.id,
        status: member.status as PresencePayload['status'],
        last_seen_at: null,
      })
    })
    .leaving((member: { id: number; status: string }) => {
      handlers?.onPresence({
        user_id: member.id,
        status: 'offline',
        last_seen_at: null,
      })
    })
}

/**
 * Close the realtime connection.
 */
export function disconnectRealtime(): void {
  if (echo) {
    echo.disconnect()
    echo = null
  }

  handlers = null
}

/**
 * The Echo instance, for components that need direct access.
 */
export function realtime(): Echo<'reverb'> | null {
  return echo
}
