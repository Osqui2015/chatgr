import { beforeAll, describe, expect, it } from 'vitest'

import { mailLogCursor, readOtpCode, waitForOtpCode } from './mailLog'

/**
 * These tests run against a live backend, so they are only executed when the
 * `E2E_BASE_URL` variable points to a running instance.
 *
 * They cover the whole lifecycle: registration, the OTP challenge, the login
 * and the creation of a message.
 */
const baseUrl = process.env['E2E_BASE_URL'] ?? 'http://127.0.0.1:8000'
const enabled = Boolean(process.env['E2E_BASE_URL'])

/**
 * A tiny cookie aware HTTP client, so the session survives between calls.
 */
class Client {
  private cookies = new Map<string, string>()

  /**
   * The CSRF token that the server issued for this session.
   */
  private csrfToken: string | null = null

  /**
   * The `XSRF-TOKEN` cookie, which carries the encrypted token that the
   * server expects in the `X-XSRF-TOKEN` header.
   */
  private xsrfCookie: string | null = null

  public async request(
    method: string,
    path: string,
    body?: unknown,
  ): Promise<{ status: number; data: Record<string, unknown> }> {
    const headers: Record<string, string> = {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    }

    if (body !== undefined) {
      headers['Content-Type'] = 'application/json'
    }

    if (this.xsrfCookie) {
      // The browser exposes the cookie already decoded, which is the value the
      // server expects in the header.
      headers['X-XSRF-TOKEN'] = this.xsrfCookie
    }

    if (this.cookies.size > 0) {
      headers['Cookie'] = [...this.cookies.entries()]
        .map(([name, value]) => `${name}=${value}`)
        .join('; ')
    }

    const response = await fetch(`${baseUrl}${path}`, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
    })

    this.storeCookies(response)

    const text = await response.text()
    let data: Record<string, unknown> = {}

    try {
      data = text ? (JSON.parse(text) as Record<string, unknown>) : {}
    } catch {
      data = { raw: text }
    }

    if (response.status === 419) {
      // The token expired; a new one is requested and the caller retries.
      await this.refreshCsrfToken()
    }

    return { status: response.status, data }
  }

  /**
   * Keep the cookies of the response.
   */
  private storeCookies(response: Response): void {
    for (const raw of response.headers.getSetCookie()) {
      const [pair] = raw.split(';')
      const index = pair?.indexOf('=') ?? -1

      if (!pair || index <= 0) {
        continue
      }

      const name = pair.slice(0, index)
      const value = pair.slice(index + 1)

      this.cookies.set(name, value)

      if (name === 'XSRF-TOKEN') {
        // The value travels URL encoded in the header, exactly as the browser
        // stores it.
        this.xsrfCookie = decodeURIComponent(value)
      }
    }
  }

  /**
   * Ask the server for a fresh CSRF token.
   */
  public async refreshCsrfToken(): Promise<void> {
    const response = await fetch(`${baseUrl}/api/auth/csrf`, {
      headers: { Accept: 'application/json' },
    })

    this.storeCookies(response)

    const data = (await response.json()) as { token?: string }

    this.csrfToken = data.token ?? null
  }

  /**
   * The cookies of the session, as a header value.
   */
  public cookieHeader(): string {
    return [...this.cookies.entries()].map(([name, value]) => `${name}=${value}`).join('; ')
  }
}

/**
 * Sign in with the seeded administrator and return the client.
 *
 * The account is protected by an OTP challenge on unknown devices, so the
 * code that the server sends is read from the mail log and confirmed.
 */
async function signIn(): Promise<Client> {
  const client = new Client()
  const cursor = await mailLogCursor()

  await client.refreshCsrfToken()

  const login = await client.request('POST', '/api/auth/login', {
    email: 'admin@chat.local',
    password: 'Admin123!',
  })

  // When requires_otp is true or HTTP 429 (a code is already active under cooldown):
  if (login.data['requires_otp'] === true || login.status === 429) {
    const code =
      (await waitForOtpCode('admin@chat.local', login.status === 429 ? 0 : cursor)) ??
      (await readOtpCode('admin@chat.local', 0))

    expect(code).toMatch(/^\d{6}$/)

    const verification = await client.request('POST', '/api/auth/verify/login', {
      email: 'admin@chat.local',
      code,
    })

    expect(verification.status).toBe(200)
  }

  return client
}

