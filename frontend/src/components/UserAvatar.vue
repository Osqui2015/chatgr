<script setup lang="ts">
import { computed } from 'vue'

/**
 * The initials shown when a user has no avatar.
 */
const props = withDefaults(
  defineProps<{
    nickname: string
    src?: string | null
    size?: number
    /** Whether the avatar should be presented as a rounded square. */
    rounded?: boolean
    alt?: string
  }>(),
  {
    src: null,
    size: 40,
    rounded: false,
    alt: '',
  },
)

/**
 * The first two letters of the nickname, uppercased.
 */
const initials = computed(() =>
  props.nickname
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join(''),
)

/**
 * A stable hue derived from the nickname so the same user always gets the
 * same placeholder colour.
 */
const hue = computed(() => {
  let hash = 0

  for (const character of props.nickname) {
    hash = (hash * 31 + character.charCodeAt(0)) % 360
  }

  return hash
})

const failed = defineModel<boolean>('failed', { default: false })

function onError(): void {
  failed.value = true
}
</script>

<template>
  <span
    class="relative inline-flex shrink-0 overflow-hidden bg-surface-overlay align-middle"
    :class="rounded ? 'rounded-xl' : 'rounded-full'"
    :style="{ width: `${size}px`, height: `${size}px` }"
  >
    <img
      v-if="src && !failed"
      :src="src"
      :alt="alt || nickname"
      :width="size"
      :height="size"
      class="size-full object-cover"
      loading="lazy"
      decoding="async"
      @error="onError"
    />
    <span
      v-else
      class="flex size-full items-center justify-center font-semibold text-white"
      :style="{
        backgroundColor: `hsl(${hue} 45% 45%)`,
        fontSize: `${Math.max(10, size * 0.38)}px`,
      }"
      aria-hidden="true"
    >
      {{ initials || '?' }}
    </span>
  </span>
</template>
