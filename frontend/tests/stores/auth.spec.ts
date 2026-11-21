import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

import { authApi } from '@/services/api'
import type { User } from '@/types'

vi.mock('@/services/api', () => ({
  authApi: {
    me: vi.fn(),
    login: vi.fn(),
    register: vi.fn(),
    verifyLogin: vi.fn(),
    verifyRegistration: vi.fn(),
    resend: vi.fn(),
    logout: vi.fn(),
  },
}))

/**
 * Build a user payload.
 */
function makeUser(overrides: Partial<User> = {}): User {
  return {
    id: 1,
    nickname: 'Ada',
    email: 'ada@example.com',
    avatar_url: null,
    avatar_sizes: {},
    status: 'online',
    last_seen_at: null,
    role: 'user',
    is_muted: false,
    email_verified_at: '2026-01-01T10:00:00.000Z',
    created_at: null,
    updated_at: null,
    ...overrides,
  }
}

describe('useAuthStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('hydrates the session user once', async () => {
    const { useAuthStore } = await import('@/stores/auth')
    const store = useAuthStore()

    vi.mocked(authApi.me).mockResolvedValue({ user: makeUser() })

    await store.hydrate()
    await store.hydrate()

    expect(authApi.me).toHaveBeenCalledTimes(1)
    expect(store.isAuthenticated).toBe(true)
    expect(store.isVerified).toBe(true)
  })

  it('stays anonymous when there is no session', async () => {
    const { useAuthStore } = await import('@/stores/auth')
    const store = useAuthStore()

    vi.mocked(authApi.me).mockRejectedValue(new Error('unauthenticated'))

    await store.hydrate()

    expect(store.user).toBeNull()
    expect(store.initialised).toBe(true)
  })

  it('moves to the verification step when the login requires a code', async () => {
    const { useAuthStore } = await import('@/stores/auth')
    const store = useAuthStore()

    vi.mocked(authApi.login).mockResolvedValue({
      message: 'Código requerido',
      requires_otp: true,
      email: 'ada@example.com',
      purpose: 'login',
    })

    await store.login('ada@example.com', 'secret123')

    expect(store.step).toBe('verify-login')
    expect(store.pendingEmail).toBe('ada@example.com')
    expect(store.isAuthenticated).toBe(false)
  })

  it('signs the user in when the device is trusted', async () => {
    const { useAuthStore } = await import('@/stores/auth')
    const store = useAuthStore()

    vi.mocked(authApi.login).mockResolvedValue({ message: 'ok', user: makeUser() })

    await store.login('ada@example.com', 'secret123')

    expect(store.step).toBe('login')
    expect(store.isAuthenticated).toBe(true)
  })

  it('moves to the verification step after the registration', async () => {
    const { useAuthStore } = await import('@/stores/auth')
    const store = useAuthStore()

    vi.mocked(authApi.register).mockResolvedValue({
      message: 'ok',
      email: 'ada@example.com',
      purpose: 'registration',
    })

    await store.register('ada@example.com', 'Ada', 'secret123', 'secret123')

    expect(store.step).toBe('verify-registration')
    expect(store.pendingEmail).toBe('ada@example.com')
  })

  it('signs the user in once the registration code is accepted', async () => {
    const { useAuthStore } = await import('@/stores/auth')
    const store = useAuthStore()

    vi.mocked(authApi.register).mockResolvedValue({
      message: 'ok',
      email: 'ada@example.com',
      purpose: 'registration',
    })
    vi.mocked(authApi.verifyRegistration).mockResolvedValue({ message: 'ok', user: makeUser() })

    await store.register('ada@example.com', 'Ada', 'secret123', 'secret123')
    await store.verifyRegistration('123456')

    expect(store.isAuthenticated).toBe(true)
    expect(store.step).toBe('login')
  })

  it('flags the staff members', async () => {
    const { useAuthStore } = await import('@/stores/auth')
    const store = useAuthStore()

    store.setUser(makeUser({ role: 'moderator' }))

    expect(store.isStaff).toBe(true)

    store.setUser(makeUser({ role: 'user' }))

    expect(store.isStaff).toBe(false)
  })

  it('clears the session on logout even when the request fails', async () => {
    const { useAuthStore } = await import('@/stores/auth')
    const store = useAuthStore()

    store.setUser(makeUser())
    vi.mocked(authApi.logout).mockRejectedValue(new Error('network'))

    await expect(store.logout()).rejects.toThrow('network')

    expect(store.user).toBeNull()
    expect(store.step).toBe('login')
  })

  it('forgets a pending verification on reset', async () => {
    const { useAuthStore } = await import('@/stores/auth')
    const store = useAuthStore()

    vi.mocked(authApi.login).mockResolvedValue({
      message: 'ok',
      requires_otp: true,
      email: 'ada@example.com',
      purpose: 'login',
    })

    await store.login('ada@example.com', 'secret123')
    store.reset()

    expect(store.step).toBe('login')
    expect(store.pendingEmail).toBe('')
  })
})
