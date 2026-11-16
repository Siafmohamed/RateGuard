import { http, HttpResponse } from 'msw'
import { describe, expect, it, vi } from 'vitest'

import { server } from '@/test/server'

import { api, ensureCsrfCookie, installInterceptors, setActiveOrganizationId } from './client'
import { ApiError } from './errors'

type Callbacks = Parameters<typeof installInterceptors>[0]

function install(overrides: Partial<Callbacks> = {}): Callbacks {
  const callbacks: Callbacks = {
    onUnauthorized: vi.fn(),
    onPlanLimit: vi.fn(),
    onRateLimit: vi.fn(),
    onForbiddenOrg: vi.fn(),
    ...overrides,
  }

  installInterceptors(callbacks)

  return callbacks
}

async function capture(promise: Promise<unknown>): Promise<ApiError> {
  try {
    await promise
  } catch (error) {
    if (error instanceof ApiError) {
      return error
    }

    throw error
  }

  throw new Error('Expected the request to reject.')
}

describe('csrf (419)', () => {
  it('fetches the cookie once and replays the original request exactly once', async () => {
    install()

    let attempts = 0
    let csrfCalls = 0

    server.use(
      http.post('/api/v1/invoices/upload', () => {
        attempts += 1

        return attempts === 1
          ? new HttpResponse(null, { status: 419 })
          : HttpResponse.json({ id: 7 })
      }),
      http.get('/sanctum/csrf-cookie', () => {
        csrfCalls += 1

        return new HttpResponse(null, { status: 204 })
      }),
    )

    const response = await api.post('/invoices/upload', new FormData())

    expect(response.data).toEqual({ id: 7 })
    expect(attempts).toBe(2)
    expect(csrfCalls).toBe(1)
  })

  it('rejects on a second 419 instead of looping', async () => {
    install()

    let attempts = 0

    server.use(
      http.post('/api/v1/invoices/upload', () => {
        attempts += 1

        return new HttpResponse(null, { status: 419 })
      }),
      http.get('/sanctum/csrf-cookie', () => new HttpResponse(null, { status: 204 })),
    )

    const error = await capture(api.post('/invoices/upload', new FormData()))

    expect(error.status).toBe(419)
    // The original request plus the single replay - never more.
    expect(attempts).toBe(2)
  })

  it('shares one csrf-cookie request across concurrent callers', async () => {
    install()

    let csrfCalls = 0

    server.use(
      http.get('/sanctum/csrf-cookie', async () => {
        csrfCalls += 1
        // Yield so the other callers get a chance to join the flight.
        await Promise.resolve()

        return new HttpResponse(null, { status: 204 })
      }),
    )

    await Promise.all([ensureCsrfCookie(), ensureCsrfCookie(), ensureCsrfCookie()])

    expect(csrfCalls).toBe(1)
  })

  it('recovers a burst of parallel 419s without deadlocking', async () => {
    install()

    const attempts = new Map<string, number>()

    server.use(
      http.post('/api/v1/a', () => {
        const count = (attempts.get('/a') ?? 0) + 1

        attempts.set('/a', count)

        return count === 1 ? new HttpResponse(null, { status: 419 }) : HttpResponse.json({ ok: 'a' })
      }),
      http.post('/api/v1/b', () => {
        const count = (attempts.get('/b') ?? 0) + 1

        attempts.set('/b', count)

        return count === 1 ? new HttpResponse(null, { status: 419 }) : HttpResponse.json({ ok: 'b' })
      }),
      http.post('/api/v1/c', () => {
        const count = (attempts.get('/c') ?? 0) + 1

        attempts.set('/c', count)

        return count === 1 ? new HttpResponse(null, { status: 419 }) : HttpResponse.json({ ok: 'c' })
      }),
      http.get('/sanctum/csrf-cookie', () => new HttpResponse(null, { status: 204 })),
    )

    const responses = await Promise.all([api.post('/a'), api.post('/b'), api.post('/c')])

    expect(responses.map((response) => response.data)).toEqual([
      { ok: 'a' },
      { ok: 'b' },
      { ok: 'c' },
    ])
  })
})

