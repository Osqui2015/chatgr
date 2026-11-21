<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useDebounceFn } from '@vueuse/core'

import EmojiPicker from '@/components/emoji/EmojiPicker.vue'
import type { Message } from '@/types'

/**
 * The props of the input.
 */
const props = defineProps<{
  /** The message that is being answered. */
  replyTo?: Message | null
  /** Whether the input is disabled. */
  disabled?: boolean
  /** Whether a message is being sent. */
  sending?: boolean
}>()

const emit = defineEmits<{
  send: [payload: { body?: string; reply_to_id?: number; image?: File; view_once?: boolean }]
  typing: [isTyping: boolean]
  cancelReply: []
}>()

const body = ref('')
const selectedFile = ref<File | null>(null)
const selectedFileUrl = ref<string | null>(null)
const viewOnce = ref(false)
const showEmoji = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)
const textarea = ref<HTMLTextAreaElement | null>(null)

const canSend = computed(() => {
  if (viewOnce.value) {
    return selectedFile.value !== null
  }

  return body.value.trim().length > 0 || selectedFile.value !== null
})

/**
 * The maximum size of an upload, in bytes.
 */
const MAX_FILE_SIZE = 20 * 1024 * 1024

const MAX_FILE_SIZE_MB = MAX_FILE_SIZE / (1024 * 1024)

/**
 * The image formats the application accepts.
 */
const ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp']

/**
 * Notify the other participants while the user types.
 *
 * The notification is throttled so a fast typist does not flood the socket.
 */
const notifyTyping = useDebounceFn((isTyping: boolean) => emit('typing', isTyping), 400)

watch(body, (value) => {
  notifyTyping(value.trim().length > 0)
})

/**
 * Send the current content and reset the input.
 */
async function send(): Promise<void> {
  if (!canSend.value || props.sending) {
    return
  }

  const payload: { body?: string; reply_to_id?: number; image?: File; view_once?: boolean } = {}

  if (!viewOnce.value && body.value.trim()) {
    payload.body = body.value.trim()
  }

  if (selectedFile.value) {
    payload.image = selectedFile.value
  }

  if (viewOnce.value && selectedFile.value) {
    payload.view_once = true
  }

  if (props.replyTo) {
    payload.reply_to_id = props.replyTo.id
  }

  emit('send', payload)

  body.value = ''
  clearFile()
  showEmoji.value = false
  emit('typing', false)

  await nextTick()
  textarea.value?.focus()
}

/**
 * Discard the selected file and release its preview.
 */
function clearFile(): void {
  if (selectedFileUrl.value) {
    URL.revokeObjectURL(selectedFileUrl.value)
  }

  selectedFile.value = null
  selectedFileUrl.value = null
  viewOnce.value = false
}

/**
 * Open the file picker in normal or view-once mode.
 */
function pickFile(oneTime = false): void {
  viewOnce.value = oneTime
  fileInput.value?.click()
}

/**
 * Handle the chosen file.
 */
function onFileChange(event: Event): void {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]

  if (!file) {
    return
  }

  if (!ACCEPTED_TYPES.includes(file.type)) {
    input.value = ''

    return
  }

  if (file.size > MAX_FILE_SIZE) {
    input.value = ''

    return
  }

  selectedFile.value = file
  selectedFileUrl.value = URL.createObjectURL(file)
}

/**
 * Insert an emoji at the end of the current text.
 */
function insertEmoji(emoji: string): void {
  body.value += emoji
  showEmoji.value = false
  textarea.value?.focus()
}
</script>

