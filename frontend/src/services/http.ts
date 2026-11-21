import axios, {
  type AxiosError,
  type AxiosInstance,
  type AxiosRequestConfig,
  type AxiosResponse,
  type InternalAxiosRequestConfig,
} from 'axios'

/**
 * A validation error returned by the API.
 */
export interface FieldError {
  field: string
  messages: string[]
}

/**
 * A normalised error that every caller can rely on.
 */
export class ApiError extends Error {
  public readonly status: number
  public readonly fieldErrors: FieldError[]
  public readonly payload: unknown
  /**
   * The original axios error, kept so the callers can inspect the raw
   * response of the endpoints that answer with a challenge.
   */
  public override readonly cause?: AxiosError

  public constructor(
    message: string,
    status: number,
    fieldErrors: FieldError[] = [],
    payload: unknown = null,
    cause?: AxiosError,
  ) {
    super(message)

    this.name = 'ApiError'
    this.status = status
    this.fieldErrors = fieldErrors
    this.payload = payload
    this.cause = cause
  }

  /**
   * The messages of a single field, or an empty array.
   */
  public errorsFor(field: string): string[] {
    return this.fieldErrors.find((error) => error.field === field)?.messages ?? []
  }

  /**
   * Whether the error was caused by invalid credentials.
   */
  public get isValidation(): boolean {
    return this.status === 422
  }

  /**
   * Whether the session expired.
   */
  public get isUnauthenticated(): boolean {
    return this.status === 401
  }
}

/**
 * Whether the browser session cookie is missing, which means the request was
 * rejected by CSRF protection and the session must be refreshed.
 */
function isCsrfFailure(error: AxiosError): boolean {
  return error.response?.status === 419
}

/**
 * Read the CSRF token from the cookie Laravel sets.
 */
function readCookie(name: string): string | null {
  const target = `${name}=`
  const entry = document.cookie
    .split(';')
    .map((cookie) => cookie.trim())
    .find((cookie) => cookie.startsWith(target))

  if (!entry) {
    return null
  }

  return decodeURIComponent(entry.slice(target.length))
}

/**
 * Convert an axios failure into a predictable {@see ApiError}.
 */
function toApiError(error: AxiosError): ApiError {
  const status = error.response?.status ?? 0
  const payload = error.response?.data ?? null

  const fieldErrors: FieldError[] = []

  if (payload && typeof payload === 'object' && 'errors' in payload) {
    const errors = (payload as { errors: Record<string, string[] | string> }).errors

    for (const [field, messages] of Object.entries(errors ?? {})) {
      fieldErrors.push({
        field,
        messages: Array.isArray(messages) ? messages : [messages],
      })
    }
  }

  const message =
    (typeof payload === 'object' && payload !== null && 'message' in payload
      ? String((payload as { message: unknown }).message)
      : '') ||
    error.message ||
    'Ha ocurrido un error inesperado.'

  return new ApiError(message, status, fieldErrors, payload, error)
}

/**
 * The shared HTTP client.
 *
 * The application authenticates with a Sanctum cookie session, so every
 * request sends the session cookie and the CSRF token that Laravel expects.
 */
const client: AxiosInstance = axios.create({
  baseURL: '/api',
  withCredentials: true,
  withXSRFToken: true,
  xsrfCookieName: 'XSRF-TOKEN',
  xsrfHeaderName: 'X-XSRF-TOKEN',
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
  timeout: 30000,
})

client.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  const token = readCookie('XSRF-TOKEN')

  if (token && config.method && !['get', 'head', 'options'].includes(config.method)) {
    config.headers.set('X-XSRF-TOKEN', token)
  }

  return config
})

client.interceptors.response.use(
  (response: AxiosResponse) => response,
  (error: AxiosError) => {
    if (isCsrfFailure(error)) {
      // The session token expired: ask the server for a fresh one and retry
      // the request once.
      return client
        .get('/auth/csrf')
        .catch(() => undefined)
        .then(() => client.request(error.config as AxiosRequestConfig))
    }

    return Promise.reject(toApiError(error))
  },
)

/**
 * Fetch a fresh CSRF token so the first state changing request is not
 * rejected while the session cookie is being created.
 */
export function bootstrapCsrf(): void {
  void client.get('/auth/csrf').catch(() => undefined)
}

/**
 * A thin wrapper around the HTTP client that unwraps the response body.
 */
export const http = {
  async get<T>(url: string, config?: AxiosRequestConfig): Promise<T> {
    const response = await client.get<T>(url, config)

    return response.data
  },

  async post<T>(url: string, data?: unknown, config?: AxiosRequestConfig): Promise<T> {
    const response = await client.post<T>(url, data, config)

    return response.data
  },

  /**
   * Post a request that may answer with a challenge instead of an error.
   *
   * The login endpoint answers with a 401 when the device is not trusted, but
   * the body carries the details of the challenge, so it is delivered as a
   * regular result instead of an exception.
   */
  async postAllowing<T>(url: string, data: unknown, status: number): Promise<T> {
    try {
      const response = await client.post<T>(url, data)

      return response.data
    } catch (error) {
      if (error instanceof ApiError && error.status === status) {
        return error.payload as T
      }

      throw error
    }
  },

  async patch<T>(url: string, data?: unknown, config?: AxiosRequestConfig): Promise<T> {
    const response = await client.patch<T>(url, data, config)

    return response.data
  },

  async put<T>(url: string, data?: unknown, config?: AxiosRequestConfig): Promise<T> {
    const response = await client.put<T>(url, data, config)

    return response.data
  },

  async delete<T>(url: string, config?: AxiosRequestConfig): Promise<T> {
    const response = await client.delete<T>(url, config)

    return response.data
  },
}

export { client }
