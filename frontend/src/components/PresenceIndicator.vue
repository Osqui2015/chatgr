<script setup lang="ts">
import { computed } from 'vue'

import type { UserStatus } from '@/types'

/**
 * The colours and labels that describe each presence state.
 */
const STATUS_STYLES: Record<UserStatus, { dot: string; label: string }> = {
  online: { dot: 'bg-success', label: 'En línea' },
  away: { dot: 'bg-warning', label: 'Ausente' },
  busy: { dot: 'bg-danger', label: 'Ocupado' },
  invisible: { dot: 'bg-text-subtle', label: 'Invisible' },
  offline: { dot: 'bg-text-subtle', label: 'Desconectado' },
}

const props = withDefaults(
  defineProps<{
    status: UserStatus
    /** Whether the state is rendered as a dot only, without the label. */
    compact?: boolean
    /** Whether the state is rendered as a label only, without the dot. */
    hideDot?: boolean
  }>(),
  {
    compact: false,
    hideDot: false,
  },
)

const style = computed(() => STATUS_STYLES[props.status] ?? STATUS_STYLES.offline)
</script>

<template>
  <span
    class="inline-flex items-center gap-1.5 text-xs"
    :class="compact ? '' : 'text-text-muted'"
    :title="style.label"
  >
    <span
      v-if="!hideDot"
      class="size-2 shrink-0 rounded-full"
      :class="[style.dot, status === 'online' ? 'ring-2 ring-success/20' : '']"
      aria-hidden="true"
    />
    <span v-if="!compact" class="truncate">{{ style.label }}</span>
    <span v-if="compact" class="sr-only">{{ style.label }}</span>
  </span>
</template>
