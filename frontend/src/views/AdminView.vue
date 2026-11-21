<script setup lang="ts">
import { onMounted, ref } from 'vue'

import PresenceIndicator from '@/components/PresenceIndicator.vue'
import UserAvatar from '@/components/UserAvatar.vue'
import { ApiError } from '@/services/http'
import { adminApi, type AdminStatistics } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import type { User } from '@/types'

/**
 * The administration panel.
 */
const auth = useAuthStore()

const statistics = ref<AdminStatistics | null>(null)
const users = ref<User[]>([])
const online = ref<User[]>([])
const reports = ref<Array<Record<string, unknown>>>([])
const bans = ref<Array<Record<string, unknown>>>([])

const query = ref('')
const loading = ref(false)
const errorMessage = ref('')
const activeTab = ref<'statistics' | 'users' | 'reports' | 'online'>('statistics')

/**
 * The panel tabs, in the order they are shown.
 */
const tabs = [
  { id: 'statistics', label: 'Estadísticas' },
  { id: 'users', label: 'Usuarios' },
  { id: 'reports', label: 'Denuncias' },
  { id: 'online', label: 'Conectados' },
] as const

/**
 * Spanish labels for the report reasons the API accepts.
 */
const reasonLabels: Record<string, string> = {
  spam: 'Spam',
  harassment: 'Acoso',
  nudity: 'Desnudos',
  violence: 'Violencia',
  other: 'Otro',
}

/**
 * Spanish labels for the report lifecycle.
 */
const statusLabels: Record<string, string> = {
  pending: 'Pendiente',
  reviewing: 'En revisión',
  resolved: 'Resuelta',
  dismissed: 'Descartada',
}

/**
 * Translate a report reason, falling back to the raw value.
 */
function reasonLabel(reason: unknown): string {
  return typeof reason === 'string' ? (reasonLabels[reason] ?? reason) : '—'
}

/**
 * Translate a report status, falling back to the raw value.
 */
function statusLabel(status: unknown): string {
  return typeof status === 'string' ? (statusLabels[status] ?? status) : '—'
}

/**
 * Load the panel data.
 */
async function load(): Promise<void> {
  loading.value = true
  errorMessage.value = ''

  try {
    const [stats, userList, onlineList, reportList, banList] = await Promise.all([
      adminApi.statistics(),
      adminApi.users({ q: query.value || undefined }),
      adminApi.onlineUsers(),
      adminApi.reports(),
      adminApi.bans(),
    ])

    statistics.value = stats.data
    users.value = userList.data
    online.value = onlineList.data
    reports.value = reportList.data as Array<Record<string, unknown>>
    bans.value = banList.data as Array<Record<string, unknown>>
  } catch (error) {
    errorMessage.value = error instanceof ApiError ? error.message : 'No se pudo cargar el panel.'
  } finally {
    loading.value = false
  }
}

/**
 * Change the role of a user.
 */
async function changeRole(user: User, role: string): Promise<void> {
  try {
    const response = await adminApi.updateRole(user.id, role)

    auth.setUser(response.user)
    await load()
  } catch (error) {
    errorMessage.value = error instanceof ApiError ? error.message : 'No se pudo cambiar el rol.'
  }
}

/**
 * Ban or unban a user.
 */
async function toggleBan(user: User): Promise<void> {
  try {
    if (user.is_banned) {
      await adminApi.unban(user.id)
    } else {
      await adminApi.ban(user.id, 'Violación de las normas de la comunidad')
    }

    await load()
  } catch (error) {
    errorMessage.value =
      error instanceof ApiError ? error.message : 'No se pudo actualizar el baneo.'
  }
}

/**
 * Resolve a report.
 */
async function resolveReport(id: number, status: string): Promise<void> {
  try {
    await adminApi.resolveReport(id, status)
    await load()
  } catch (error) {
    errorMessage.value =
      error instanceof ApiError ? error.message : 'No se pudo resolver la denuncia.'
  }
}

onMounted(load)
</script>