<template>
  <div class="relative border-t border-border bg-surface">
    <div v-if="replyTo" class="flex items-center gap-2 border-b border-border px-4 py-2 text-xs">
      <div class="min-w-0 flex-1 border-l-2 border-accent pl-2">
        <p class="font-semibold text-accent">{{ replyTo.user?.nickname ?? 'Desconocido' }}</p>
        <p class="truncate text-text-muted">{{ replyTo.body ?? 'Mensaje eliminado' }}</p>
      </div>
      <button
        type="button"
        class="rounded p-1 text-text-muted transition-colors hover:bg-surface-raised hover:text-text"
        aria-label="Cancelar la respuesta"
        @click="emit('cancelReply')"
      >
        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="m6 6 12 12M18 6 6 18" stroke-linecap="round" />
        </svg>
      </button>
    </div>

    <div v-if="selectedFile" class="flex items-center gap-2 border-b border-border px-4 py-2">
      <img :src="selectedFileUrl ?? ''" alt="Adjunto" class="size-12 rounded object-cover" />
      <div class="min-w-0 flex-1">
        <p class="truncate text-xs text-text-muted">
          {{ selectedFile.name }} ({{ (selectedFile.size / 1024).toFixed(0) }} KB)
        </p>
        <p v-if="viewOnce" class="text-[11px] font-semibold text-accent">
          Se verá una sola vez y se destruirá al abrirse.
        </p>
      </div>
      <button
        type="button"
        class="rounded-full border border-border px-2 py-1 text-[11px] text-text-muted transition-colors hover:border-accent hover:text-accent"
        @click="viewOnce = !viewOnce"
      >
        {{ viewOnce ? 'Enviar normal' : 'Ver una vez' }}
      </button>
      <button
        type="button"
        class="rounded p-1 text-text-muted transition-colors hover:bg-surface-raised hover:text-text"
        aria-label="Quitar el archivo"
        @click="clearFile"
      >
        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="m6 6 12 12M18 6 6 18" stroke-linecap="round" />
        </svg>
      </button>
    </div>

    <div class="flex items-end gap-2 px-3 py-3">
      <button
        type="button"
        class="btn-ghost !p-2"
        title="Adjuntar imagen"
        :disabled="disabled"
        @click="pickFile(false)"
      >
        <svg
          class="size-5"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="1.8"
        >
          <path
            d="M21 11.5 12.5 20a5 5 0 0 1-7-7l8-8a3.5 3.5 0 1 1 5 5l-8 8a2 2 0 0 1-3-3l7-7"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
        </svg>
      </button>

      <button
        type="button"
        class="btn-ghost !p-2"
        :class="viewOnce ? 'text-accent' : ''"
        title="Enviar imagen de un solo uso"
        :disabled="disabled"
        @click="pickFile(true)"
      >
        <svg
          class="size-5"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="1.8"
        >
          <circle cx="12" cy="12" r="9" />
          <path d="M12 7v5l3 2" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
      </button>

      <div class="relative">
        <button
          type="button"
          class="btn-ghost !p-2"
          title="Emoji"
          :disabled="disabled || viewOnce"
          @click="showEmoji = !showEmoji"
        >
          <svg
            class="size-5"
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
      </div>

      <textarea
        ref="textarea"
        v-model="body"
        rows="1"
        class="field max-h-40 min-h-10 flex-1 resize-none py-2.5"
        :disabled="disabled || viewOnce"
        :placeholder="
          disabled
            ? 'No puedes escribir en esta conversación'
            : viewOnce
              ? 'La imagen de un solo uso no lleva texto'
              : 'Escribe un mensaje...'
        "
        maxlength="4000"
        @keydown.enter.exact.prevent="send"
      />

      <button
        type="button"
        class="btn-primary !p-2.5"
        :disabled="!canSend || sending || disabled"
        aria-label="Enviar"
        @click="send"
      >
        <svg
          class="size-5"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="1.8"
        >
          <path d="m4 12 16-8-6 16-2.5-6.5L4 12Z" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
      </button>

      <input
        ref="fileInput"
        type="file"
        class="hidden"
        accept="image/jpeg,image/png,image/webp"
        @change="onFileChange"
      />
    </div>

    <!--
      The picker is anchored to the composer, not to the button, so it stays
      inside the viewport on narrow screens.
    -->
    <EmojiPicker
      v-if="showEmoji"
      class="absolute bottom-full left-3 right-3 z-30 mb-2"
      @select="insertEmoji"
      @close="showEmoji = false"
    />

    <p class="px-4 pb-2 text-[11px] text-text-subtle">
      <template v-if="viewOnce">
        La imagen solo podrá abrirse una vez y se destruirá después de verse.
      </template>
      <template v-else>
        Las imágenes se comprimen automáticamente. Formatos: JPG, PNG y WebP (máx.
        {{ MAX_FILE_SIZE_MB }} MB).
      </template>
    </p>
  </div>
</template>
