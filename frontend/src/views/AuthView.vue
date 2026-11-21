<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { ApiError } from '@/services/http'
import { useAuthStore } from '@/stores/auth'

/**
 * The authentication view.
 *
 * It hosts the whole account lifecycle: the login form, the registration
 * form and the two verification steps.
 */
const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const email = ref('')
const nickname = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const code = ref('')
const mode = ref<'login' | 'register'>('login')
const errorMessage = ref('')
const notice = ref('')

const isVerificationStep = computed(
  () => auth.step === 'verify-login' || auth.step === 'verify-registration',
)

const submitLabel = computed(() => {
  if (auth.step === 'verify-login') {
    return 'Verificar código'
  }

  if (auth.step === 'verify-registration') {
    return 'Completar registro'
  }

  return mode.value === 'register' ? 'Crear cuenta' : 'Entrar'
})

/**
 * Clear the transient feedback before a new attempt.
 */
function resetFeedback(): void {
  errorMessage.value = ''
  notice.value = ''
}

/**
 * Turn a validation failure into readable field messages.
 */
function describe(error: unknown): string {
  if (error instanceof ApiError) {
    const first = error.fieldErrors[0]

    if (first) {
      return first.messages[0] ?? error.message
    }

    return error.message
  }

  return 'Ha ocurrido un error inesperado. Inténtalo de nuevo.'
}

/**
 * Submit the login or the registration form.
 */
async function submit(): Promise<void> {
  resetFeedback()

  try {
    if (mode.value === 'register') {
      await auth.register(email.value, nickname.value, password.value, passwordConfirmation.value)
      notice.value = 'Te enviamos un código de 6 dígitos a tu correo.'
    } else {
      await auth.login(email.value, password.value)

      if (auth.isAuthenticated) {
        await redirectAfterLogin()
      }
    }
  } catch (error) {
    errorMessage.value = describe(error)
  }
}

/**
 * Submit the verification code.
 */
async function verify(): Promise<void> {
  resetFeedback()

  try {
    if (auth.step === 'verify-registration') {
      await auth.verifyRegistration(code.value, password.value || undefined)
    } else {
      await auth.verifyLogin(code.value)
    }

    await redirectAfterLogin()
  } catch (error) {
    errorMessage.value = describe(error)
  }
}

/**
 * Ask for a new verification code.
 */
async function resend(): Promise<void> {
  resetFeedback()

  try {
    await auth.resendCode()
    notice.value = 'Te enviamos un nuevo código.'
  } catch (error) {
    errorMessage.value = describe(error)
  }
}

/**
 * Send the user to the page they came from, or to the chat.
 */
async function redirectAfterLogin(): Promise<void> {
  const redirect = route.query.redirect

  await router.replace(typeof redirect === 'string' ? redirect : { name: 'chat' })
}

/**
 * Switch between the login and the registration form.
 */
function switchMode(): void {
  mode.value = mode.value === 'login' ? 'register' : 'login'
  resetFeedback()
  auth.reset()
}

/**
 * Leave the verification step and return to the login form.
 */
function backToForm(): void {
  auth.reset()
  switchMode()
}
</script>

