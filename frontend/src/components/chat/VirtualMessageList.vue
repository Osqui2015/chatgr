<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'

import type { VirtualMessageListExposed } from '@/components/chat/virtualMessageList'
import type { Message } from '@/types'

/**
 * The estimated height of a message, in pixels.
 *
 * The real height is measured once the item is rendered; this value is only
 * used for the items that have not been measured yet.
 */
const ESTIMATED_HEIGHT = 72

/**
 * The height of the viewport used to decide how many messages are rendered.
 */
const VIEWPORT_HEIGHT = 640

/**
 * The number of extra messages rendered above and below the viewport, so the
 * scroll feels continuous while the user moves through the history.
 */
const OVERSCAN = 8

/**
 * The props of the virtual list.
 */
interface VirtualMessageListProps {
  /** Every message of the conversation, in chronological order. */
  messages: Message[]
  /** Whether the previous page is being loaded. */
  loadingMore?: boolean
}

/**
 * Renders only the messages that are visible in the viewport.
 *
 * Chat histories can hold thousands of messages, so mounting every bubble at
 * once would freeze the browser. This component keeps an offset table with
 * the measured height of each message, computes the visible window from the
 * scroll position and renders only that window plus a small overscan.
 */
const props = withDefaults(defineProps<VirtualMessageListProps>(), {
  loadingMore: false,
})

const emit = defineEmits<{
  /** The user reached the top of the list and the previous page is needed. */
  reachTop: []
  /** The message element was mounted, so its height can be measured. */
  measure: [id: number, height: number]
}>()

const container = ref<HTMLElement | null>(null)
const scrollTop = ref(0)
const viewportHeight = ref(VIEWPORT_HEIGHT)

/**
 * Whether the list is scrolled close to the newest message.
 *
 * The flag tells the parent view if an incoming message must be scrolled into
 * view or kept out of it while the user reads the history.
 */
const nearBottom = ref(true)

/**
 * The measured height of every message that has already been rendered.
 */
const heights = ref<Map<number, number>>(new Map())

/**
 * The cumulative offset of each message inside the virtual list.
 */
const offsets = computed(() => {
  const result: number[] = new Array(props.messages.length + 1)
  let total = 0

  for (let index = 0; index < props.messages.length; index += 1) {
    result[index] = total

    const message = props.messages[index]
    const height = message ? (heights.value.get(message.id) ?? ESTIMATED_HEIGHT) : ESTIMATED_HEIGHT

    total += height
  }

  result[props.messages.length] = total

  return result
})

/**
 * The total height of the virtual list.
 */
const totalHeight = computed(() => offsets.value[props.messages.length] ?? 0)

/**
 * The index of the first visible message.
 */
const startIndex = computed(() => {
  const table = offsets.value
  const target = scrollTop.value

  let low = 0
  let high = props.messages.length

  while (low < high) {
    const middle = Math.floor((low + high) / 2)
    const offset = table[middle] ?? 0

    if (offset < target) {
      low = middle + 1
    } else {
      high = middle
    }
  }

  return Math.max(0, low - 1)
})

/**
 * The index of the last visible message.
 */
const endIndex = computed(() => {
  const table = offsets.value
  const target = scrollTop.value + viewportHeight.value

  let low = 0
  let high = props.messages.length

  while (low < high) {
    const middle = Math.floor((low + high) / 2)
    const offset = table[middle] ?? 0

    if (offset <= target) {
      low = middle + 1
    } else {
      high = middle
    }
  }

  return Math.min(props.messages.length, Math.max(0, low + 1))
})

/**
 * The slice of messages that is currently rendered.
 */
const visibleMessages = computed(() => {
  const start = Math.max(0, startIndex.value - OVERSCAN)
  const end = Math.min(props.messages.length, endIndex.value + OVERSCAN)

  return props.messages.slice(start, end).map((message, index) => ({
    message,
    index: start + index,
    offset: offsets.value[start + index] ?? 0,
  }))
})

/**
 * Keep the scroll position when the history grows above the viewport.
 */
let previousTotalHeight = 0

watch(totalHeight, (height) => {
  const delta = height - previousTotalHeight
  previousTotalHeight = height

  if (delta > 0 && container.value) {
    const element = container.value

    if (element.scrollTop > 0) {
      element.scrollTop += delta
    }
  }
})

/**
 * Read the scroll position of the container.
 */
function onScroll(): void {
  const element = container.value

  if (!element) {
    return
  }

  scrollTop.value = element.scrollTop
  viewportHeight.value = element.clientHeight

  const distance = element.scrollHeight - element.scrollTop - element.clientHeight

  nearBottom.value = distance < 120

  if (element.scrollTop <= 24) {
    emit('reachTop')
  }
}

/**
 * Measure a rendered message so the offset table stays accurate.
 */
function measure(element: Element): void {
  const id = Number((element as HTMLElement).dataset['messageId'])

  if (!Number.isFinite(id) || id <= 0) {
    return
  }

  const height = (element as HTMLElement).offsetHeight

  if (height <= 0) {
    return
  }

  const current = heights.value.get(id)

  if (current === height) {
    return
  }

  const next = new Map(heights.value)

  next.set(id, height)
  heights.value = next

  emit('measure', id, height)
}

/**
 * Scroll to the newest message.
 */
async function scrollToBottom(smooth = true): Promise<void> {
  await nextTick()

  const element = container.value

  if (!element) {
    return
  }

  element.scrollTo({ top: element.scrollHeight, behavior: smooth ? 'smooth' : 'auto' })
  scrollTop.value = element.scrollTop
}

/**
 * Scroll to the top of the list, used when a previous page is loaded.
 */
async function scrollToTop(): Promise<void> {
  await nextTick()

  container.value?.scrollTo({ top: 0, behavior: 'auto' })
  scrollTop.value = 0
}

let resizeObserver: ResizeObserver | null = null

onMounted(() => {
  const element = container.value

  if (!element) {
    return
  }

  viewportHeight.value = element.clientHeight || VIEWPORT_HEIGHT

  resizeObserver = new ResizeObserver(() => {
    viewportHeight.value = element.clientHeight
  })

  resizeObserver.observe(element)
})

onBeforeUnmount(() => {
  resizeObserver?.disconnect()
  resizeObserver = null
})

defineExpose<VirtualMessageListExposed>({
  scrollToBottom,
  scrollToTop,
  get isNearBottom(): boolean {
    return nearBottom.value
  },
})
</script>

<template>
  <div ref="container" class="h-full overflow-y-auto" @scroll.passive="onScroll">
    <div :style="{ height: `${totalHeight}px`, position: 'relative' }">
      <div
        v-for="entry in visibleMessages"
        :key="entry.message.id"
        :data-message-id="entry.message.id"
        :ref="(element) => element && measure(element as Element)"
        :style="{
          position: 'absolute',
          top: `${entry.offset}px`,
          left: '0',
          right: '0',
        }"
      >
        <slot name="message" :message="entry.message" :index="entry.index" :offset="entry.offset" />
      </div>
    </div>

    <slot name="loading" :visible="Boolean(loadingMore)" />
  </div>
</template>
