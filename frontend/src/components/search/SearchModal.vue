<script setup lang="ts">
import { ref, watch } from 'vue'
import { useDebounceFn } from '@vueuse/core'

import UserAvatar from '@/components/UserAvatar.vue'
import { ApiError } from '@/services/http'
import { searchApi } from '@/services/api'
import type { Message, User } from '@/types'

/**
 * The global search modal.
 */
const props = defineProps<{
  open: boolean
}>()

const emit = defineEmits<{
  close: []
  selectUser: [userId: number]
  selectMessage: [message: Message]
}>()

const query = ref('')
const scope = ref<'all' | 'users' | 'messages'>('all')
const results = ref<{ users: User[]; messages: Message[] }>({ users: [], messages: [] })
const loading = ref(false)
const errorMessage = ref('')

/**
 * Run the search a moment after the user stops typing.
 */
const runSearch = useDebounceFn(async (term: string) => {
  if (term.trim().length < 2) {
    results.value = { users: [], messages: [] }

    return
  }

  loading.value = true
  errorMessage.value = ''

  try {
    const response = await searchApi.query(term, scope.value)

    results.value = {
      users: response.data.users,
      messages: response.data.messages,
    }
  } catch (error) {
    errorMessage.value =
      error instanceof ApiError ? error.message : 'No se pudo completar la búsqueda.'
  } finally {
    loading.value = false
  }
}, 300)

watch([query, scope], ([term]) => {
  void runSearch(term)
})

watch(
  () => props.open,
  (isOpen) => {
    if (!isOpen) {
      query.value = ''
      results.value = { users: [], messages: [] }
      errorMessage.value = ''
    }
  },
)
</script>

<template>
  <Teleport to="body">
    <Transition name="fade">
      <div
        v-if="props.open"
        class="fixed inset-0 z-40 flex items-start justify-center bg-black/70 p-4 pt-16 backdrop-blur-sm"
        role="dialog"
        aria-modal="true"
        @click.self="emit('close')"
      >
        <div class="panel w-full max-w-lg overflow-hidden">
          <div class="flex items-center gap-2 border-b border-border px-4 py-3">
            <svg
              class="size-4 shrink-0 text-text-subtle"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="2"
            >
              <circle cx="11" cy="11" r="6" />
              <path d="m16 16 4 4" stroke-linecap="round" />
            </svg>

            <input
              v-model="query"
              type="search"
              class="flex-1 bg-transparent text-sm text-text placeholder:text-text-subtle focus:outline-none"
              placeholder="Buscar usuarios y mensajes..."
              autofocus
            />

            <button
              type="button"
              class="rounded p-1 text-text-muted transition-colors hover:text-text"
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

          <div class="flex gap-1 border-b border-border px-3 py-2">
            <button
              v-for="option in ['all', 'users', 'messages'] as const"
              :key="option"
              type="button"
              class="rounded px-2.5 py-1 text-xs capitalize transition-colors"
              :class="
                scope === option
                  ? 'bg-surface-overlay text-text'
                  : 'text-text-muted hover:text-text'
              "
              @click="scope = option"
            >
              {{ option === 'all' ? 'Todo' : option === 'users' ? 'Usuarios' : 'Mensajes' }}
            </button>
          </div>

          <div class="max-h-96 overflow-y-auto p-2">
            <div v-if="errorMessage" class="px-3 py-4 text-sm text-danger">
              {{ errorMessage }}
            </div>

            <div v-else-if="loading" class="px-3 py-6 text-center text-sm text-text-subtle">
              Buscando...
            </div>

            <div
              v-else-if="query.trim().length < 2"
              class="px-3 py-6 text-center text-sm text-text-subtle"
            >
              Escribe al menos 2 caracteres.
            </div>

            <div
              v-else-if="results.users.length === 0 && results.messages.length === 0"
              class="px-3 py-6 text-center text-sm text-text-subtle"
            >
              No se encontraron resultados.
            </div>

            <template v-else>
              <section v-if="results.users.length">
                <p
                  class="px-2 py-1 text-[11px] font-medium uppercase tracking-wide text-text-subtle"
                >
                  Usuarios
                </p>
                <button
                  v-for="user in results.users"
                  :key="user.id"
                  type="button"
                  class="flex w-full items-center gap-3 rounded-lg px-2 py-2 text-left transition-colors hover:bg-surface-raised"
                  @click="emit('selectUser', user.id)"
                >
                  <UserAvatar :nickname="user.nickname" :src="user.avatar_url" :size="32" />
                  <span class="min-w-0 flex-1 truncate text-sm text-text">{{ user.nickname }}</span>
                </button>
              </section>

              <section v-if="results.messages.length" class="mt-2">
                <p
                  class="px-2 py-1 text-[11px] font-medium uppercase tracking-wide text-text-subtle"
                >
                  Mensajes
                </p>
                <button
                  v-for="message in results.messages"
                  :key="message.id"
                  type="button"
                  class="block w-full rounded-lg px-2 py-2 text-left transition-colors hover:bg-surface-raised"
                  @click="emit('selectMessage', message)"
                >
                  <p class="truncate text-xs font-medium text-text">
                    {{ message.user?.nickname ?? 'Desconocido' }}
                  </p>
                  <p class="truncate text-sm text-text-muted">{{ message.body }}</p>
                </button>
              </section>
            </template>
          </div>
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
