import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import { conversationApi, mediaApi, messageApi } from '@/services/api'
import type { Conversation, Message } from '@/types'

vi.mock('@/services/api', () => ({
  conversationApi: {
    list: vi.fn(),
    global: vi.fn(),
    open: vi.fn(),
  },
  mediaApi: {
    uploadEphemeral: vi.fn(),
  },
  messageApi: {
    list: vi.fn(),
    send: vi.fn(),
    update: vi.fn(),
    remove: vi.fn(),
    toggleReaction: vi.fn(),
    markRead: vi.fn(),
    typing: vi.fn(),
  },
}))

/**
 * Build a message that the store can consume.
 */
function makeMessage(id: number, overrides: Partial<Message> = {}): Message {
  return {
    id,
    conversation_id: 1,
    user: {
      id: 9,
      nickname: 'Ada',
      avatar_url: null,
      avatar_sizes: {},
      status: 'online',
      last_seen_at: null,
      role: 'user',
      is_muted: false,
      email_verified_at: null,
      created_at: null,
      updated_at: null,
    },
    reply_to: null,
    body: `Mensaje ${id}`,
    type: 'text',
    attachment: null,
    view_once: null,
    reactions: [],
    edited_at: null,
    deleted_at: null,
    is_deleted: false,
    created_at: '2026-01-01T10:00:00.000Z',
    updated_at: null,
    ...overrides,
  }
}

/**
 * Build a conversation payload.
 */
function makeConversation(id: number, overrides: Partial<Conversation> = {}): Conversation {
  return {
    id,
    type: 'global',
    title: 'Chat General',
    participants: [],
    participant_count: 0,
    unread_count: 0,
    last_read_at: null,
    last_message_at: null,
    created_at: null,
    ...overrides,
  }
}

describe('useChatStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('loads the messages of a conversation and marks it as read', async () => {
    const { useChatStore } = await import('@/stores/chat')
    const store = useChatStore()

    vi.mocked(messageApi.list).mockResolvedValue({
      data: [makeMessage(1), makeMessage(2)],
      meta: { has_more: false },
    })
    vi.mocked(messageApi.markRead).mockResolvedValue(undefined)

    await store.openConversation(1)

    expect(store.messages).toHaveLength(2)
    expect(store.activeConversationId).toBe(1)
    expect(store.hasMore).toBe(false)
    expect(messageApi.markRead).toHaveBeenCalledWith(1)
  })

  it('prepends the previous page and updates the pagination flag', async () => {
    const { useChatStore } = await import('@/stores/chat')
    const store = useChatStore()

    vi.mocked(messageApi.list).mockResolvedValueOnce({
      data: [makeMessage(3)],
      meta: { has_more: true },
    })
    vi.mocked(messageApi.markRead).mockResolvedValue(undefined)

    await store.openConversation(1)

    vi.mocked(messageApi.list).mockResolvedValueOnce({
      data: [makeMessage(1), makeMessage(2)],
      meta: { has_more: false },
    })

    await store.loadMoreMessages()

    expect(store.messages.map((message) => message.id)).toEqual([1, 2, 3])
    expect(store.hasMore).toBe(false)
  })

  it('does not request a previous page when there are no more messages', async () => {
    const { useChatStore } = await import('@/stores/chat')
    const store = useChatStore()

    vi.mocked(messageApi.list).mockResolvedValue({
      data: [makeMessage(1)],
      meta: { has_more: false },
    })
    vi.mocked(messageApi.markRead).mockResolvedValue(undefined)

    await store.openConversation(1)
    vi.mocked(messageApi.list).mockClear()

    await store.loadMoreMessages()

    expect(messageApi.list).not.toHaveBeenCalled()
  })

  it('uploads the image before sending a view-once message', async () => {
    const { useChatStore } = await import('@/stores/chat')
    const store = useChatStore()
    const file = new File(['imagen'], 'secreto.png', { type: 'image/png' })

    store.activeConversationId = 8

    vi.mocked(mediaApi.uploadEphemeral).mockResolvedValue({
      uuid: '019bf2c0-0000-7000-8000-000000000000',
      token: 'token',
      url: 'https://example.test/media',
      width: 400,
      height: 400,
      size: 1024,
      expires_at: null,
    })
    vi.mocked(messageApi.send).mockResolvedValue({
      message: makeMessage(21, {
        type: 'view_once',
        body: '',
        view_once: {
          available: true,
          opened: false,
          url: null,
          width: 400,
          height: 400,
        },
      }),
    })

    await store.sendMessage({ image: file, view_once: true, reply_to_id: 3 })

    expect(mediaApi.uploadEphemeral).toHaveBeenCalledWith(file, 8)
    expect(messageApi.send).toHaveBeenCalledWith(8, {
      type: 'view_once',
      media_uuid: '019bf2c0-0000-7000-8000-000000000000',
      reply_to_id: 3,
    })
    expect(store.messages).toHaveLength(1)
  })

  it('sends a normal message without touching the ephemeral upload', async () => {
    const { useChatStore } = await import('@/stores/chat')
    const store = useChatStore()

    store.activeConversationId = 8

    vi.mocked(messageApi.send).mockResolvedValue({ message: makeMessage(22) })

    await store.sendMessage({ body: 'Hola' })

    expect(mediaApi.uploadEphemeral).not.toHaveBeenCalled()
    expect(messageApi.send).toHaveBeenCalledWith(8, { body: 'Hola' })
  })

  it('appends a message that arrives over the socket', async () => {
    const { useChatStore } = await import('@/stores/chat')
    const store = useChatStore()

    store.appendMessage(makeMessage(10))

    expect(store.messages).toHaveLength(1)
    expect(store.messages[0]?.id).toBe(10)
  })

  it('ignores a duplicated message and replaces it instead', async () => {
    const { useChatStore } = await import('@/stores/chat')
    const store = useChatStore()

    store.appendMessage(makeMessage(10, { body: 'Original' }))
    store.appendMessage(makeMessage(10, { body: 'Editado' }))

    expect(store.messages).toHaveLength(1)
    expect(store.messages[0]?.body).toBe('Editado')
  })

  it('removes a message that was deleted elsewhere', async () => {
    const { useChatStore } = await import('@/stores/chat')
    const store = useChatStore()

    store.appendMessage(makeMessage(10))
    store.appendMessage(makeMessage(11))

    store.removeMessage(10)

    expect(store.messages.map((message) => message.id)).toEqual([11])
  })

  it('tracks the typing users and forgets them on timeout', async () => {
    vi.useFakeTimers()

    const { useChatStore } = await import('@/stores/chat')
    const store = useChatStore()

    store.setTyping(4, 'Grace')
    store.setTyping(5, 'Linus')

    expect(store.typingNames).toEqual(['Grace', 'Linus'])

    vi.advanceTimersByTime(6000)

    expect(store.typingNames).toEqual([])

    vi.useRealTimers()
  })

  it('updates the presence of a user', async () => {
    const { useChatStore } = await import('@/stores/chat')
    const store = useChatStore()

    store.setPresence(7, 'busy')

    expect(store.presence[7]).toBe('busy')
  })

  it('reuses an existing direct conversation instead of duplicating it', async () => {
    const { useChatStore } = await import('@/stores/chat')
    const store = useChatStore()

    store.conversations = [makeConversation(4, { type: 'direct' })]

    vi.mocked(conversationApi.open).mockResolvedValue({
      data: makeConversation(4, { type: 'direct' }),
      meta: {},
    })

    const conversation = await store.openDirectConversation(7)

    expect(conversation.id).toBe(4)
    expect(store.conversations).toHaveLength(1)
  })
})
