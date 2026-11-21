import { defineStore } from 'pinia'
import { computed, ref, shallowRef } from 'vue'

import { conversationApi, mediaApi, messageApi } from '@/services/api'
import type { Conversation, Message, User } from '@/types'

/**
 * How many messages are loaded per page.
 */
const PAGE_SIZE = 50

/**
 * How long a typing indicator stays visible after the last keystroke.
 */
const TYPING_TIMEOUT_MS = 6000

/**
 * Holds the conversations, the loaded messages and the live presence state.
 */
export const useChatStore = defineStore('chat', () => {
  const conversations = ref<Conversation[]>([])
  const activeConversationId = ref<number | null>(null)

  /**
   * The messages of the active conversation.
   *
   * A shallow ref is used because the array is replaced wholesale whenever it
   * changes; a deep reactive proxy over thousands of messages is wasteful.
   */
  const messages = shallowRef<Message[]>([])

  const loading = ref(false)
  const loadingMore = ref(false)
  const hasMore = ref(true)
  const sending = ref(false)

  /**
   * The ids of the users that are currently typing in the active conversation.
   */
  const typingUsers = ref<Record<number, string>>({})

  /**
   * The live presence of every known user.
   */
  const presence = ref<Record<number, User['status']>>({})

  const activeConversation = computed(
    () =>
      conversations.value.find((conversation) => conversation.id === activeConversationId.value) ??
      null,
  )

  const unreadCount = computed(() =>
    conversations.value.reduce((total, conversation) => total + conversation.unread_count, 0),
  )

  const typingNames = computed(() => Object.values(typingUsers.value))

  /**
   * Load every conversation the user takes part in.
   */
  async function loadConversations(): Promise<void> {
    loading.value = true

    try {
      const response = await conversationApi.list()
      conversations.value = response.data
    } finally {
      loading.value = false
    }
  }

  /**
   * Make sure the global room exists and is part of the list.
   */
  async function ensureGlobalConversation(): Promise<Conversation> {
    const response = await conversationApi.global()

    const existing = conversations.value.find(
      (conversation) => conversation.id === response.data.id,
    )

    if (!existing) {
      conversations.value = [response.data, ...conversations.value]
    }

    return response.data
  }

  /**
   * Open a conversation and load its most recent messages.
   */
  async function openConversation(conversationId: number): Promise<void> {
    if (activeConversationId.value === conversationId && messages.value.length > 0) {
      return
    }

    activeConversationId.value = conversationId
    loading.value = true
    hasMore.value = true
    typingUsers.value = {}

    try {
      const response = await messageApi.list(conversationId, { limit: PAGE_SIZE })

      messages.value = response.data
      hasMore.value = response.meta?.has_more ?? false

      await markRead(conversationId)
    } finally {
      loading.value = false
    }
  }

  /**
   * Load the previous page of messages.
   */
  async function loadMoreMessages(): Promise<void> {
    if (loadingMore.value || !hasMore.value || messages.value.length === 0) {
      return
    }

    const oldest = messages.value[0]

    if (!oldest) {
      return
    }

    loadingMore.value = true

    try {
      const response = await messageApi.list(activeConversationId.value as number, {
        before_id: oldest.id,
        limit: PAGE_SIZE,
      })

      messages.value = [...response.data, ...messages.value]
      hasMore.value = response.meta?.has_more ?? false
    } finally {
      loadingMore.value = false
    }
  }

  /**
   * Send a message and append it to the list.
   */
  async function sendMessage(payload: {
    body?: string
    reply_to_id?: number
    image?: File
    view_once?: boolean
  }): Promise<Message | null> {
    if (activeConversationId.value === null) {
      return null
    }

    const conversationId = activeConversationId.value

    sending.value = true

    try {
      const response =
        payload.view_once && payload.image
          ? await mediaApi.uploadEphemeral(payload.image, conversationId).then((upload) =>
              messageApi.send(conversationId, {
                type: 'view_once',
                media_uuid: upload.uuid,
                reply_to_id: payload.reply_to_id,
              }),
            )
          : await messageApi.send(conversationId, payload)

      appendMessage(response.message)

      return response.message
    } finally {
      sending.value = false
    }
  }

  /**
   * Edit a message.
   */
  async function editMessage(id: number, body: string): Promise<void> {
    const response = await messageApi.update(id, body)

    replaceMessage(response.message)
  }

  /**
   * Delete a message.
   */
  async function deleteMessage(id: number): Promise<void> {
    await messageApi.remove(id)

    const message = messages.value.find((item) => item.id === id)

    if (message) {
      replaceMessage({
        ...message,
        body: null,
        is_deleted: true,
        deleted_at: new Date().toISOString(),
      })
    }
  }

  /**
   * Toggle an emoji reaction on a message.
   */
  async function toggleReaction(id: number, emoji: string): Promise<void> {
    const response = await messageApi.toggleReaction(id, emoji)

    const message = messages.value.find((item) => item.id === id)

    if (!message) {
      return
    }

    const author = currentUserId.value

    if (author === null) {
      return
    }

    const reactions = message.reactions.map((group) => ({
      ...group,
      user_ids: [...group.user_ids],
    }))

    const existing = reactions.find((group) => group.emoji === emoji)

    if (!response.active) {
      if (existing) {
        existing.count = Math.max(0, existing.count - 1)
        existing.user_ids = existing.user_ids.filter((userId) => userId !== author)

        if (existing.count === 0) {
          const index = reactions.indexOf(existing)
          reactions.splice(index, 1)
        }
      }
    } else if (existing) {
      existing.count += 1
      existing.user_ids.push(author)
    } else {
      reactions.push({ emoji, count: 1, user_ids: [author] })
    }

    replaceMessage({ ...message, reactions })
  }

  /**
   * Append a message, ignoring duplicates that arrive over the socket.
   */
  function appendMessage(message: Message): void {
    if (messages.value.some((item) => item.id === message.id)) {
      replaceMessage(message)

      return
    }

    messages.value = [...messages.value, message]
  }

  /**
   * Replace a message in place.
   */
  function replaceMessage(message: Message): void {
    const index = messages.value.findIndex((item) => item.id === message.id)

    if (index === -1) {
      return
    }

    const next = [...messages.value]
    next[index] = message
    messages.value = next
  }

  /**
   * Remove a message that was deleted elsewhere.
   */
  function removeMessage(id: number): void {
    messages.value = messages.value.filter((message) => message.id !== id)
  }

  /**
   * Mark a conversation as read.
   */
  async function markRead(conversationId: number): Promise<void> {
    const conversation = conversations.value.find((item) => item.id === conversationId)

    if (conversation && conversation.unread_count === 0) {
      return
    }

    await messageApi.markRead(conversationId)

    if (conversation) {
      conversation.unread_count = 0
    }
  }

  /**
   * Open a private conversation with another user.
   */
  async function openDirectConversation(userId: number): Promise<Conversation> {
    const response = await conversationApi.open(userId)

    const existing = conversations.value.find(
      (conversation) => conversation.id === response.data.id,
    )

    if (!existing) {
      conversations.value = [response.data, ...conversations.value]
    }

    return response.data
  }

  /**
   * Register a user as typing.
   */
  function setTyping(userId: number, nickname: string): void {
    typingUsers.value = { ...typingUsers.value, [userId]: nickname }

    window.setTimeout(() => clearTyping(userId), TYPING_TIMEOUT_MS)
  }

  /**
   * Stop showing the typing indicator of a user.
   */
  function clearTyping(userId: number): void {
    const next = { ...typingUsers.value }
    delete next[userId]
    typingUsers.value = next
  }

  /**
   * Update the presence of a user.
   */
  function setPresence(userId: number, status: User['status']): void {
    presence.value = { ...presence.value, [userId]: status }
  }

  /**
   * The id of the authenticated user, used to tell apart own reactions.
   */
  const currentUserId = ref<number | null>(null)

  function setCurrentUser(id: number | null): void {
    currentUserId.value = id
  }

  return {
    conversations,
    activeConversationId,
    activeConversation,
    messages,
    loading,
    loadingMore,
    hasMore,
    sending,
    typingUsers,
    typingNames,
    presence,
    unreadCount,
    currentUserId,
    loadConversations,
    ensureGlobalConversation,
    openConversation,
    loadMoreMessages,
    sendMessage,
    editMessage,
    deleteMessage,
    toggleReaction,
    appendMessage,
    replaceMessage,
    removeMessage,
    markRead,
    openDirectConversation,
    setTyping,
    clearTyping,
    setPresence,
    setCurrentUser,
  }
})
