<script setup lang="ts">
import { computed } from 'vue'

import PresenceIndicator from '@/components/PresenceIndicator.vue'
import UserAvatar from '@/components/UserAvatar.vue'
import type { Conversation } from '@/types'

/**
 * The props of the sidebar.
 */
const props = defineProps<{
  conversations: Conversation[]
  activeId: number | null
  /** The presence of each known user. */
  presence: Record<number, string>
  /** The name of the global room. */
  globalTitle: string
}>()

const emit = defineEmits<{
  select: [conversationId: number]
  search: []
  newConversation: []
}>()

/**
 * The conversations sorted so the most recent one comes first.
 */
const sorted = computed(() =>
  [...props.conversations].sort((a, b) => {
    const aTime = a.last_message_at ? new Date(a.last_message_at).getTime() : 0
    const bTime = b.last_message_at ? new Date(b.last_message_at).getTime() : 0

    return bTime - aTime
  }),
)

/**
 * The title of a conversation.
 */
function titleOf(conversation: Conversation): string {
  if (conversation.type === 'global') {
    return conversation.title ?? props.globalTitle
  }

  const other = conversation.participants.find((participant) => participant.id !== conversation.id)

  return other?.nickname ?? 'Conversación'
}

/**
 * The status of the other participant of a direct conversation.
 */
function statusOf(
  conversation: Conversation,
): 'online' | 'away' | 'busy' | 'invisible' | 'offline' {
  if (conversation.type === 'global') {
    return 'online'
  }

  const other = conversation.participants.find((participant) => participant.id !== conversation.id)

  if (!other) {
    return 'offline'
  }

  return (props.presence[other.id] as never) ?? other.status
}
</script>

<template>
  <aside class="flex h-full w-full flex-col border-r border-border bg-surface md:w-72">
    <header class="flex items-center gap-2 border-b border-border px-4 py-3">
      <h1 class="text-base font-semibold text-text">Chat</h1>

      <button type="button" class="btn-ghost ml-auto !p-2" title="Buscar" @click="emit('search')">
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

      <button
        type="button"
        class="btn-ghost !p-2"
        title="Nueva conversación"
        @click="emit('newConversation')"
      >
        <svg
          class="size-5"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="1.8"
        >
          <path d="M12 5v14M5 12h14" stroke-linecap="round" />
        </svg>
      </button>
    </header>

    <nav class="min-h-0 flex-1 overflow-y-auto p-2">
      <ul class="space-y-0.5">
        <li v-for="conversation in sorted" :key="conversation.id">
          <button
            type="button"
            class="flex w-full items-center gap-3 rounded-lg px-2.5 py-2 text-left transition-colors"
            :class="
              conversation.id === activeId
                ? 'bg-surface-overlay text-text'
                : 'text-text-muted hover:bg-surface-raised hover:text-text'
            "
            @click="emit('select', conversation.id)"
          >
            <UserAvatar
              :nickname="titleOf(conversation)"
              :src="conversation.participants[0]?.avatar_url ?? null"
              :size="36"
              :rounded="conversation.type === 'global'"
            />

            <span class="min-w-0 flex-1">
              <span class="block truncate text-sm font-medium">{{ titleOf(conversation) }}</span>
              <span class="block truncate text-xs text-text-subtle">
                <template v-if="conversation.type === 'global'">Sala pública</template>
                <template v-else>
                  <PresenceIndicator :status="statusOf(conversation)" compact />
                  <span class="ml-1">
                    {{ statusOf(conversation) === 'online' ? 'En línea' : 'Desconectado' }}
                  </span>
                </template>
              </span>
            </span>

            <span
              v-if="conversation.unread_count > 0"
              class="flex size-5 shrink-0 items-center justify-center rounded-full bg-accent text-[11px] font-semibold text-white"
            >
              {{ conversation.unread_count > 99 ? '99+' : conversation.unread_count }}
            </span>
          </button>
        </li>
      </ul>

      <p v-if="sorted.length === 0" class="px-3 py-8 text-center text-xs text-text-subtle">
        Todavía no tienes conversaciones.
      </p>
    </nav>
  </aside>
</template>
