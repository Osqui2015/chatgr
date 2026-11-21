/**
 * Shared domain types that mirror the API payloads.
 */

/** Presence states a user can expose. */
export type UserStatus = 'online' | 'away' | 'busy' | 'invisible' | 'offline'

/** Role based authorisation levels. */
export type UserRole = 'user' | 'moderator' | 'admin'

/** Rol personal / preferencia de perfil. */
export type SexualRole = 'activo' | 'versatil' | 'pasivo'

/** The kind of payload a message carries. */
export type MessageType = 'text' | 'image' | 'gif' | 'view_once'

/** The scope of a conversation. */
export type ConversationType = 'global' | 'direct'

/** A user as the API returns them. */
export interface User {
  id: number
  nickname: string
  email?: string
  avatar_url: string | null
  avatar_sizes: Record<string, string | null>
  status: UserStatus
  sexual_role?: SexualRole | null
  sexual_role_label?: string | null
  last_seen_at: string | null
  role: UserRole
  is_muted: boolean
  is_banned?: boolean
  email_verified_at: string | null
  created_at: string | null
  updated_at: string | null
}

/** A participant inside a conversation payload. */
export interface ConversationParticipant {
  id: number
  nickname: string
  avatar_url: string | null
  status: UserStatus
  sexual_role?: SexualRole | null
  unread_count: number
}

/** A conversation as the API returns them. */
export interface Conversation {
  id: number
  type: ConversationType
  title: string | null
  participants: ConversationParticipant[]
  participant_count: number
  unread_count: number
  last_read_at: string | null
  last_message_at: string | null
  created_at: string | null
}

/** A reply preview attached to a message. */
export interface MessageReply {
  id: number
  body: string | null
  user_id: number
  user: User | null
}

/** An image or file attached to a message. */
export interface MessageAttachment {
  url: string
  mime: string | null
  size: number | null
  width: number | null
  height: number | null
}

/** A view-once image linked to a message. */
export interface MessageViewOnce {
  /** Whether the image can still be opened. */
  available: boolean
  /** Whether the image has already been consumed. */
  opened: boolean
  /** The authorized URL, only present for recipients while available. */
  url: string | null
  width: number | null
  height: number | null
}

/** A group of reactions for one emoji. */
export interface MessageReactionGroup {
  emoji: string
  count: number
  user_ids: number[]
}

/** A chat message as the API returns them. */
export interface Message {
  id: number
  conversation_id: number
  user: User | null
  reply_to: MessageReply | null
  body: string | null
  type: MessageType
  attachment: MessageAttachment | null
  view_once: MessageViewOnce | null
  reactions: MessageReactionGroup[]
  edited_at: string | null
  deleted_at: string | null
  is_deleted: boolean
  created_at: string | null
  updated_at: string | null
}

/** A view-once image uploaded by the current user. */
export interface EphemeralUpload {
  uuid: string
  token: string
  url: string
  width: number | null
  height: number | null
  size: number
  expires_at: string | null
}

/** The payload of a typing indicator broadcast. */
export interface TypingPayload {
  conversation_id: number
  user_id: number
  nickname: string
  is_typing: boolean
}

/** The payload of a presence broadcast. */
export interface PresencePayload {
  user_id: number
  status: UserStatus
  sexual_role?: SexualRole | null
  sexual_role_label?: string | null
  last_seen_at: string | null
}

/** The response of a paginated list endpoint. */
export interface PaginatedResponse<T> {
  data: T[]
  meta?: {
    current_page?: number
    last_page?: number
    total?: number
    count?: number
    has_more?: boolean
  }
}
