import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'

import MessageBubble from '@/components/chat/MessageBubble.vue'
import type { Message, MessageViewOnce } from '@/types'

/**
 * Build a message the bubble can render.
 */
function makeMessage(viewOnce: MessageViewOnce, overrides: Partial<Message> = {}): Message {
  return {
    id: 12,
    conversation_id: 1,
    user: null,
    reply_to: null,
    body: '',
    type: 'view_once',
    attachment: null,
    view_once: viewOnce,
    reactions: [],
    edited_at: null,
    deleted_at: null,
    is_deleted: false,
    created_at: '2026-01-01T10:00:00.000Z',
    updated_at: null,
    ...overrides,
  }
}

function findButton(wrapper: ReturnType<typeof mount>, label: string) {
  return wrapper.findAll('button').find((button) => button.text() === label)
}

describe('MessageBubble view-once card', () => {
  it('lets the recipient open an available image exactly once', async () => {
    const message = makeMessage({
      available: true,
      opened: false,
      url: 'https://example.test/api/media/ephemeral/asset/uuid',
      width: 400,
      height: 400,
    })

    const wrapper = mount(MessageBubble, {
      props: { message, isOwn: false, grouped: false, showAuthor: false },
    })

    expect(wrapper.text()).toContain('Imagen de un solo uso')
    expect(wrapper.text()).toContain('Se destruirá al abrirla')

    await findButton(wrapper, 'Abrir')?.trigger('click')

    expect(wrapper.emitted('openImage')?.[0]?.[0]).toEqual(message)
  })

  it('tells the author that only the recipient can open it', () => {
    const message = makeMessage({
      available: true,
      opened: false,
      url: null,
      width: 400,
      height: 400,
    })

    const wrapper = mount(MessageBubble, {
      props: { message, isOwn: true, grouped: false, showAuthor: false },
    })

    expect(wrapper.text()).toContain('Solo el destinatario puede abrirla')
    expect(findButton(wrapper, 'Abrir')).toBeUndefined()
  })

  it('marks a consumed image as unavailable', () => {
    const message = makeMessage({
      available: false,
      opened: true,
      url: null,
      width: 400,
      height: 400,
    })

    const wrapper = mount(MessageBubble, {
      props: { message, isOwn: false, grouped: false, showAuthor: false },
    })

    expect(wrapper.text()).toContain('Ya no está disponible')
    expect(findButton(wrapper, 'Abrir')).toBeUndefined()
  })

  it('opens a regular attachment in the viewer', async () => {
    const message = makeMessage(
      { available: false, opened: false, url: null, width: null, height: null },
      {
        type: 'image',
        attachment: {
          url: 'https://example.test/storage/media/1/image.webp',
          width: 800,
          height: 600,
          mime: 'image/webp',
          size: 2048,
        },
      },
    )

    const wrapper = mount(MessageBubble, {
      props: { message, isOwn: false, grouped: false, showAuthor: false },
    })

    await wrapper.find('button[title="Ampliar imagen"]').trigger('click')

    expect(wrapper.emitted('openImage')?.[0]?.[0]).toEqual(message)
  })

  it('keeps the action bar reachable on touch devices', () => {
    const message = makeMessage({
      available: false,
      opened: false,
      url: null,
      width: null,
      height: null,
    })

    const wrapper = mount(MessageBubble, {
      props: { message, isOwn: false, grouped: false, showAuthor: false },
    })

    const bar = wrapper.find('div.absolute')

    // Touch devices have no hover, so the bar must not be hidden by default.
    expect(bar.classes()).not.toContain('opacity-0')
    expect(bar.classes()).toContain('md:opacity-0')
  })
})
