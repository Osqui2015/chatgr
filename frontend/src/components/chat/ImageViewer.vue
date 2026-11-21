<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'

/**
 * The props of the viewer.
 */
const props = defineProps<{
  /** The URL of the image to display. */
  src: string
  /** Whether the viewer is open. */
  open: boolean
  /** The message the image belongs to, used for the caption. */
  caption?: string
}>()

const emit = defineEmits<{
  close: []
}>()

const loaded = ref(false)
const failed = ref(false)

/**
 * Close the viewer when the escape key is pressed.
 */
function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    emit('close')
  }
}

onMounted(() => {
  window.addEventListener('keydown', onKeydown)
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
})

watch(
  () => props.src,
  () => {
    loaded.value = false
    failed.value = false
  },
)
</script>

<template>
  <Teleport to="body">
    <Transition name="fade">
      <div
        v-if="open"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/90 p-4 backdrop-blur-sm"
        role="dialog"
        aria-modal="true"
        @click.self="emit('close')"
      >
        <div class="relative flex max-h-full max-w-full animate-pop flex-col items-center">
          <img
            v-if="!failed"
            :src="src"
            alt="Imagen"
            class="h-auto max-h-[85vh] w-auto max-w-[95vw] rounded-lg object-contain shadow-2xl"
            :class="loaded ? 'opacity-100' : 'opacity-0'"
            @load="loaded = true"
            @error="failed = true"
          />

          <div
            v-else
            class="flex h-64 w-80 flex-col items-center justify-center gap-2 rounded-lg bg-surface-raised text-text-muted"
          >
            <svg
              class="size-10"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="1.5"
            >
              <path d="M4 5h16v14H4z" />
              <path d="m4 15 5-5 4 4 3-3 4 4" stroke-linecap="round" stroke-linejoin="round" />
              <circle cx="9" cy="9" r="1.5" />
            </svg>
            <p class="text-sm">Esta imagen ya no está disponible.</p>
          </div>

          <p v-if="caption" class="mt-3 text-center text-xs text-text-muted">{{ caption }}</p>

          <button
            type="button"
            class="absolute -right-2 -top-2 rounded-full bg-surface-overlay p-2 text-text-muted shadow-lg transition-colors hover:text-text"
            aria-label="Cerrar"
            @click="emit('close')"
          >
            <svg
              class="size-5"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="2"
            >
              <path d="m6 6 12 12M18 6 6 18" stroke-linecap="round" />
            </svg>
          </button>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.18s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