<template>
  <div class="h-full overflow-y-auto bg-canvas">
    <header class="sticky top-0 z-10 border-b border-border bg-surface/95 backdrop-blur">
      <div class="mx-auto max-w-6xl px-4 py-3">
        <div class="flex items-center gap-4">
          <h1 class="text-base font-semibold text-text">Panel de administración</h1>

          <RouterLink :to="{ name: 'chat' }" class="btn-ghost ml-auto !py-1.5 text-xs">
            Volver al chat
          </RouterLink>
        </div>

        <!--
          The tabs scroll sideways on narrow screens so every panel stays
          reachable without breaking the header layout.
        -->
        <nav class="scrollbar-none -mx-4 mt-3 flex gap-1 overflow-x-auto px-4">
          <button
            v-for="tab in tabs"
            :key="tab.id"
            type="button"
            class="shrink-0 rounded-lg px-3 py-1.5 text-xs transition-colors"
            :class="
              activeTab === tab.id
                ? 'bg-surface-overlay text-text'
                : 'text-text-muted hover:text-text'
            "
            @click="activeTab = tab.id"
          >
            {{ tab.label }}
          </button>
        </nav>
      </div>
    </header>

    <main class="mx-auto max-w-6xl space-y-6 p-4">
      <div v-if="errorMessage" class="rounded-lg bg-danger/15 px-3 py-2 text-sm text-danger">
        {{ errorMessage }}
      </div>

      <div v-if="loading" class="flex justify-center py-10">
        <span class="size-6 animate-spin rounded-full border-2 border-border border-t-accent" />
      </div>

      <template v-else>
        <section v-if="activeTab === 'statistics' && statistics" class="space-y-4">
          <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <div class="panel p-4">
              <p class="text-xs text-text-muted">Usuarios</p>
              <p class="mt-1 text-2xl font-semibold text-text">{{ statistics.users.total }}</p>
              <p class="mt-1 text-[11px] text-text-subtle">
                {{ statistics.users.new_24h }} nuevos · {{ statistics.users.verified }} verificados
              </p>
            </div>

            <div class="panel p-4">
              <p class="text-xs text-text-muted">Mensajes</p>
              <p class="mt-1 text-2xl font-semibold text-text">{{ statistics.messages.total }}</p>
              <p class="mt-1 text-[11px] text-text-subtle">
                {{ statistics.messages.last_24h }} en 24 h ·
                {{ statistics.messages.deleted }} eliminados
              </p>
            </div>

            <div class="panel p-4">
              <p class="text-xs text-text-muted">Conectados</p>
              <p class="mt-1 text-2xl font-semibold text-text">{{ statistics.presence.online }}</p>
              <p class="mt-1 text-[11px] text-text-subtle">En este momento</p>
            </div>

            <div class="panel p-4">
              <p class="text-xs text-text-muted">Moderación</p>
              <p class="mt-1 text-2xl font-semibold text-text">
                {{ statistics.moderation.pending_reports }}
              </p>
              <p class="mt-1 text-[11px] text-text-subtle">
                Denuncias pendientes · {{ statistics.moderation.active_bans }} baneos
              </p>
            </div>
          </div>

          <div v-if="bans.length" class="panel overflow-hidden">
            <h2 class="border-b border-border px-4 py-3 text-sm font-medium text-text">
              Baneos activos
            </h2>
            <ul class="divide-y divide-border">
              <li
                v-for="ban in bans"
                :key="String(ban.id)"
                class="flex items-center justify-between px-4 py-2.5 text-xs"
              >
                <span class="text-text">
                  {{ (ban.user as { nickname?: string })?.nickname ?? '—' }}
                </span>
                <span class="text-text-muted">{{ ban.reason ?? 'Sin motivo' }}</span>
              </li>
            </ul>
          </div>
        </section>

        <section v-if="activeTab === 'users'" class="panel overflow-hidden">
          <div class="flex items-center gap-2 border-b border-border px-4 py-3">
            <input
              v-model="query"
              type="search"
              class="field !py-1.5 text-sm"
              placeholder="Buscar por nombre o correo"
              @keydown.enter.prevent="load"
            />
            <button type="button" class="btn-secondary shrink-0 !py-1.5 text-xs" @click="load">
              Buscar
            </button>
          </div>

          <ul class="divide-y divide-border">
            <li
              v-for="user in users"
              :key="user.id"
              class="flex flex-wrap items-center gap-3 px-4 py-3"
            >
              <UserAvatar :nickname="user.nickname" :src="user.avatar_url" :size="32" />

              <div class="min-w-0 flex-1">
                <p class="truncate text-sm text-text">{{ user.nickname }}</p>
                <p class="truncate text-xs text-text-subtle">{{ user.email ?? '—' }}</p>
              </div>

              <PresenceIndicator :status="user.status" />

              <select
                class="field !w-auto !py-1 text-xs"
                :value="user.role"
                :disabled="user.id === auth.user?.id"
                @change="changeRole(user, ($event.target as HTMLSelectElement).value)"
              >
                <option value="user">Usuario</option>
                <option value="moderator">Moderador</option>
                <option value="admin">Administrador</option>
              </select>

              <button
                type="button"
                class="btn-ghost !py-1.5 text-xs"
                :class="user.is_banned ? 'text-success' : 'text-danger'"
                :disabled="user.id === auth.user?.id"
                @click="toggleBan(user)"
              >
                {{ user.is_banned ? 'Desbanear' : 'Banear' }}
              </button>
            </li>
          </ul>

          <p v-if="users.length === 0" class="px-4 py-8 text-center text-sm text-text-subtle">
            No se encontraron usuarios.
          </p>
        </section>

        <section v-if="activeTab === 'reports'" class="panel overflow-hidden">
          <h2 class="border-b border-border px-4 py-3 text-sm font-medium text-text">Denuncias</h2>

          <ul class="divide-y divide-border">
            <li v-for="report in reports" :key="String(report.id)" class="px-4 py-3">
              <div class="flex flex-wrap items-center gap-2 text-xs">
                <span class="font-medium text-text">
                  {{ (report.reported_user as { nickname?: string })?.nickname ?? 'Usuario' }}
                </span>
                <span class="rounded-full bg-surface-overlay px-2 py-0.5 text-text-muted">
                  {{ reasonLabel(report.reason) }}
                </span>
                <span
                  class="rounded-full px-2 py-0.5"
                  :class="
                    report.status === 'pending'
                      ? 'bg-warning/15 text-warning'
                      : 'bg-success/15 text-success'
                  "
                >
                  {{ statusLabel(report.status) }}
                </span>
              </div>

              <p v-if="report.details" class="mt-1 text-xs text-text-muted">{{ report.details }}</p>

              <div v-if="report.status === 'pending'" class="mt-2 flex gap-2">
                <button
                  type="button"
                  class="btn-secondary !py-1 text-xs"
                  @click="resolveReport(Number(report.id), 'resolved')"
                >
                  Resolver
                </button>
                <button
                  type="button"
                  class="btn-ghost !py-1 text-xs"
                  @click="resolveReport(Number(report.id), 'dismissed')"
                >
                  Descartar
                </button>
              </div>
            </li>
          </ul>

          <p v-if="reports.length === 0" class="px-4 py-8 text-center text-sm text-text-subtle">
            No hay denuncias.
          </p>
        </section>

        <section v-if="activeTab === 'online'" class="panel overflow-hidden">
          <h2 class="border-b border-border px-4 py-3 text-sm font-medium text-text">
            Conectados ahora ({{ online.length }})
          </h2>

          <ul class="divide-y divide-border">
            <li v-for="user in online" :key="user.id" class="flex items-center gap-3 px-4 py-2.5">
              <UserAvatar :nickname="user.nickname" :src="user.avatar_url" :size="28" />
              <span class="flex-1 truncate text-sm text-text">{{ user.nickname }}</span>
              <PresenceIndicator :status="user.status" />
            </li>
          </ul>

          <p v-if="online.length === 0" class="px-4 py-8 text-center text-sm text-text-subtle">
            Nadie conectado.
          </p>
        </section>
      </template>
    </main>
  </div>
</template>
