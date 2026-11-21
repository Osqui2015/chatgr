<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'

import ChatSidebar from '@/components/chat/ChatSidebar.vue'
import ImageViewer from '@/components/chat/ImageViewer.vue'
import MessageBubble from '@/components/chat/MessageBubble.vue'
import MessageInput from '@/components/chat/MessageInput.vue'
import TypingIndicator from '@/components/chat/TypingIndicator.vue'
import VirtualMessageList from '@/components/chat/VirtualMessageList.vue'
import type { VirtualMessageListExposed } from '@/components/chat/virtualMessageList'
import ProfileModal from '@/components/profile/ProfileModal.vue'
import SearchModal from '@/components/search/SearchModal.vue'
import UserCard from '@/components/user/UserCard.vue'
import { ApiError } from '@/services/http'
import { messageApi, reportApi, userApi } from '@/services/api'
import {
  connectRealtime,
  disconnectRealtime,
  joinConversation,
  leaveConversation,
} from '@/services/realtime'
import { useAuthStore } from '@/stores/auth'
import { useChatStore } from '@/stores/chat'
import type { Message, User } from '@/types'

/**
 * The main chat screen.
 */
const auth = useAuthStore()
const chat = useChatStore()

/**
 * The virtual list that renders the messages.
 */
const virtualList = ref<VirtualMessageListExposed | null>(null)
const replyTo = ref<Message | null>(null)
const showProfile = ref(false)
const showSearch = ref(false)
const showUserCard = ref(false)
const selectedUser = ref<User | null>(null)
const selectedUserBlocked = ref(false)
const selectedUserMuted = ref(false)
const viewerSrc = ref<string | null>(null)
const viewerCaption = ref('')
const notice = ref('')

/**
 * Whether the message list is scrolled close to the bottom.
 *
 * The virtual list owns the scroll position, so the flag is read from it.
 */
const isNearBottom = computed(() => virtualList.value?.isNearBottom ?? true)

/**
 * Whether the conversation is the global room.
 */
const isGlobal = computed(() => chat.activeConversation?.type === 'global')

/**
 * The title of the active conversation.
 */
const conversationTitle = computed(() => {
  const conversation = chat.activeConversation

  if (!conversation) {
    return 'Chat'
  }

  if (conversation.type === 'global') {
    return conversation.title ?? 'Chat General'
  }

  const other = conversation.participants.find((participant) => participant.id !== auth.user?.id)

  return other?.nickname ?? 'Conversación'
})

const otherParticipantId = computed(() => {
  const conversation = chat.activeConversation
  if (!conversation || conversation.type === 'global') {
    return null
  }
  const other = conversation.participants.find((p) => p.id !== auth.user?.id)
  return other?.id ?? null
})

/**
 * Scroll the list to the newest message.
 */
async function scrollToBottom(smooth = true): Promise<void> {
  await virtualList.value?.scrollToBottom(smooth)
}

/**
 * Load the previous page when the user reaches the top of the list.
 */
async function onScrollTop(): Promise<void> {
  await chat.loadMoreMessages()

  await virtualList.value?.scrollToTop()
}

/**
 * Open a conversation.
 */
async function selectConversation(conversationId: number): Promise<void> {
  if (chat.activeConversationId === conversationId) {
    return
  }

  if (chat.activeConversationId !== null) {
    leaveConversation(chat.activeConversationId)
  }

  replyTo.value = null

  await chat.openConversation(conversationId)

  joinConversation(conversationId)

  await scrollToBottom(false)
}

/**
 * Send a message.
 */
async function sendMessage(payload: {
  body?: string
  reply_to_id?: number
  image?: File
  view_once?: boolean
}): Promise<void> {
  try {
    await chat.sendMessage(payload)
    replyTo.value = null
    await scrollToBottom()
  } catch (error) {
    notice.value = error instanceof ApiError ? error.message : 'No se pudo enviar el mensaje.'
  }
}

/**
 * Open an image in the viewer.
 *
 * A view-once image is opened for the first and only time, so the local
 * message is switched to the opened state as soon as the viewer is launched.
 */
function openImage(message: Message): void {
  if (message.type === 'view_once') {
    const url = message.view_once?.url

    if (!url) {
      notice.value = 'Esta imagen ya no está disponible.'

      return
    }

    viewerCaption.value = 'Imagen de un solo uso · ya se ha abierto'
    viewerSrc.value = url

    chat.replaceMessage({
      ...message,
      view_once: { ...message.view_once!, available: false, opened: true, url: null },
    })

    return
  }

  const url = message.attachment?.url

  if (!url) {
    notice.value = 'Esta imagen ya no está disponible.'

    return
  }

  viewerCaption.value = ''
  viewerSrc.value = url
}

/**
 * Close the image viewer.
 */
function closeViewer(): void {
  viewerSrc.value = null
  viewerCaption.value = ''
}

/**
 * Edit a message.
 */
async function editMessage(id: number, body: string): Promise<void> {
  try {
    await chat.editMessage(id, body)
  } catch (error) {
    notice.value = error instanceof ApiError ? error.message : 'No se pudo editar el mensaje.'
  }
}

