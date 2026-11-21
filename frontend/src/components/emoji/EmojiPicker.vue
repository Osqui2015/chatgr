<script setup lang="ts">
import { computed, ref } from 'vue'

/**
 * The emoji groups offered by the picker.
 */
const GROUPS = [
  {
    name: 'Caras',
    emoji: [
      '😀',
      '😃',
      '😄',
      '😁',
      '😆',
      '😅',
      '🤣',
      '😂',
      '🙂',
      '🙃',
      '😉',
      '😊',
      '😇',
      '🥰',
      '😍',
      '🤩',
      '😘',
      '😗',
      '😚',
      '😙',
      '🥲',
      '😋',
      '😛',
      '😜',
      '🤪',
      '😝',
      '🤑',
      '🤗',
      '🤭',
      '🤫',
      '🤔',
      '🤐',
      '🤨',
      '😐',
      '😑',
      '😶',
      '😏',
      '😒',
      '🙄',
      '😬',
      '🤥',
      '😌',
      '😔',
      '😪',
      '🤤',
      '😴',
      '😷',
      '🤒',
      '🤕',
      '🤢',
      '🤮',
      '🤧',
      '🥵',
      '🥶',
      '🥴',
      '😵',
      '🤯',
      '🤠',
      '🥳',
      '😎',
      '🤓',
      '🧐',
      '😕',
      '😟',
      '🙁',
      '😮',
      '😯',
      '😲',
      '😳',
      '🥺',
      '😦',
      '😧',
      '😨',
      '😰',
      '😥',
      '😢',
      '😭',
      '😱',
      '😖',
      '😣',
      '😞',
      '😓',
      '😩',
      '😫',
      '🥱',
      '😤',
      '😡',
      '😠',
      '🤬',
      '😈',
      '💀',
      '💩',
      '🤡',
      '👻',
      '👽',
      '🤖',
      '🎃',
    ],
  },
  {
    name: 'Gestos',
    emoji: [
      '👍',
      '👎',
      '👌',
      '🤌',
      '🤏',
      '✌️',
      '🤞',
      '🫰',
      '🤟',
      '🤘',
      '🤙',
      '👈',
      '👉',
      '👆',
      '👇',
      '☝️',
      '👋',
      '🤚',
      '🖐️',
      '✋',
      '🖖',
      '👏',
      '🙌',
      '🫶',
      '👐',
      '🤲',
      '🤝',
      '🙏',
      '✍️',
      '💪',
      '🦾',
      '🫵',
    ],
  },
  {
    name: 'Corazones',
    emoji: [
      '❤️',
      '🧡',
      '💛',
      '💚',
      '💙',
      '💜',
      '🖤',
      '🤍',
      '🤎',
      '💔',
      '❣️',
      '💕',
      '💞',
      '💓',
      '💗',
      '💖',
      '💘',
      '💝',
      '💟',
    ],
  },
  {
    name: 'Animales',
    emoji: [
      '🐶',
      '🐱',
      '🐭',
      '🐹',
      '🐰',
      '🦊',
      '🐻',
      '🐼',
      '🐨',
      '🐯',
      '🦁',
      '🐮',
      '🐷',
      '🐸',
      '🐵',
      '🐔',
      '🐧',
      '🐦',
      '🦆',
      '🦉',
      '🦇',
      '🐺',
      '🐗',
      '🐴',
      '🦄',
      '🐝',
      '🦋',
      '🐌',
      '🐞',
      '🐢',
      '🐍',
      '🦎',
      '🐙',
      '🦑',
      '🦀',
      '🐡',
      '🐠',
      '🐟',
      '🐬',
      '🐳',
      '🐋',
      '🦈',
      '🐊',
      '🐅',
      '🐆',
      '🦓',
      '🦍',
      '🐘',
      '🦛',
      '🐪',
      '🦒',
      '🐄',
      '🐖',
      '🐑',
      '🐐',
      '🦌',
      '🐕',
      '🐩',
      '🦮',
      '🐈',
      '🐓',
      '🦃',
      '🕊️',
    ],
  },
  {
    name: 'Comida',
    emoji: [
      '🍏',
      '🍎',
      '🍐',
      '🍊',
      '🍋',
      '🍌',
      '🍉',
      '🍇',
      '🍓',
      '🫐',
      '🍈',
      '🍒',
      '🍑',
      '🥭',
      '🍍',
      '🥥',
      '🥝',
      '🍅',
      '🍆',
      '🥑',
      '🥦',
      '🥬',
      '🥒',
      '🌶️',
      '🫑',
      '🌽',
      '🥕',
      '🫒',
      '🧄',
      '🧅',
      '🥔',
      '🍠',
      '🥐',
      '🥯',
      '🍞',
      '🥖',
      '🥨',
      '🧀',
      '🥚',
      '🍳',
      '🧈',
      '🥞',
      '🧇',
      '🥓',
      '🍔',
      '🍟',
      '🍕',
      '🌭',
      '🥪',
      '🌮',
      '🌯',
      '🥙',
      '🧆',
      '🍜',
      '🍝',
      '🍲',
      '🍛',
      '🍣',
      '🍱',
      '🥟',
      '🍤',
      '🍙',
      '🍚',
      '🍘',
      '🍥',
      '🥠',
      '🥮',
      '🍢',
      '🍡',
      '🍧',
      '🍨',
      '🍦',
      '🥧',
      '🧁',
      '🍰',
      '🎂',
      '🍮',
      '🍭',
      '🍬',
      '🍫',
      '🍿',
      '🍩',
      '🍪',
    ],
  },
  {
    name: 'Actividades',
    emoji: [
      '⚽',
      '🏀',
      '🏈',
      '⚾',
      '🥎',
      '🎾',
      '🏐',
      '🏉',
      '🥏',
      '🎱',
      '🪀',
      '🏓',
      '🏸',
      '🏒',
      '🏑',
      '🥍',
      '🏏',
      '🥅',
      '⛳',
      '🪁',
      '🏹',
      '🎣',
      '🤿',
      '🥊',
      '🥋',
      '🎽',
      '🛹',
      '🛼',
      '🛷',
      '⛸️',
      '🥌',
      '🎿',
      '⛷️',
      '🏂',
      '🪂',
      '🏋️',
      '🤼',
      '🤸',
      '⛹️',
      '🤺',
      '🤾',
      '🏌️',
      '🏇',
      '🧘',
      '🏄',
      '🏊',
      '🤽',
      '🚣',
      '🧗',
      '🚵',
      '🚴',
      '🏆',
      '🥇',
      '🥈',
      '🥉',
      '🏅',
      '🎖️',
      '🎗️',
      '🎫',
      '🎟️',
      '🎪',
      '🤹',
      '🎭',
      '🎨',
      '🎬',
      '🎤',
      '🎧',
      '🎼',
      '🎹',
      '🥁',
      '🎷',
      '🎺',
      '🎸',
      '🪕',
      '🎻',
      '🎲',
      '♟️',
      '🎯',
      '🎳',
      '🎮',
      '🎰',
      '🧩',
    ],
  },
]

