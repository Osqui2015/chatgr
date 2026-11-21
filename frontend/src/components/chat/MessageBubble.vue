<script setup lang="ts">
import { computed, ref } from 'vue'

import EmojiPicker from '@/components/emoji/EmojiPicker.vue'
import PresenceIndicator from '@/components/PresenceIndicator.vue'
import UserAvatar from '@/components/UserAvatar.vue'
import type { Message } from '@/types'

/**
 * The message being rendered.
 */
const props = defineProps<{
  message: Message
  /** Whether the message was sent by the authenticated user. */
  isOwn: boolean
  /** Whether the previous message belongs to the same author. */
  grouped: boolean
  /** Whether the message answers another one. */
  showAuthor: boolean
}>()

const emit = defineEmits<{
  edit: [id: number, body: string]
  remove: [id: number]
  react: [id: number, emoji: string]
  reply: [message: Message]
  report: [message: Message]
  profile: [userId: number]
  openImage: [message: Message]
}>()

const editing = ref(false)
const draft = ref('')
const showEmoji = ref(false)
const showActions = ref(false)
const copied = ref(false)

const isDeleted = computed(() => props.message.is_deleted)

const isViewOnce = computed(() => props.message.type === 'view_once')

const canOpenViewOnce = computed(
  () =>
    !props.isOwn &&
    props.message.view_once?.available === true &&
    props.message.view_once.url !== null,
)

const viewOnceHint = computed(() => {
  if (props.isOwn) {
    return props.message.view_once?.opened
      ? 'Abierta por el destinatario'
      : 'Solo el destinatario puede abrirla'
  }

  return canOpenViewOnce.value ? 'Se destruirá al abrirla' : 'Ya no está disponible'
})

const time = computed(() => {
  if (!props.message.created_at) {
    return ''
  }

  return new Date(props.message.created_at).toLocaleTimeString('es', {
    hour: '2-digit',
    minute: '2-digit',
  })
})

/**
 * Start editing the message.
 */
function startEditing(): void {
  draft.value = props.message.body ?? ''
  editing.value = true
  showActions.value = false
}

/**
 * Cancel the edit.
 */
function cancelEditing(): void {
  editing.value = false
  draft.value = ''
}

/**
 * Save the edit.
 */
function saveEdit(): void {
  const body = draft.value.trim()

  if (body === '' || body === props.message.body) {
    cancelEditing()

    return
  }

  emit('edit', props.message.id, body)
  cancelEditing()
}

/**
 * Open the author profile card.
 */
function openProfile(): void {
  if (props.message.user) {
    emit('profile', props.message.user.id)
  }
}

/**
 * Copy the message body to the clipboard.
 */
async function copy(): Promise<void> {
  if (!props.message.body) {
    return
  }

  try {
    await navigator.clipboard.writeText(props.message.body)
    copied.value = true
    window.setTimeout(() => {
      copied.value = false
    }, 1600)
  } catch {
    // The clipboard may be unavailable; the failure is silent on purpose.
  }

  showActions.value = false
}
</script>

