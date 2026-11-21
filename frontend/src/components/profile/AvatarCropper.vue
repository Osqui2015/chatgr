<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import Cropper from 'cropperjs'

/**
 * The props of the cropper.
 */
const props = defineProps<{
  /** The image to crop. */
  src: string
  /** Whether the cropper is open. */
  open: boolean
}>()

const emit = defineEmits<{
  /** The cropped image, already resized and encoded. */
  cropped: [blob: Blob]
  close: []
}>()

/**
 * A square selection is mandatory so the avatar is never distorted.
 */
const CROPPER_TEMPLATE = `
  <cropper-canvas background>
    <cropper-image rotatable scalable skewable translatable></cropper-image>
    <cropper-shade hidden></cropper-shade>
    <cropper-handle action="select" plain></cropper-handle>
    <cropper-selection initial-coverage="1" aspect-ratio="1" movable resizable>
      <cropper-grid role="grid" bordered covered></cropper-grid>
      <cropper-crosshair centered></cropper-crosshair>
      <cropper-handle action="move" theme-color="rgba(255, 255, 255, 0.35)"></cropper-handle>
      <cropper-handle action="n-resize"></cropper-handle>
      <cropper-handle action="e-resize"></cropper-handle>
      <cropper-handle action="s-resize"></cropper-handle>
      <cropper-handle action="w-resize"></cropper-handle>
      <cropper-handle action="ne-resize"></cropper-handle>
      <cropper-handle action="nw-resize"></cropper-handle>
      <cropper-handle action="se-resize"></cropper-handle>
      <cropper-handle action="sw-resize"></cropper-handle>
    </cropper-selection>
  </cropper-canvas>
`

const image = ref<HTMLImageElement | null>(null)
const processing = ref(false)

let cropper: Cropper | null = null

/**
 * The edge length of the exported avatar.
 */
const EXPORT_SIZE = 512

/**
 * The quality of the exported WebP image.
 */
const EXPORT_QUALITY = 0.82

/**
 * Build the cropper once the image element exists.
 */
function buildCropper(): void {
  if (!image.value || cropper) {
    return
  }

  cropper = new Cropper(image.value, { template: CROPPER_TEMPLATE })

  const canvas = cropper.getCropperCanvas()

  canvas?.addEventListener(
    'wheel',
    (event) => {
      event.preventDefault()
      cropper?.getCropperImage()?.$zoom(-event.deltaY / 1000)
    },
    { passive: false },
  )
}

/**
 * Export the selected area as a WebP blob.
 */
async function exportImage(): Promise<void> {
  const selection = cropper?.getCropperSelection()

  if (!selection || processing.value) {
    return
  }

  processing.value = true

  try {
    const cropped = await selection.$toCanvas({
      width: EXPORT_SIZE,
      height: EXPORT_SIZE,
    })

    cropped.toBlob(
      (blob: Blob | null) => {
        if (blob) {
          emit('cropped', blob)
        }
      },
      'image/webp',
      EXPORT_QUALITY,
    )
  } finally {
    processing.value = false
  }
}

/**
 * Close the cropper and release the resources.
 */
function close(): void {
  destroyCropper()
  emit('close')
}

function destroyCropper(): void {
  cropper?.destroy()
  cropper = null
}

watch(
  [() => props.open, () => props.src],
  ([isOpen]) => {
    destroyCropper()

    if (isOpen) {
      // Wait for the image to be rendered before attaching the cropper.
      window.requestAnimationFrame(buildCropper)
    }
  },
  { immediate: true, flush: 'post' },
)

onBeforeUnmount(destroyCropper)
</script>

<template>
  <Teleport to="body">
    <Transition name="fade">
      <div
        v-if="open"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/85 p-4 backdrop-blur-sm"
        role="dialog"
        aria-modal="true"
        @click.self="close"
      >
        <div class="panel w-full max-w-md overflow-hidden">
          <header class="border-b border-border px-4 py-3">
            <h2 class="text-sm font-semibold text-text">Recortar avatar</h2>
            <p class="mt-0.5 text-xs text-text-muted">
              Ajusta el encuadre cuadrado. Usa la rueda para acercar o alejar.
            </p>
          </header>

          <div class="relative h-80 w-full overflow-hidden bg-canvas">
            <img
              ref="image"
              :src="src"
              alt="Imagen a recortar"
              class="block h-full w-full object-contain"
            />
          </div>

          <footer class="flex items-center justify-end gap-2 border-t border-border px-4 py-3">
            <button type="button" class="btn-ghost" @click="close">Cancelar</button>
            <button type="button" class="btn-primary" :disabled="processing" @click="exportImage">
              {{ processing ? 'Procesando…' : 'Guardar avatar' }}
            </button>
          </footer>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.18s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

:deep(cropper-canvas) {
  display: block;
  height: 100%;
  width: 100%;
}
</style>
