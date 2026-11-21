import type {
  Conversation,
  EphemeralUpload,
  Message,
  MessageType,
  PaginatedResponse,
  SexualRole,
  User,
  UserStatus,
} from '@/types'
import { http } from './http'

/**
 * The payload returned when a login needs an extra verification step.
 */
export interface OtpRequiredResponse {
  message: string
  requires_otp: true
  email: string
  purpose: 'login' | 'registration'
}

/**
 * The payload returned when the registration code was accepted.
 */
export interface AuthResponse {
  message: string
  user: User
}

/**
 * The payload of a successful message creation.
 */
export interface MessageResponse {
  message: Message
}

/**
 * Every endpoint the frontend uses to talk to the API.
 */
export const authApi = {
  register: (data: {
    email: string
    nickname: string
    password: string
    password_confirmation: string
  }) => http.post<{ message: string; email: string; purpose: string }>('/auth/register', data),

  verifyRegistration: (data: { email: string; code: string; password?: string }) =>
    http.post<AuthResponse>('/auth/verify/registration', data),

  verifyLogin: (data: { email: string; code: string }) =>
    http.post<AuthResponse>('/auth/verify/login', data),

  resend: (email: string) => http.post<{ message: string }>('/auth/resend', { email }),

  login: (data: { email: string; password: string }) =>
    http.postAllowing<AuthResponse | OtpRequiredResponse>('/auth/login', data, 401),

  me: () => http.get<{ user: User }>('/auth/me'),

  logout: () => http.post<{ message: string }>('/auth/logout'),
}

export const userApi = {
  update: (data: { nickname?: string; sexual_role?: SexualRole | null }) =>
    http.patch<AuthResponse>('/users/me', data),

  updateStatus: (status: UserStatus) =>
    http.post<{ message: string; status: UserStatus }>('/users/me/status', { status }),

  updateAvatar: (file: File) => {
    const form = new FormData()
    form.append('avatar', file)

    return http.post<AuthResponse>('/users/me/avatar', form)
  },

  deleteAvatar: () => http.delete<AuthResponse>('/users/me/avatar'),

  search: (query: string) =>
    http.get<PaginatedResponse<User>>('/users/search', { params: { q: query } }),

  show: (id: number) =>
    http.get<{
      data: User
      moderation?: { blocked: boolean; muted: boolean }
    }>(`/users/${id}`),

  block: (id: number) => http.post<{ message: string }>(`/users/${id}/block`),

  unblock: (id: number) => http.delete<{ message: string }>(`/users/${id}/block`),

  mute: (id: number) => http.post<{ message: string }>(`/users/${id}/mute`),

  unmute: (id: number) => http.delete<{ message: string }>(`/users/${id}/mute`),

  blocks: () => http.get<PaginatedResponse<User>>('/users/me/blocks'),
}

export const conversationApi = {
  list: () => http.get<PaginatedResponse<Conversation>>('/conversations'),

  show: (id: number) => http.get<{ data: Conversation }>(`/conversations/${id}`),

  global: () => http.get<{ data: Conversation }>('/conversations/global'),

  open: (userId: number) =>
    http.post<{ data: Conversation }>('/conversations', { user_id: userId }),
}

export interface MessageListParams {
  before_id?: number
  after_id?: number
  limit?: number
}

export const messageApi = {
  list: (conversationId: number, params: MessageListParams = {}) =>
    http.get<PaginatedResponse<Message>>(`/conversations/${conversationId}/messages`, { params }),

  send: (
    conversationId: number,
    data: {
      body?: string
      reply_to_id?: number
      image?: File
      type?: MessageType
      media_uuid?: string
    },
  ) => {
    const form = new FormData()

    if (data.body) {
      form.append('body', data.body)
    }

    if (data.reply_to_id) {
      form.append('reply_to_id', String(data.reply_to_id))
    }

    if (data.type) {
      form.append('type', data.type)
    }

    if (data.media_uuid) {
      form.append('media_uuid', data.media_uuid)
    }

    if (data.image) {
      form.append('image', data.image)
    }

    return http.post<MessageResponse>(`/conversations/${conversationId}/messages`, form)
  },

  update: (id: number, body: string) => http.patch<MessageResponse>(`/messages/${id}`, { body }),

  remove: (id: number) => http.delete<{ message: string }>(`/messages/${id}`),

  toggleReaction: (id: number, emoji: string) =>
    http.post<{ active: boolean; emoji: string }>(`/messages/${id}/reactions`, { emoji }),

  markRead: (conversationId: number, messageId?: number) =>
    http.post<{ message: string; marked: number }>(`/conversations/${conversationId}/read`, {
      message_id: messageId,
    }),

  typing: (conversationId: number, isTyping: boolean) =>
    http.post<{ message: string }>(`/conversations/${conversationId}/typing`, {
      is_typing: isTyping,
    }),
}

export const mediaApi = {
  uploadEphemeral: (file: File, conversationId?: number) => {
    const form = new FormData()
    form.append('image', file)

    if (conversationId) {
      form.append('conversation_id', String(conversationId))
    }

    return http.post<EphemeralUpload>('/media/ephemeral', form)
  },
}

export const reportApi = {
  create: (data: {
    reported_user_id: number
    message_id?: number
    reason: 'spam' | 'harassment' | 'nudity' | 'violence' | 'other'
    details?: string
  }) => http.post<{ message: string; report_id: number }>('/reports', data),
}

export interface SearchResults {
  users: User[]
  messages: Message[]
  conversations: Conversation[]
}

export const searchApi = {
  query: (q: string, scope: 'all' | 'users' | 'messages' | 'conversations' = 'all') =>
    http.get<{ data: SearchResults }>('/search', { params: { q, scope } }),
}

export interface AdminStatistics {
  users: { total: number; verified: number; banned: number; new_24h: number }
  messages: { total: number; last_24h: number; deleted: number }
  presence: { online: number }
  moderation: { pending_reports: number; active_bans: number }
}

export const adminApi = {
  statistics: () => http.get<{ data: AdminStatistics }>('/admin/statistics'),

  users: (params: { q?: string; role?: string; banned?: boolean } = {}) =>
    http.get<PaginatedResponse<User>>('/admin/users', { params }),

  updateRole: (id: number, role: string) =>
    http.patch<AuthResponse>(`/admin/users/${id}/role`, { role }),

  ban: (id: number, reason?: string) =>
    http.post<{ message: string }>(`/admin/users/${id}/ban`, { reason }),

  unban: (id: number) => http.delete<{ message: string }>(`/admin/users/${id}/ban`),

  bans: () => http.get<PaginatedResponse<unknown>>('/admin/bans'),

  reports: (status?: string) =>
    http.get<PaginatedResponse<unknown>>('/admin/reports', { params: { status } }),

  resolveReport: (id: number, status: string) =>
    http.patch<{ message: string }>(`/admin/reports/${id}`, { status }),

  onlineUsers: () => http.get<PaginatedResponse<User>>('/admin/online'),
}
