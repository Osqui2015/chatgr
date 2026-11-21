import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'

import VirtualMessageList from '@/components/chat/VirtualMessageList.vue'
import type { Message } from '@/types'

/**
 * Build a message that the virtual list can consume.
 */
function makeMessage(id: number): Message {
  return {
    id,
    conversation_id: 1,
    user: null,
    reply_to: null,
    body: `Mensaje ${id}`,
    type: 'text',
    attachment: null,
    view_once: null,
    reactions: [],
    edited_at: null,
    deleted_at: null,
    is_deleted: false,
    created_at: '2026-01-01T10:00:00.000Z',
    updated_at: null,
  }
}

/**
 * Build a list of messages.
 */
function makeMessages(count: number): Message[] {
  return Array.from({ length: count }, (_, index) => makeMessage(index + 1))
}

describe('VirtualMessageList', () => {
  it('renders only a window of the history', async () => {
    const wrapper = mount(VirtualMessageList, {
      props: { messages: makeMessages(500) },
      slots: {
        message: '<p class="bubble">{{ params.message.body }}</p>',
      },
    })

    await wrapper.vm.$nextTick()

    const rendered = wrapper.findAll('.bubble')

    expect(rendered.length).toBeGreaterThan(0)
    expect(rendered.length).toBeLessThan(500)
  })

  it('keeps the total height of the list even when few items are rendered', async () => {
    const wrapper = mount(VirtualMessageList, {
      props: { messages: makeMessages(200) },
      slots: {
        message: '<p class="bubble">{{ params.message.body }}</p>',
      },
    })

    await wrapper.vm.$nextTick()

    const spacer = wrapper.find('[style*="position: relative"]')

    expect(spacer.exists()).toBe(true)
    expect(spacer.attributes('style')).toContain('height:')
  })

  it('emits reachTop when the list is scrolled to the top', async () => {
    const wrapper = mount(VirtualMessageList, {
      props: { messages: makeMessages(50) },
      slots: {
        message: '<p class="bubble">{{ params.message.body }}</p>',
      },
      attachTo: document.body,
    })

    await wrapper.vm.$nextTick()

    const container = wrapper.element as HTMLElement

    container.scrollTop = 0
    await wrapper.trigger('scroll')

    expect(wrapper.emitted('reachTop')).toBeTruthy()
  })

  it('exposes the scroll helpers', async () => {
    const wrapper = mount(VirtualMessageList, {
      props: { messages: makeMessages(10) },
      slots: {
        message: '<p class="bubble">{{ params.message.body }}</p>',
      },
    })

    await wrapper.vm.$nextTick()

    expect(typeof wrapper.vm.scrollToBottom).toBe('function')
    expect(typeof wrapper.vm.scrollToTop).toBe('function')
  })
})
