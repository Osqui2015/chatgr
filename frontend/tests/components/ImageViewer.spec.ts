import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'

import ImageViewer from '@/components/chat/ImageViewer.vue'

/**
 * The viewer renders through a teleport, so its markup lives in the document
 * instead of inside the wrapper.
 */
function viewerHtml(): string {
  return document.body.innerHTML
}

describe('ImageViewer', () => {
  afterEach(() => {
    document.body.innerHTML = ''
  })

  it('scales the image to the available space', () => {
    mount(ImageViewer, {
      props: { src: 'https://example.test/storage/media/1/image.webp', open: true },
    })

    const image = document.querySelector('img')

    expect(image).not.toBeNull()

    const classes = Array.from(image!.classList)

    // The image must be able to grow: without an explicit size the browser
    // keeps the natural size and small pictures stay tiny on a phone.
    expect(classes).toContain('w-auto')
    expect(classes).toContain('h-auto')
    expect(classes).toContain('max-w-[95vw]')
    expect(classes).toContain('max-h-[85vh]')
  })

  it('shows the caption of a view-once image', () => {
    mount(ImageViewer, {
      props: {
        src: 'https://example.test/api/media/ephemeral/asset/uuid',
        open: true,
        caption: 'Imagen de un solo uso · ya se ha abierto',
      },
    })

    expect(viewerHtml()).toContain('Imagen de un solo uso · ya se ha abierto')
  })

  it('renders nothing when it is closed', () => {
    mount(ImageViewer, {
      props: { src: 'https://example.test/storage/media/1/image.webp', open: false },
    })

    expect(document.querySelector('img')).toBeNull()
  })

  it('tells the user when the image can no longer be loaded', async () => {
    mount(ImageViewer, {
      props: { src: 'https://example.test/storage/media/1/missing.webp', open: true },
    })

    const image = document.querySelector('img')

    expect(image).not.toBeNull()

    image!.dispatchEvent(new Event('error'))

    await Promise.resolve()

    expect(viewerHtml()).toContain('Esta imagen ya no está disponible.')
  })
})
