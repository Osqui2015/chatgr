<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRegisterSW } from 'virtual:pwa-register/vue'

/**
 * The root component.
 *
 * It hosts the application shell and the update prompt of the PWA.
 */
const { needRefresh, updateServiceWorker } = useRegisterSW({
  immediate: true,
})

const updateDismissed = ref(false)

onMounted(() => {
  updateDismissed.value = false
})
</script>

<template>
  <div class="h-full">
    <RouterView v-slot="{ Component }">
      <Transition name="fade" mode="out-in">
        <component :is="Component" />
      </Transition>
    </RouterView>

    <Transition name="slide">
      <div
        v-if="needRefresh && !updateDismissed"
        class="fixed bottom-4 right-4 z-50 flex items-center gap-3 rounded-panel border border-border bg-surface-overlay px-4 py-3 shadow-2xl"
      >
        <p class="text-sm text-text">Hay una actualización disponible.</p>
        <button
          type="button"
          class="btn-primary !py-1.5 text-xs"
          @click="updateServiceWorker(true)"
        >
          Actualizar
        </button>
        <button type="button" class="btn-ghost !py-1.5 text-xs" @click="updateDismissed = true">
          Ahora no
        </button>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.15s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

.slide-enter-active,
.slide-leave-active {
  transition:
    opacity 0.2s ease,
    transform 0.2s ease;
}

.slide-enter-from,
.slide-leave-to {
  opacity: 0;
  transform: translateY(12px);
}
</style>
