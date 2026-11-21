import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

import MessageInput from '@/components/chat/MessageInput.vue'

/**
 * Attach a file to the hidden input of the component.
 */
async function chooseFile(wrapper: ReturnType<typeof mount>, file: File): Promise<void> {
  const input = wrapper.find('input[type="file"]')

  Object.defineProperty(input.element, 'files', {
    configurable: true,
    value: [file],
  })

  await input.trigger('change')
}

describe('MessageInput', () => {
  beforeEach(() => {
    Object.defineProperty(URL, 'createObjectURL', {
      configurable: true,
      value: vi.fn(() => 'blob:preview'),
    })
    Object.defineProperty(URL, 'revokeObjectURL', {
      configurable: true,
      value: vi.fn(),
    })
  })

  it('emits a view-once image when the one-time picker is used', async () => {
    const wrapper = mount(MessageInput)
    const file = new File(['imagen'], 'secreto.png', { type: 'image/png' })

    await wrapper.find('button[title="Enviar imagen de un solo uso"]').trigger('click')
    await chooseFile(wrapper, file)

    expect(wrapper.find('textarea').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('Se verá una sola vez')

    await wrapper.find('button[aria-label="Enviar"]').trigger('click')

    expect(wrapper.emitted('send')?.[0]?.[0]).toEqual({ image: file, view_once: true })
  })

  it('emits a normal image when the regular picker is used', async () => {
    const wrapper = mount(MessageInput)
    const file = new File(['imagen'], 'foto.png', { type: 'image/png' })

    await wrapper.find('button[title="Adjuntar imagen"]').trigger('click')
    await chooseFile(wrapper, file)

    await wrapper.find('button[aria-label="Enviar"]').trigger('click')

    expect(wrapper.emitted('send')?.[0]?.[0]).toEqual({ image: file })
  })

  it('sends text without an image', async () => {
    const wrapper = mount(MessageInput)

    await wrapper.find('textarea').setValue('Hola')
    await wrapper.find('button[aria-label="Enviar"]').trigger('click')

    expect(wrapper.emitted('send')?.[0]?.[0]).toEqual({ body: 'Hola' })
  })
})