/**
 * Delete a message.
 */
async function deleteMessage(id: number): Promise<void> {
  try {
    await chat.deleteMessage(id)
  } catch (error) {
    notice.value = error instanceof ApiError ? error.message : 'No se pudo eliminar el mensaje.'
  }
}

/**
 * Toggle a reaction.
 */
async function reactToMessage(id: number, emoji: string): Promise<void> {
  try {
    await chat.toggleReaction(id, emoji)
  } catch (error) {
    notice.value = error instanceof ApiError ? error.message : 'No se pudo añadir la reacción.'
  }
}

/**
 * Report a message.
 */
async function reportMessage(message: Message): Promise<void> {
  if (!message.user) {
    return
  }

  try {
    await reportApi.create({
      reported_user_id: message.user.id,
      message_id: message.id,
      reason: 'spam',
    })

    notice.value = 'Denuncia registrada.'
  } catch (error) {
    notice.value = error instanceof ApiError ? error.message : 'No se pudo registrar la denuncia.'
  }
}

/**
 * Notify the other participants that the user is typing.
 */
async function onTyping(isTyping: boolean): Promise<void> {
  if (chat.activeConversationId === null) {
    return
  }

  try {
    await messageApi.typing(chat.activeConversationId, isTyping)
  } catch {
    // The indicator is a hint; a failure must never interrupt the user.
  }
}

/**
 * Open the profile of another user.
 */
async function openUser(userId: number): Promise<void> {
  try {
    const response = await userApi.show(userId)

    selectedUser.value = response.data
    selectedUserBlocked.value = response.moderation?.blocked ?? false
    selectedUserMuted.value = response.moderation?.muted ?? false
    showUserCard.value = true
  } catch {
    notice.value = 'No se pudo cargar el perfil del usuario.'
  }
}

/**
 * Start a private conversation with the selected user.
 */
async function startDirectConversation(userId: number): Promise<void> {
  showUserCard.value = false

  try {
    const conversation = await chat.openDirectConversation(userId)

    await selectConversation(conversation.id)
  } catch (error) {
    notice.value = error instanceof ApiError ? error.message : 'No se pudo abrir la conversación.'
  }
}

/**
 * Open a conversation with the user found in the search.
 */
async function onSearchUser(userId: number): Promise<void> {
  showSearch.value = false
  await startDirectConversation(userId)
}

/**
 * Jump to the conversation that contains the found message.
 */
async function onSearchMessage(message: Message): Promise<void> {
  showSearch.value = false
  await selectConversation(message.conversation_id)
}

/**
 * Handle the incoming real-time events.
 */
function handleRealtime(): void {
  connectRealtime({
    onMessage: (message) => {
      if (message.conversation_id !== chat.activeConversationId) {
        const conversation = chat.conversations.find((item) => item.id === message.conversation_id)

        if (conversation) {
          conversation.unread_count += 1
        }

        return
      }

      chat.appendMessage(message)

      if (isNearBottom.value) {
        void scrollToBottom()
      }
    },
    onMessageUpdated: (message) => {
      chat.replaceMessage(message)
    },
    onMessageDeleted: (messageId) => {
      chat.removeMessage(messageId)
    },
    onReactionAdded: (payload) => {
      const message = chat.messages.find((item) => item.id === payload.message_id)

      if (!message) {
        return
      }

      const existing = message.reactions.find((group) => group.emoji === payload.emoji)

      if (existing) {
        existing.count += 1
        existing.user_ids.push(payload.user_id)
      } else {
        message.reactions.push({
          emoji: payload.emoji,
          count: 1,
          user_ids: [payload.user_id],
        })
      }

      chat.replaceMessage({ ...message })
    },
    onReactionRemoved: (payload) => {
      const message = chat.messages.find((item) => item.id === payload.message_id)

      if (!message) {
        return
      }

      const existing = message.reactions.find((group) => group.emoji === payload.emoji)

      if (existing) {
        existing.count = Math.max(0, existing.count - 1)
        existing.user_ids = existing.user_ids.filter((id) => id !== payload.user_id)

        if (existing.count === 0) {
          message.reactions = message.reactions.filter((group) => group.emoji !== payload.emoji)
        }
      }

      chat.replaceMessage({ ...message })
    },
    onTyping: (payload) => {
      if (payload.user_id === auth.user?.id) {
        return
      }

      if (payload.is_typing) {
        chat.setTyping(payload.user_id, payload.nickname)
      } else {
        chat.clearTyping(payload.user_id)
      }
    },
    onPresence: (payload) => {
      chat.setPresence(payload.user_id, payload.status)
    },
  })
}

/**
 * Load the initial data and connect the socket.
 */
onMounted(async () => {
  chat.setCurrentUser(auth.user?.id ?? null)

  try {
    const global = await chat.ensureGlobalConversation()

    await chat.loadConversations()
    await chat.openConversation(global.id)

    joinConversation(global.id)
    handleRealtime()

    await scrollToBottom(false)
  } catch (error) {
    notice.value = error instanceof ApiError ? error.message : 'No se pudo cargar el chat.'
  }
})

