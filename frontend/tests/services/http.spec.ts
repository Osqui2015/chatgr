import { describe, expect, it } from 'vitest'
import { AxiosError, AxiosHeaders } from 'axios'

import { ApiError } from '@/services/http'

/**
 * Build an axios error that carries a validation payload.
 */
function makeAxiosError(status: number, data: unknown): AxiosError {
  const error = new AxiosError('Request failed')

  error.status = status
  error.response = {
    status,
    data,
    statusText: '',
    headers: new AxiosHeaders(),
    config: { headers: new AxiosHeaders() },
  }

  return error
}

describe('ApiError', () => {
  it('exposes the field errors returned by the validator', () => {
    const error = new ApiError('Datos inválidos', 422, [
      { field: 'email', messages: ['El correo no es válido.'] },
    ])

    expect(error.isValidation).toBe(true)
    expect(error.errorsFor('email')).toEqual(['El correo no es válido.'])
    expect(error.errorsFor('nickname')).toEqual([])
  })

  it('flags an expired session', () => {
    const error = new ApiError('Sesión expirada', 401)

    expect(error.isUnauthenticated).toBe(true)
    expect(error.isValidation).toBe(false)
  })
})

describe('the error conversion', () => {
  it('keeps the payload of a failed request', () => {
    const error = makeAxiosError(422, {
      message: 'Datos inválidos',
      errors: {
        email: ['El correo ya está registrado.'],
        password: ['La contraseña es demasiado corta.'],
      },
    })

    expect(error.response?.status).toBe(422)
  })
})
