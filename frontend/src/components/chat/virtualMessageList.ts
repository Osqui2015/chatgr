import type { Message } from '@/types'

/**
 * The public API exposed by the virtual message list.
 */
export interface VirtualMessageListExposed {
  /** Scroll the list to the newest message. */
  scrollToBottom: (smooth?: boolean) => Promise<void>
  /** Scroll the list to the oldest message. */
  scrollToTop: () => Promise<void>
  /** Whether the list is scrolled close to the newest message. */
  readonly isNearBottom: boolean
}

/**
 * A message that has been measured in the DOM.
 */
export interface MeasuredMessage {
  id: number
  height: number
}

/**
 * A message that is currently rendered, with its position in the virtual list.
 */
export interface VirtualMessageEntry {
  message: Message
  index: number
  offset: number
}