const emit = defineEmits<{
  select: [emoji: string]
  close: []
}>()

const activeGroup = ref(0)
const query = ref('')

/**
 * The emojis that match the current search.
 */
const results = computed(() => {
  const term = query.value.trim().toLowerCase()

  if (term === '') {
    return null
  }

  const matches: string[] = []

  for (const group of GROUPS) {
    for (const emoji of group.emoji) {
      if (emoji.toLowerCase().includes(term)) {
        matches.push(emoji)
      }
    }
  }

  return matches
})
</script>

<template>
  <div class="panel w-[min(18rem,calc(100vw-2rem))] overflow-hidden text-sm" @click.stop>
    <div class="border-b border-border p-2">
      <input
        v-model="query"
        type="search"
        class="field !py-1.5 !text-xs"
        placeholder="Buscar emoji"
        autofocus
      />
    </div>

    <div v-if="results" class="max-h-56 overflow-y-auto p-2">
      <div class="grid grid-cols-8 gap-0.5">
        <button
          v-for="emoji in results"
          :key="emoji"
          type="button"
          class="rounded p-1 text-xl transition-transform hover:scale-125 hover:bg-surface-overlay"
          @click="emit('select', emoji)"
        >
          {{ emoji }}
        </button>
      </div>
      <p v-if="results.length === 0" class="px-1 py-4 text-center text-xs text-text-subtle">
        No se encontraron emojis.
      </p>
    </div>

    <template v-else>
      <div class="flex gap-0.5 border-b border-border p-1.5">
        <button
          v-for="(group, index) in GROUPS"
          :key="group.name"
          type="button"
          class="rounded px-2 py-1 text-xs transition-colors"
          :class="
            activeGroup === index
              ? 'bg-surface-overlay text-text'
              : 'text-text-subtle hover:text-text'
          "
          :title="group.name"
          @click="activeGroup = index"
        >
          {{ group.name }}
        </button>
      </div>

      <div class="max-h-56 overflow-y-auto p-2">
        <div class="grid grid-cols-8 gap-0.5">
          <button
            v-for="emoji in GROUPS[activeGroup]?.emoji ?? []"
            :key="emoji"
            type="button"
            class="rounded p-1 text-xl transition-transform hover:scale-125 hover:bg-surface-overlay"
            @click="emit('select', emoji)"
          >
            {{ emoji }}
          </button>
        </div>
      </div>
    </template>
  </div>
</template>
