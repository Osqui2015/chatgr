import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { authApi } from '@/services/api'
import type { User } from '@/types'

/**
 * The step the authentication flow is currently in.
 */
export type AuthStep = 'login' | 'register' | 'verify-registration' | 'verify-login'

/**
 * Holds the authenticated user and drives the login and registration flows.
 */
export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const step = ref<AuthStep>('login')
  const pendingEmail = ref<string>('')
  const loading = ref(false)
  const initialised = ref(false)

  const isAuthenticated = computed(() => user.value !== null)

  const isVerified = computed(
    () => user.value?.email_verified_at !== null && user.value?.email_verified_at !== undefined,
  )

  const isStaff = computed(() => {
    const role = user.value?.role

    return role === 'admin' || role === 'moderator'
  })

  /**
   * Load the session user if there is one.
   */
  async function hydrate(): Promise<void> {
    if (initialised.value) {
      return
    }

    try {
      const response = await authApi.me()
      user.value = response.user
    } catch {
      // A missing session is the normal state for a visitor.
      user.value = null
    } finally {
      initialised.value = true
    }
  }

  /**
   * Start the login flow.
   *
   * When the account is not trusted on this device the API answers with a
   * challenge and the flow moves to the verification step.
   */
  async function login(email: string, password: string): Promise<void> {
    loading.value = true

    try {
      const response = await authApi.login({ email, password })

      if ('requires_otp' in response) {
        pendingEmail.value = response.email
        step.value = 'verify-login'

        return
      }

      user.value = response.user
      step.value = 'login'
    } finally {
      loading.value = false
    }
  }
  /**
   * Start the registration flow.
   */
  async function register(
    email: string,
    nickname: string,
    password: string,
    passwordConfirmation: string,
  ): Promise<void> {
    loading.value = true

    try {
      await authApi.register({
        email,
        nickname,
        password,
        password_confirmation: passwordConfirmation,
      })

      pendingEmail.value = email
      step.value = 'verify-registration'
    } finally {
      loading.value = false
    }
  }

  /**
   * Confirm the registration code and sign the user in.
   */
  async function verifyRegistration(code: string, password?: string): Promise<void> {
    loading.value = true

    try {
      const response = await authApi.verifyRegistration({
        email: pendingEmail.value,
        code,
        password,
      })

      user.value = response.user
      step.value = 'login'
    } finally {
      loading.value = false
    }
  }

  /**
   * Confirm the login code and sign the user in.
   */
  async function verifyLogin(code: string): Promise<void> {
    loading.value = true

    try {
      const response = await authApi.verifyLogin({ email: pendingEmail.value, code })

      user.value = response.user
      step.value = 'login'
    } finally {
      loading.value = false
    }
  }

  /**
   * Ask the server for a new verification code.
   */
  async function resendCode(): Promise<void> {
    await authApi.resend(pendingEmail.value)
  }

  /**
   * End the session.
   */
  async function logout(): Promise<void> {
    try {
      await authApi.logout()
    } finally {
      user.value = null
      step.value = 'login'
      pendingEmail.value = ''
    }
  }

  /**
   * Replace the cached user, for example after a profile update.
   */
  function setUser(next: User): void {
    user.value = next
  }

  /**
   * Return to the login form and forget any pending verification.
   */
  function reset(): void {
    step.value = 'login'
    pendingEmail.value = ''
  }

  return {
    user,
    step,
    pendingEmail,
    loading,
    initialised,
    isAuthenticated,
    isVerified,
    isStaff,
    hydrate,
    login,
    register,
    verifyRegistration,
    verifyLogin,
    resendCode,
    logout,
    setUser,
    reset,
  }
})
