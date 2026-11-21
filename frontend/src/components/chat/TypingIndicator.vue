<script setup lang="ts">
/**
 * The names of the users that are typing.
 */
defineProps<{
  names: string[]
}>()
</script>

<template>
  <div v-if="names.length" class="flex items-center gap-2 px-4 py-1 text-xs text-text-muted">
    <span class="flex items-center gap-0.5" aria-hidden="true">
      <span class="size-1.5 animate-bounce rounded-full bg-text-muted [animation-delay:-0.3s]" />
      <span class="size-1.5 animate-bounce rounded-full bg-text-muted [animation-delay:-0.15s]" />
      <span class="size-1.5 animate-bounce rounded-full bg-text-muted" />
    </span>

    <span class="truncate">
      <template v-if="names.length === 1">{{ names[0] }} está escribiendo...</template>
      <template v-else-if="names.length === 2">
        {{ names[0] }} y {{ names[1] }} están escribiendo...
      </template>
      <template v-else>Varias personas están escribiendo...</template>
    </span>
  </div>
</template>