describe('unauthorized (401)', () => {
  it('fires onUnauthorized once for three parallel failures', async () => {
    const callbacks = install()

    server.use(
      http.get('/api/v1/a', () => new HttpResponse(null, { status: 401 })),
      http.get('/api/v1/b', () => new HttpResponse(null, { status: 401 })),
      http.get('/api/v1/c', () => new HttpResponse(null, { status: 401 })),
    )

    await Promise.allSettled([api.get('/a'), api.get('/b'), api.get('/c')])

    expect(callbacks.onUnauthorized).toHaveBeenCalledTimes(1)
  })

  it('never redirects on a failed login and surfaces field errors instead', async () => {
    const callbacks = install()

    server.use(
      http.post('/api/v1/auth/login', () =>
        HttpResponse.json(
          {
            message: 'These credentials do not match our records.',
            errors: { email: ['These credentials do not match our records.'] },
          },
          { status: 422 },
        ),
      ),
    )

    const error = await capture(api.post('/auth/login', { email: 'a@b.c', password: 'x' }))

    expect(error.status).toBe(422)
    expect(error.fieldErrors.email).toEqual(['These credentials do not match our records.'])
    expect(callbacks.onUnauthorized).not.toHaveBeenCalled()
  })

  it('treats a 401 on the session bootstrap as expected, not as a redirect', async () => {
    const callbacks = install()

    server.use(http.get('/api/v1/auth/me', () => new HttpResponse(null, { status: 401 })))

    const error = await capture(api.get('/auth/me'))

    expect(error.status).toBe(401)
    expect(callbacks.onUnauthorized).not.toHaveBeenCalled()
  })
})

describe('plan limit (402)', () => {
  it('notifies the upgrade handler with the payload and still rejects', async () => {
    const callbacks = install()

    const details = {
      code: 'plan_limit_reached',
      message: 'You have used all 10 invoices on your plan.',
      limit: 10,
      used: 10,
    }

    server.use(
      http.post('/api/v1/invoices/upload', () => HttpResponse.json(details, { status: 402 })),
    )

    const error = await capture(api.post('/invoices/upload', new FormData()))

    expect(error.status).toBe(402)
    expect(error.code).toBe('plan_limit_reached')
    expect(callbacks.onPlanLimit).toHaveBeenCalledWith(details)
  })
})

describe('rate limit (429)', () => {
  it('reports the Retry-After seconds and never auto-retries', async () => {
    const callbacks = install()

    let attempts = 0

    server.use(
      http.get('/api/v1/invoices', () => {
        attempts += 1

        return new HttpResponse(null, { status: 429, headers: { 'Retry-After': '42' } })
      }),
    )

    const error = await capture(api.get('/invoices'))

    expect(error.status).toBe(429)
    expect(error.retryAfter).toBe(42)
    expect(callbacks.onRateLimit).toHaveBeenCalledWith(42)
    expect(attempts).toBe(1)
  })
})

describe('organization access (403)', () => {
  it('invokes the recovery handler when the active organization is gone', async () => {
    const callbacks = install()

    server.use(
      http.get('/api/v1/invoices', () =>
        HttpResponse.json({ message: 'You do not belong to this organization.' }, { status: 403 }),
      ),
    )

    const error = await capture(api.get('/invoices'))

    expect(error.status).toBe(403)
    expect(callbacks.onForbiddenOrg).toHaveBeenCalledTimes(1)
  })

  it('leaves an ordinary 403 alone', async () => {
    const callbacks = install()

    server.use(
      http.get('/api/v1/invoices', () =>
        HttpResponse.json({ message: 'This action is unauthorized.' }, { status: 403 }),
      ),
    )

    const error = await capture(api.get('/invoices'))

    expect(error.status).toBe(403)
    expect(callbacks.onForbiddenOrg).not.toHaveBeenCalled()
  })
})

describe('tenant header', () => {
  it('attaches X-Organization-Id after the active organization changes', async () => {
    install()

    const seen: Array<string | null> = []

    server.use(
      http.get('/api/v1/invoices', ({ request }) => {
        seen.push(request.headers.get('x-organization-id'))

        return HttpResponse.json({ data: [], meta: {} })
      }),
    )

    setActiveOrganizationId(11)
    await api.get('/invoices')

    setActiveOrganizationId(22)
    await api.get('/invoices')

    expect(seen).toEqual(['11', '22'])
  })

  it('omits the header when no organization is active', async () => {
    install()

    let header: string | null = 'unset'

    server.use(
      http.get('/api/v1/content/posts', ({ request }) => {
        header = request.headers.get('x-organization-id')

        return HttpResponse.json({ data: [], meta: {} })
      }),
    )

    await api.get('/content/posts')

    expect(header).toBeNull()
  })
})

describe('storage', () => {
  it('never writes a token to localStorage or sessionStorage', async () => {
    install()

    server.use(
      http.post('/api/v1/auth/login', () => HttpResponse.json({ user: { id: 1 } })),
      http.get('/api/v1/invoices', () => new HttpResponse(null, { status: 401 })),
    )

    await api.post('/auth/login', {})
    await Promise.allSettled([api.get('/invoices')])

    expect(window.localStorage.length).toBe(0)
    expect(window.sessionStorage.length).toBe(0)
  })
})

describe('transport failures', () => {
  it('normalises a network error to status 0', async () => {
    install()

    server.use(http.get('/api/v1/invoices', () => HttpResponse.error()))

    const error = await capture(api.get('/invoices'))

    expect(error.status).toBe(0)
    expect(error.isNetworkError).toBe(true)
  })
})
