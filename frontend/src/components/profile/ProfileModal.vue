<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRouter } from 'vue-router'

import AvatarCropper from '@/components/profile/AvatarCropper.vue'
import PresenceIndicator from '@/components/PresenceIndicator.vue'
import UserAvatar from '@/components/UserAvatar.vue'
import { ApiError } from '@/services/http'
import { userApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import type { SexualRole, UserStatus } from '@/types'

/**
 * The profile modal lets the user change their avatar, nickname and presence.
 */
const props = defineProps<{
  open: boolean
}>()

const emit = defineEmits<{
  close: []
}>()

const auth = useAuthStore()
const router = useRouter()

const nickname = ref('')
const saving = ref(false)
const loggingOut = ref(false)
const errorMessage = ref('')
const notice = ref('')

const cropSource = ref<string | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)

/**
 * The presence states the user can choose.
 */
const STATUSES: Array<{ value: UserStatus; label: string }> = [
  { value: 'online', label: 'En línea' },
  { value: 'away', label: 'Ausente' },
  { value: 'busy', label: 'Ocupado' },
  { value: 'invisible', label: 'Invisible' },
]

/**
 * The personal roles the user can choose.
 */
const ROLES: Array<{ value: SexualRole; label: string }> = [
  { value: 'activo', label: 'Activo' },
  { value: 'versatil', label: 'Versátil' },
  { value: 'pasivo', label: 'Pasivo' },
]

const selectedRole = ref<SexualRole | null>(null)

/**
 * Reset the form when the modal opens.
 */
function syncForm(): void {
  nickname.value = auth.user?.nickname ?? ''
  selectedRole.value = auth.user?.sexual_role ?? null
  errorMessage.value = ''
  notice.value = ''
}

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) {
      syncForm()
    }
  },
  { immediate: true },
)

/**
 * End the session and return to the login screen.
 */
async function endSession(): Promise<void> {
  loggingOut.value = true
  errorMessage.value = ''

  try {
    await auth.logout()

    emit('close')

    await router.push({ name: 'login' })
  } catch (error) {
    errorMessage.value = error instanceof ApiError ? error.message : 'No se pudo cerrar la sesión.'
  } finally {
    loggingOut.value = false
  }
}

/**
 * Choose an avatar file and open the cropper.
 */
function onFileChange(event: Event): void {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]

  input.value = ''

  if (!file) {
    return
  }

  cropSource.value = URL.createObjectURL(file)
}

/**
 * Upload the cropped avatar.
 */
async function onCropped(blob: Blob): Promise<void> {
  cropSource.value = null
  saving.value = true
  errorMessage.value = ''

  try {
    const file = new File([blob], 'avatar.webp', { type: 'image/webp' })
    const response = await userApi.updateAvatar(file)

    auth.setUser(response.user)
    notice.value = 'Avatar actualizado.'
  } catch (error) {
    errorMessage.value =
      error instanceof ApiError ? error.message : 'No se pudo actualizar el avatar.'
  } finally {
    saving.value = false
  }
}

/**
 * Remove the current avatar.
 */
async function removeAvatar(): Promise<void> {
  saving.value = true
  errorMessage.value = ''

  try {
    const response = await userApi.deleteAvatar()

    auth.setUser(response.user)
    notice.value = 'Avatar eliminado.'
  } catch (error) {
    errorMessage.value =
      error instanceof ApiError ? error.message : 'No se pudo eliminar el avatar.'
  } finally {
    saving.value = false
  }
}

/**
 * Save the new nickname.
 */
async function saveNickname(): Promise<void> {
  const value = nickname.value.trim()

  if (value === '' || value === auth.user?.nickname) {
    return
  }

  saving.value = true
  errorMessage.value = ''

  try {
    const response = await userApi.update({ nickname: value })

    auth.setUser(response.user)
    notice.value = 'Nombre actualizado.'
  } catch (error) {
    errorMessage.value =
      error instanceof ApiError ? error.message : 'No se pudo actualizar el nombre.'
  } finally {
    saving.value = false
  }
}

/**
 * Change the presence state.
 */
async function changeStatus(status: UserStatus): Promise<void> {
  try {
    await userApi.updateStatus(status)

    if (auth.user) {
      auth.setUser({ ...auth.user, status })
    }
  } catch (error) {
    errorMessage.value = error instanceof ApiError ? error.message : 'No se pudo cambiar el estado.'
  }
}

/**
 * Select or toggle a personal role (Activo, Versátil, Pasivo).
 */