<template>
  <article
    class="group relative flex gap-3 px-4 transition-colors hover:bg-surface/40"
    :class="[grouped ? 'mt-0.5' : 'mt-3', isOwn ? 'flex-row-reverse' : '']"
  >
    <div class="w-10 shrink-0">
      <button
        v-if="!grouped && message.user"
        type="button"
        class="mt-0.5 block rounded-full focus-visible:ring-2 focus-visible:ring-accent"
        :title="`Ver perfil de ${message.user.nickname}`"
        @click="openProfile"
      >
        <UserAvatar :nickname="message.user.nickname" :src="message.user.avatar_url" :size="40" />
      </button>
    </div>

    <div class="min-w-0 flex-1">
      <header v-if="showAuthor" class="mb-1 flex items-center gap-2">
        <button
          type="button"
          class="max-w-full truncate text-sm font-semibold text-text hover:underline"
          @click="openProfile"
        >
          {{ message.user?.nickname ?? 'Desconocido' }}
        </button>
        <span class="text-xs text-text-subtle">{{ time }}</span>
        <PresenceIndicator
          v-if="message.user"
          :status="message.user.status"
          compact
          class="text-text-subtle"
        />
        <span
          v-if="message.edited_at"
          class="text-xs italic text-text-subtle"
          title="Este mensaje fue editado"
        >
          (editado)
        </span>
      </header>

      <div
        class="relative rounded-bubble px-3.5 py-2 text-sm leading-relaxed"
        :class="[
          isOwn
            ? 'rounded-tr-sm bg-accent text-white'
            : 'rounded-tl-sm bg-surface-raised text-text',
          isDeleted ? 'italic text-text-subtle' : '',
          editing ? 'ring-1 ring-accent' : '',
        ]"
      >
        <div v-if="message.reply_to" class="mb-1.5 border-l-2 border-accent/60 pl-2 text-xs">
          <p class="font-semibold text-accent">
            {{ message.reply_to.user?.nickname ?? 'Desconocido' }}
          </p>
          <p class="truncate text-text-muted">
            {{ message.reply_to.body ?? 'Mensaje eliminado' }}
          </p>
        </div>

        <div v-if="isDeleted" class="text-text-subtle">Este mensaje fue eliminado.</div>

        <template v-else-if="editing">
          <textarea
            v-model="draft"
            class="field min-h-20 resize-y bg-transparent"
            maxlength="4000"
            @keydown.enter.exact.prevent="saveEdit"
            @keydown.escape.prevent="cancelEditing"
          />
          <div class="mt-2 flex items-center gap-2">
            <button type="button" class="btn-primary !px-3 !py-1 text-xs" @click="saveEdit">
              Guardar
            </button>
            <button type="button" class="btn-ghost !px-3 !py-1 text-xs" @click="cancelEditing">
              Cancelar
            </button>
          </div>
        </template>

        <template v-else>
          <p v-if="message.body" class="break-words whitespace-pre-wrap">{{ message.body }}</p>

          <button
            v-if="message.attachment?.url"
            type="button"
            class="mt-2 block max-h-80 max-w-full overflow-hidden rounded-lg"
            title="Ampliar imagen"
            @click="emit('openImage', message)"
          >
            <img
              :src="message.attachment.url"
              :alt="'Imagen adjunta al mensaje'"
              class="max-h-80 max-w-full object-cover"
              loading="lazy"
            />
          </button>

          <div
            v-if="isViewOnce"
            class="mt-1 flex max-w-sm items-center gap-3 rounded-xl border p-3"
            :class="
              isOwn
                ? 'border-white/25 bg-black/10 text-white'
                : 'border-border bg-surface text-text'
            "
          >
            <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-black/10">
              <svg
                class="size-5"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.7"
              >
                <path
                  d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"
                  stroke-linejoin="round"
                />
                <circle cx="12" cy="12" r="2.5" />
                <path d="m4 4 16 16" stroke-linecap="round" />
              </svg>
            </div>

            <div class="min-w-0 flex-1">
              <p class="text-sm font-semibold">Imagen de un solo uso</p>
              <p class="text-xs opacity-80">{{ viewOnceHint }}</p>
            </div>

            <button
              v-if="canOpenViewOnce"
              type="button"
              class="shrink-0 rounded-full px-3 py-1.5 text-xs font-semibold transition-colors"
              :class="
                isOwn
                  ? 'bg-white/20 hover:bg-white/30'
                  : 'bg-accent/15 hover:bg-accent/25 text-accent'
              "
              @click="emit('openImage', message)"
            >
              Abrir
            </button>
          </div>

          <div v-if="message.reactions.length" class="mt-1.5 flex flex-wrap gap-1">
            <button
              v-for="group in message.reactions"
              :key="group.emoji"
              type="button"
              class="flex items-center gap-1 rounded-full border px-1.5 py-0.5 text-xs transition-colors"
              :class="
                group.user_ids.includes(0)
                  ? 'border-accent bg-accent/15'
                  : 'border-border bg-surface-raised hover:border-border-strong'
              "
              @click="emit('react', message.id, group.emoji)"
            >
              <span>{{ group.emoji }}</span>
              <span class="text-text-muted">{{ group.count }}</span>
            </button>
          </div>
        </template>
      </div>
    </div>

    <!--
      The action bar is revealed on hover on pointer devices. Touch devices
      have no hover, so the bar stays visible there and the buttons keep a
      large enough target to be usable with a finger.
    -->
    <div
      v-if="!isDeleted && !editing"
      class="absolute top-0 flex items-center gap-0.5 rounded-lg border border-border bg-surface-overlay px-1 py-0.5 shadow-lg transition-opacity md:opacity-0 md:group-hover:opacity-100 md:group-focus-within:opacity-100"
      :class="isOwn ? 'left-2 md:-left-1' : 'right-2 md:-right-1'"
    >
      <button
        type="button"
        class="rounded p-1.5 text-text-muted transition-colors hover:bg-surface-raised hover:text-text md:p-1"
        title="Reaccionar"
        @click="showEmoji = !showEmoji"
      >
        <svg
          class="size-4"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="1.8"
        >
          <circle cx="12" cy="12" r="9" />
          <path d="M8.5 14.5s1.3 1.8 3.5 1.8 3.5-1.8 3.5-1.8" stroke-linecap="round" />
          <path d="M9 9.5h.01M15 9.5h.01" stroke-linecap="round" />
        </svg>
      </button>
      <button
        type="button"
        class="rounded p-1.5 text-text-muted transition-colors hover:bg-surface-raised hover:text-text md:p-1"
        title="Responder"
        @click="emit('reply', message)"
      >
        <svg
          class="size-4"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="1.8"
        >
          <path d="M9 14 4 9l5-5" stroke-linecap="round" stroke-linejoin="round" />
          <path d="M4 9h9a7 7 0 0 1 7 7v4" stroke-linecap="round" />
        </svg>
      </button>
      <button
        v-if="message.body"
        type="button"
        class="rounded p-1.5 text-text-muted transition-colors hover:bg-surface-raised hover:text-text md:p-1"
        :title="copied ? 'Copiado' : 'Copiar'"
        @click="copy"
      >
        <svg
          class="size-4"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="1.8"
        >
          <rect x="9" y="9" width="11" height="11" rx="2" />
          <path d="M5 15V6a2 2 0 0 1 2-2h9" stroke-linecap="round" />
        </svg>
      </button>
      <button
        v-if="isOwn && !isViewOnce"
        type="button"
        class="rounded p-1.5 text-text-muted transition-colors hover:bg-surface-raised hover:text-text md:p-1"
        title="Editar"
        @click="startEditing"
      >
        <svg
          class="size-4"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="1.8"
        >
          <path d="M4 20h4l10-10-4-4L4 16v4Z" stroke-linejoin="round" />
          <path d="m13.5 6.5 4 4" stroke-linecap="round" />
        </svg>
      </button>
      <button
        v-if="isOwn"
        type="button"
        class="rounded p-1.5 text-text-muted transition-colors hover:bg-danger/20 hover:text-danger md:p-1"
        title="Eliminar"
        @click="emit('remove', message.id)"
      >
        <svg
          class="size-4"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="1.8"
        >
          <path
            d="M5 7h14M10 7V5h4v2M6 7l1 13h10l1-13"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
        </svg>
      </button>
      <button
        v-else
        type="button"
        class="rounded p-1.5 text-text-muted transition-colors hover:bg-surface-raised hover:text-text md:p-1"
        title="Denunciar"
        @click="emit('report', message)"
      >
        <svg
          class="size-4"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="1.8"
        >
          <path
            d="M5 21V4M5 5h11l-1.5 3.5L16 12H5"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
        </svg>
      </button>
    </div>

    <EmojiPicker
      v-if="showEmoji"
      class="absolute top-8 z-30"
      :class="isOwn ? 'right-2' : 'left-2'"
      @select="
        (emoji) => {
          emit('react', message.id, emoji)
          showEmoji = false
        }
      "
      @close="showEmoji = false"
    />
  </article>
</template>