<template>
  <div class="flex min-h-full items-center justify-center p-4">
    <div class="w-full max-w-md">
      <div class="mb-8 text-center">
        <div
          class="mx-auto mb-4 flex size-14 items-center justify-center rounded-2xl bg-accent text-2xl font-bold text-white"
        >
          C
        </div>
        <h1 class="text-2xl font-semibold text-text">Chat</h1>
        <p class="mt-1 text-sm text-text-muted">
          {{
            isVerificationStep
              ? 'Introduce el código que hemos enviado a tu correo.'
              : 'Entra para escribir en tiempo real.'
          }}
        </p>
      </div>

      <div class="panel p-6">
        <form v-if="!isVerificationStep" class="space-y-4" @submit.prevent="submit">
          <div v-if="errorMessage" class="rounded-lg bg-danger/15 px-3 py-2 text-sm text-danger">
            {{ errorMessage }}
          </div>

          <div v-if="notice" class="rounded-lg bg-success/15 px-3 py-2 text-sm text-success">
            {{ notice }}
          </div>

          <div>
            <label for="email" class="mb-1.5 block text-xs font-medium text-text-muted">
              Correo electrónico
            </label>
            <input
              id="email"
              v-model="email"
              type="email"
              class="field"
              autocomplete="email"
              required
              placeholder="tu@correo.com"
            />
          </div>

          <div v-if="mode === 'register'">
            <label for="nickname" class="mb-1.5 block text-xs font-medium text-text-muted">
              Nombre de usuario
            </label>
            <input
              id="nickname"
              v-model="nickname"
              type="text"
              class="field"
              autocomplete="username"
              required
              minlength="3"
              maxlength="32"
              placeholder="Cómo te verán los demás"
            />
          </div>

          <div>
            <label for="password" class="mb-1.5 block text-xs font-medium text-text-muted">
              Contraseña
            </label>
            <input
              id="password"
              v-model="password"
              type="password"
              class="field"
              :autocomplete="mode === 'register' ? 'new-password' : 'current-password'"
              required
              :minlength="mode === 'register' ? 10 : undefined"
              :placeholder="mode === 'register' ? 'Mínimo 10 caracteres' : 'Tu contraseña'"
            />
          </div>

          <div v-if="mode === 'register'">
            <label for="password-confirm" class="mb-1.5 block text-xs font-medium text-text-muted">
              Repetir contraseña
            </label>
            <input
              id="password-confirm"
              v-model="passwordConfirmation"
              type="password"
              class="field"
              autocomplete="new-password"
              required
              minlength="10"
            />
            <p class="mt-1.5 text-[11px] text-text-subtle">
              Debe incluir mayúsculas, minúsculas y números.
            </p>
          </div>

          <button type="submit" class="btn-primary w-full" :disabled="auth.loading">
            {{ auth.loading ? 'Un momento...' : submitLabel }}
          </button>

          <p class="text-center text-xs text-text-muted">
            {{ mode === 'register' ? '¿Ya tienes cuenta?' : '¿No tienes cuenta?' }}
            <button
              type="button"
              class="font-medium text-accent hover:underline"
              @click="switchMode"
            >
              {{ mode === 'register' ? 'Inicia sesión' : 'Regístrate' }}
            </button>
          </p>
        </form>

        <form v-else class="space-y-4" @submit.prevent="verify">
          <div v-if="errorMessage" class="rounded-lg bg-danger/15 px-3 py-2 text-sm text-danger">
            {{ errorMessage }}
          </div>

          <div v-if="notice" class="rounded-lg bg-success/15 px-3 py-2 text-sm text-success">
            {{ notice }}
          </div>

          <p class="text-sm text-text-muted">
            Enviamos un código de 6 dígitos a
            <span class="font-medium text-text">{{ auth.pendingEmail }}</span>
            .
          </p>

          <div>
            <label for="code" class="mb-1.5 block text-xs font-medium text-text-muted">
              Código de verificación
            </label>
            <input
              id="code"
              v-model="code"
              type="text"
              inputmode="numeric"
              pattern="[0-9]*"
              maxlength="6"
              class="field text-center text-2xl tracking-[0.5em]"
              required
              placeholder="000000"
              autocomplete="one-time-code"
            />
          </div>

          <button type="submit" class="btn-primary w-full" :disabled="auth.loading">
            {{ auth.loading ? 'Un momento...' : submitLabel }}
          </button>

          <div class="flex items-center justify-between text-xs">
            <button
              type="button"
              class="text-text-muted transition-colors hover:text-text"
              @click="backToForm"
            >
              Volver
            </button>
            <button type="button" class="font-medium text-accent hover:underline" @click="resend">
              Reenviar código
            </button>
          </div>
        </form>
      </div>

      <p class="mt-6 text-center text-[11px] text-text-subtle">
        Tu correo nunca se comparte. Cada dispositivo nuevo requiere verificación.
      </p>
    </div>
  </div>
</template>