onBeforeUnmount(() => {
  disconnectRealtime()
})

watch(
  () => auth.user?.id,
  (id) => chat.setCurrentUser(id ?? null),
)
</script>

<template>
  <div class="flex h-full overflow-hidden">
    <div class="hidden md:block">
      <ChatSidebar
        :conversations="chat.conversations"
        :active-id="chat.activeConversationId"
        :presence="chat.presence"
        global-title="Chat General"
        @select="selectConversation"
        @search="showSearch = true"
        @new-conversation="showSearch = true"
      />
    </div>

    <div class="flex min-w-0 flex-1 flex-col">
      <header class="flex items-center gap-3 border-b border-border bg-surface px-4 py-3">
        <button
          type="button"
          class="btn-ghost !p-2 md:hidden"
          aria-label="Ver conversaciones"
          @click="showSearch = true"
        >
          <svg
            class="size-5"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="1.8"
          >
            <path d="M4 6h16M4 12h16M4 18h16" stroke-linecap="round" />
          </svg>
        </button>

        <div
          class="min-w-0 flex-1"
          :class="{ 'cursor-pointer': otherParticipantId !== null }"
          @click="otherParticipantId !== null && openUser(otherParticipantId)"
        >
          <h2
            class="truncate text-sm font-semibold text-text"
            :class="{ 'hover:underline': otherParticipantId !== null }"
          >
            {{ conversationTitle }}
          </h2>
          <p class="truncate text-xs text-text-subtle">
            {{ isGlobal ? 'Sala pública' : 'Conversación privada' }}
          </p>
        </div>

        <button type="button" class="btn-ghost !p-2" title="Buscar" @click="showSearch = true">
          <svg
            class="size-5"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="1.8"
          >
            <circle cx="11" cy="11" r="6" />
            <path d="m16 16 4 4" stroke-linecap="round" />
          </svg>
        </button>

        <button type="button" class="btn-ghost !p-2" title="Mi perfil" @click="showProfile = true">
          <span
            class="relative flex size-8 items-center justify-center rounded-full bg-accent text-xs font-semibold text-white"
          >
            {{ auth.user?.nickname?.[0]?.toUpperCase() ?? '?' }}
          </span>
        </button>
      </header>

      <div
        v-if="notice"
        class="flex items-center justify-between gap-3 bg-warning/15 px-4 py-2 text-xs text-warning"
      >
        <span>{{ notice }}</span>
        <button type="button" class="text-warning/70 hover:text-warning" @click="notice = ''">
          ✕
        </button>
      </div>

      <div class="min-h-0 flex-1 py-3">
        <div v-if="chat.loading" class="flex justify-center py-8">
          <span class="size-6 animate-spin rounded-full border-2 border-border border-t-accent" />
        </div>

        <div
          v-else-if="chat.messages.length === 0"
          class="flex h-full flex-col items-center justify-center gap-2 px-6 text-center"
        >
          <p class="text-sm text-text-muted">Todavía no hay mensajes aquí.</p>
          <p class="text-xs text-text-subtle">Saluda a los demás para empezar la conversación.</p>
        </div>

        <VirtualMessageList
          v-else
          ref="virtualList"
          :messages="chat.messages"
          :loading-more="chat.loadingMore"
          @reach-top="onScrollTop"
        >
          <template #message="{ message, index }">
            <MessageBubble
              :message="message"
              :is-own="message.user?.id === auth.user?.id"
              :grouped="
                index > 0 &&
                chat.messages[index - 1]?.user?.id === message.user?.id &&
                !message.reply_to
              "
              :show-author="
                index === 0 ||
                chat.messages[index - 1]?.user?.id !== message.user?.id ||
                Boolean(message.reply_to)
              "
              @edit="editMessage"
              @remove="deleteMessage"
              @react="reactToMessage"
              @reply="replyTo = $event"
              @report="reportMessage"
              @profile="openUser"
              @open-image="openImage"
            />
          </template>

          <template #loading="{ visible }">
            <div v-if="visible" class="flex justify-center py-3">
              <span
                class="size-5 animate-spin rounded-full border-2 border-border border-t-accent"
              />
            </div>
          </template>
        </VirtualMessageList>
      </div>

      <TypingIndicator :names="chat.typingNames" />

      <MessageInput
        :reply-to="replyTo"
        :sending="chat.sending"
        @send="sendMessage"
        @typing="onTyping"
        @cancel-reply="replyTo = null"
      />
    </div>

    <ProfileModal :open="showProfile" @close="showProfile = false" />

    <SearchModal
      :open="showSearch"
      @close="showSearch = false"
      @select-user="onSearchUser"
      @select-message="onSearchMessage"
    />

    <UserCard
      :open="showUserCard"
      :user="selectedUser"
      :blocked="selectedUserBlocked"
      :muted="selectedUserMuted"
      @close="showUserCard = false"
      @message="startDirectConversation"
    />

    <ImageViewer
      :src="viewerSrc ?? ''"
      :open="viewerSrc !== null"
      :caption="viewerCaption"
      @close="closeViewer"
    />
  </div>
</template>