describe.skipIf(!enabled)('the end to end flow', () => {
  let adminClient: Client

  beforeAll(async () => {
    adminClient = await signIn()
  })
  it('registers, verifies the code, signs in and sends a message', async () => {
    const client = new Client()
    const stamp = Date.now()
    const email = `e2e-${stamp}@example.com`
    const password = 'Secret123!'

    await client.refreshCsrfToken()

    const registration = await client.request('POST', '/api/auth/register', {
      email,
      nickname: `E2E${stamp}`,
      password,
      password_confirmation: password,
    })

    expect(registration.status).toBe(201)

    const verification = await client.request('POST', '/api/auth/verify/registration', {
      email,
      code: '000000',
    })

    // A wrong code must be rejected.
    expect(verification.status).toBe(422)
  })

  it('signs in and loads the global room once the code is accepted', async () => {
    const client = new Client()
    const stamp = Date.now()
    const email = `e2e-full-${stamp}@example.com`
    const password = 'Secret123!'

    await client.refreshCsrfToken()

    const registration = await client.request('POST', '/api/auth/register', {
      email,
      nickname: `Full${stamp}`,
      password,
      password_confirmation: password,
    })

    expect(registration.status).toBe(201)

    // The code is read from the mail log, because the local mailer writes to
    // the application log instead of sending it.
    const code = await readOtpCode(email)

    expect(code).toMatch(/^\d{6}$/)

    const verification = await client.request('POST', '/api/auth/verify/registration', {
      email,
      code,
    })

    expect(verification.status).toBe(200)

    const conversations = await client.request('GET', '/api/conversations')

    expect(conversations.status).toBe(200)
    expect(Array.isArray(conversations.data['data'])).toBe(true)
  })

  it('rejects an unauthenticated request', async () => {
    const client = new Client()
    const response = await client.request('GET', '/api/conversations')

    expect(response.status).toBe(401)
  })

  it('issues a CSRF token', async () => {
    const client = new Client()
    const response = await client.request('GET', '/api/auth/csrf')

    expect(response.status).toBe(200)
    expect(typeof response.data['token']).toBe('string')
  })

  it('rejects a state changing request without the CSRF token', async () => {
    const client = new Client()

    const response = await client.request('POST', '/api/auth/register', {
      email: `no-csrf-${Date.now()}@example.com`,
      nickname: `NoCsrf${Date.now()}`,
      password: 'Secret123!',
      password_confirmation: 'Secret123!',
    })

    expect(response.status).toBe(419)
  })

  it('answers the login challenge when the device is not trusted', async () => {
    const client = new Client()

    await client.refreshCsrfToken()

    const response = await client.request('POST', '/api/auth/login', {
      email: 'admin@chat.local',
      password: 'Admin123!',
    })

    // The challenge carries the address that must be verified.
    expect(response.data['requires_otp']).toBe(true)
    expect(response.data['email']).toBe('admin@chat.local')
  })

  it('publishes a message in the global room', async () => {
    const client = adminClient

    const body = `Mensaje de verificación ${Date.now()}`

    const response = await client.request('POST', '/api/conversations/1/messages', { body })

    expect(response.status).toBe(201)

    const message = response.data['message'] as { body: string; id: number }

    expect(message.body).toBe(body)

    const list = await client.request('GET', '/api/conversations/1/messages')

    expect(list.status).toBe(200)

    const messages = (list.data['data'] as Array<{ body: string }>).map((item) => item.body)

    expect(messages).toContain(body)
  })

  it('edits and deletes a message', async () => {
    const client = adminClient

    const created = await client.request('POST', '/api/conversations/1/messages', {
      body: 'Mensaje que se va a editar',
    })

    const message = created.data['message'] as { id: number }

    const updated = await client.request('PATCH', `/api/messages/${message.id}`, {
      body: 'Mensaje editado',
    })

    expect(updated.status).toBe(200)

    const removed = await client.request('DELETE', `/api/messages/${message.id}`)

    expect(removed.status).toBe(200)
  })

  it('reacts to a message', async () => {
    const client = adminClient

    const created = await client.request('POST', '/api/conversations/1/messages', {
      body: 'Mensaje con reacción',
    })

    const message = created.data['message'] as { id: number }

    const reacted = await client.request('POST', `/api/messages/${message.id}/reactions`, {
      emoji: '👍',
    })

    expect(reacted.status).toBe(200)
  })

  it('exposes the administration statistics to the administrators', async () => {
    const client = adminClient

    const response = await client.request('GET', '/api/admin/statistics')

    expect(response.status).toBe(200)
    expect(response.data['data']).toBeDefined()
  })
})
