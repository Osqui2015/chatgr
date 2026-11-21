<script setup lang="ts">
import { computed, ref, watch } from 'vue'

import PresenceIndicator from '@/components/PresenceIndicator.vue'
import UserAvatar from '@/components/UserAvatar.vue'
import { ApiError } from '@/services/http'
import { reportApi, userApi } from '@/services/api'
import type { User } from '@/types'

/**
 * The card that shows the public profile of another user.
 */
const props = defineProps<{
  open: boolean
  user: User | null
  /** Whether the authenticated user already blocked this user. */
  blocked?: boolean
  /** Whether the authenticated user already muted this user. */
  muted?: boolean
}>()

const emit = defineEmits<{
  close: []
  message: [userId: number]
}>()

const busy = ref(false)
const notice = ref('')
const errorMessage = ref('')
const showReport = ref(false)
const reportReason = ref<'spam' | 'harassment' | 'nudity' | 'violence' | 'other'>('spam')
const reportDetails = ref('')
const blocked = ref(props.blocked ?? false)
const muted = ref(props.muted ?? false)

watch(
  () => props.blocked,
  (value) => {
    blocked.value = value ?? false
  },
)

watch(
  () => props.muted,
  (value) => {
    muted.value = value ?? false
  },
)

const ROLE_LABELS: Record<string, string> = {
  activo: 'Activo',
  versatil: 'Versátil',
  pasivo: 'Pasivo',
}

/**
 * The user's role (Activo, Versátil, Pasivo) in a readable form.
 */
const roleLabel = computed(() => {
  if (!props.user?.sexual_role) {
    return null
  }
  return (
    ROLE_LABELS[props.user.sexual_role] ?? props.user.sexual_role_label ?? props.user.sexual_role
  )
})

/**
 * The last time the user was seen, in a readable form.
 */
const lastSeen = computed(() => {
  if (!props.user?.last_seen_at) {
    return null
  }

  const date = new Date(props.user.last_seen_at)
  const diff = Date.now() - date.getTime()

  const minutes = Math.floor(diff / 60000)

  if (minutes < 1) {
    return 'Última conexión hace instantes'
  }

  if (minutes < 60) {
    return `Última conexión hace ${minutes} min`
  }

  const hours = Math.floor(minutes / 60)

  if (hours < 24) {
    return `Última conexión hace ${hours} h`
  }

  const days = Math.floor(hours / 24)

  return `Última conexión hace ${days} d`
})

/**
 * Block or unblock the user.
 */
async function toggleBlock(): Promise<void> {
  if (!props.user) {
    return
  }

  busy.value = true
  errorMessage.value = ''

  try {
    if (blocked.value) {
      await userApi.unblock(props.user.id)
      blocked.value = false
      notice.value = 'Usuario desbloqueado.'
    } else {
      await userApi.block(props.user.id)
      blocked.value = true
      notice.value = 'Usuario bloqueado.'
    }
  } catch (error) {
    errorMessage.value =
      error instanceof ApiError ? error.message : 'No se pudo completar la acción.'
  } finally {
    busy.value = false
  }
}

/**
 * Mute or unmute the user's messages.
 */
async function toggleMute(): Promise<void> {
  if (!props.user) {
    return
  }

  busy.value = true
  errorMessage.value = ''

  try {
    if (muted.value) {
      await userApi.unmute(props.user.id)
      muted.value = false
      notice.value = 'Usuario reactivado.'
    } else {
      await userApi.mute(props.user.id)
      muted.value = true
      notice.value = 'Usuario silenciado. Sus mensajes ya no se mostrarán.'
    }
  } catch (error) {
    errorMessage.value =
      error instanceof ApiError ? error.message : 'No se pudo completar la acción.'
  } finally {
    busy.value = false
  }
}

/**
 * File a report about the user.
 */
async function submitReport(): Promise<void> {
  if (!props.user) {
    return
  }

  busy.value = true
  errorMessage.value = ''

  try {
    await reportApi.create({
      reported_user_id: props.user.id,
      reason: reportReason.value,
      details: reportDetails.value || undefined,
    })

    notice.value = 'Denuncia registrada.'
    showReport.value = false
    reportDetails.value = ''
  } catch (error) {
    errorMessage.value =
      error instanceof ApiError ? error.message : 'No se pudo registrar la denuncia.'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <Transition name="fade">
      <div
        v-if="props.open && props.user"
        class="fixed inset-0 z-40 flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm"
        role="dialog"
        aria-modal="true"
        @click.self="emit('close')"
      >
        <div class="panel w-full max-w-sm overflow-hidden">
          <div class="flex flex-col items-center gap-3 border-b border-border px-5 py-6">
            <UserAvatar :nickname="props.user.nickname" :src="props.user.avatar_url" :size="72" />
            <div class="text-center">
              <p class="text-base font-semibold text-text">{{ props.user.nickname }}</p>
              <PresenceIndicator :status="props.user.status" class="mt-1 justify-center" />
              <div
                v-if="roleLabel"
                class="mt-2 inline-flex items-center gap-1.5 rounded-full border border-accent/40 bg-accent/15 px-3 py-0.5 text-xs font-medium text-text"
              >
                <span class="text-text-muted">Rol:</span>
                <span class="text-accent font-semibold">{{ roleLabel }}</span>
              </div>
              <p v-if="lastSeen" class="mt-1 text-xs text-text-subtle">{{ lastSeen }}</p>
            </div>
          </div>

          <div class="space-y-2 p-4">
            <div v-if="errorMessage" class="rounded-lg bg-danger/15 px-3 py-2 text-sm text-danger">
              {{ errorMessage }}
            </div>

            <div v-if="notice" class="rounded-lg bg-success/15 px-3 py-2 text-sm text-success">
              {{ notice }}
            </div>

            <button
              type="button"
              class="btn-primary w-full"
              @click="emit('message', props.user.id)"
            >
              Enviar mensaje
            </button>

            <button
              type="button"
              class="btn-secondary w-full"
              :disabled="busy"
              @click="toggleBlock"
            >
              {{ blocked ? 'Desbloquear usuario' : 'Bloquear usuario' }}
            </button>

            <button type="button" class="btn-secondary w-full" :disabled="busy" @click="toggleMute">
              {{ muted ? 'Reactivar usuario' : 'Silenciar usuario' }}
            </button>

            <button type="button" class="btn-ghost w-full" @click="showReport = !showReport">
              Denunciar usuario
            </button>

            <div v-if="showReport" class="space-y-2 rounded-lg bg-surface p-3">
              <select v-model="reportReason" class="field !py-1.5 text-xs">
                <option value="spam">Spam</option>
                <option value="harassment">Acoso</option>
                <option value="nudity">Contenido inapropiado</option>
                <option value="violence">Violencia</option>
                <option value="other">Otro</option>
              </select>

              <textarea
                v-model="reportDetails"
                class="field min-h-16 resize-y !py-1.5 text-xs"
                placeholder="Detalles (opcional)"
                maxlength="2000"
              />

              <button
                type="button"
                class="btn-danger w-full !py-1.5 text-xs"
                :disabled="busy"
                @click="submitReport"
              >
                Enviar denuncia
              </button>
            </div>

            <button type="button" class="btn-ghost w-full" @click="emit('close')">Cerrar</button>
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