async function changeRole(role: SexualRole): Promise<void> {
  const newRole = selectedRole.value === role ? null : role
  selectedRole.value = newRole
  saving.value = true
  errorMessage.value = ''

  try {
    const response = await userApi.update({ sexual_role: newRole })
    auth.setUser(response.user)
    notice.value = newRole ? 'Rol actualizado.' : 'Rol eliminado.'
  } catch (error) {
    selectedRole.value = auth.user?.sexual_role ?? null
    errorMessage.value = error instanceof ApiError ? error.message : 'No se pudo actualizar el rol.'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <Transition name="fade">
      <div
        v-if="props.open"
        class="fixed inset-0 z-40 flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm"
        role="dialog"
        aria-modal="true"
        @click.self="emit('close')"
      >
        <div class="panel w-full max-w-md overflow-hidden">
          <header class="flex items-center justify-between border-b border-border px-5 py-3">
            <h2 class="text-sm font-semibold text-text">Mi perfil</h2>
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
          </header>

          <div class="space-y-5 p-5">
            <div v-if="errorMessage" class="rounded-lg bg-danger/15 px-3 py-2 text-sm text-danger">
              {{ errorMessage }}
            </div>

            <div v-if="notice" class="rounded-lg bg-success/15 px-3 py-2 text-sm text-success">
              {{ notice }}
            </div>

            <div class="flex items-center gap-4">
              <UserAvatar
                :nickname="auth.user?.nickname ?? '?'"
                :src="auth.user?.avatar_url ?? null"
                :size="64"
              />

              <div class="flex flex-col gap-2">
                <button
                  type="button"
                  class="btn-secondary !py-1.5 text-xs"
                  :disabled="saving"
                  @click="fileInput?.click()"
                >
                  Cambiar foto
                </button>

                <button
                  v-if="auth.user?.avatar_url"
                  type="button"
                  class="btn-ghost !py-1.5 text-xs"
                  :disabled="saving"
                  @click="removeAvatar"
                >
                  Quitar foto
                </button>
              </div>
            </div>

            <div>
              <label
                for="profile-nickname"
                class="mb-1.5 block text-xs font-medium text-text-muted"
              >
                Nombre de usuario
              </label>
              <div class="flex gap-2">
                <input
                  id="profile-nickname"
                  v-model="nickname"
                  type="text"
                  class="field"
                  minlength="3"
                  maxlength="32"
                  @keydown.enter.prevent="saveNickname"
                />
                <button
                  type="button"
                  class="btn-secondary shrink-0"
                  :disabled="saving"
                  @click="saveNickname"
                >
                  Guardar
                </button>
              </div>
            </div>

            <div>
              <p class="mb-2 text-xs font-medium text-text-muted">Estado</p>
              <div class="flex flex-wrap gap-2">
                <button
                  v-for="status in STATUSES"
                  :key="status.value"
                  type="button"
                  class="rounded-lg border px-3 py-1.5 text-xs transition-colors"
                  :class="
                    auth.user?.status === status.value
                      ? 'border-accent bg-accent/15 text-text'
                      : 'border-border text-text-muted hover:border-border-strong hover:text-text'
                  "
                  @click="changeStatus(status.value)"
                >
                  {{ status.label }}
                </button>
              </div>
              <div v-if="auth.user" class="mt-2">
                <PresenceIndicator :status="auth.user.status" />
              </div>
            </div>

            <div>
              <p class="mb-2 text-xs font-medium text-text-muted">Rol</p>
              <div class="flex flex-wrap gap-2">
                <button
                  v-for="role in ROLES"
                  :key="role.value"
                  type="button"
                  class="rounded-lg border px-3 py-1.5 text-xs transition-colors"
                  :class="
                    auth.user?.sexual_role === role.value
                      ? 'border-accent bg-accent/15 text-text font-medium'
                      : 'border-border text-text-muted hover:border-border-strong hover:text-text'
                  "
                  :disabled="saving"
                  @click="changeRole(role.value)"
                >
                  {{ role.label }}
                </button>
              </div>
            </div>

            <div class="border-t border-border pt-4">
              <p v-if="auth.user?.email" class="text-xs text-text-subtle">
                Correo: {{ auth.user.email }}
              </p>
              <p v-if="auth.user?.created_at" class="text-xs text-text-subtle">
                Miembro desde
                {{ new Date(auth.user.created_at).toLocaleDateString('es') }}
              </p>

              <button
                type="button"
                class="btn-danger mt-4 w-full"
                :disabled="loggingOut"
                @click="endSession"
              >
                {{ loggingOut ? 'Cerrando sesión...' : 'Cerrar sesión' }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>

  <AvatarCropper
    v-if="cropSource"
    :src="cropSource"
    open
    @cropped="onCropped"
    @close="cropSource = null"
  />

  <input
    ref="fileInput"
    type="file"
    class="hidden"
    accept="image/jpeg,image/png,image/webp,image/heic,image/heif"
    @change="onFileChange"
  />
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
