import axios, {
  type AxiosError,
  type AxiosInstance,
  type AxiosResponse,
  type InternalAxiosRequestConfig,
} from 'axios'

import { ApiError, friendlyMessage, type FieldErrors } from './errors'

/*
 * The axios interceptor pattern lives entirely in this module.
 *
 * Hard rules:
 *  - No import of the router, the stores or React. The interceptors only emit
 *    through the callbacks passed to `installInterceptors`, so they can be unit
 *    tested without a DOM, a router or a Redux-like store.
 *  - No token or credential is ever read from, or written to, storage. Auth is
 *    the Sanctum SPA cookie, which the browser manages on its own.
 */

/** Flags we piggyback on a request config without touching axios' own tyes. */
interface RateGuardRequestConfig extends InternalAxiosRequestConfig {
  /** Set once a 419 has been recovered, so a second 419 rejects instead of looping. */
  _retried?: boolean
}

export interface InterceptorCallbacks {
  /** Session is gone (any non-auth 401). Fired at most once per session. */
  onUnauthorized: () => void | Promise<void>
  /** The org plan limit was reached (402 + `plan_limit_reached`). */
  onPlanLimit: (details: unknown) => void
  /** Server asked us to slow down (429). Argument is `Retry-After` in seconds. */
  onRateLimit: (seconds: number) => void
  /** The active organization is no longer accessible (403); recover by switching. */
  onForbiddenOrg: () => void | Promise<void>
}

/**
 * The single instance every API module uses. `withXSRFToken` makes axios replay
 * the `XSRF-TOKEN` cookie as the `X-XSRF-TOKEN` header automatically, which is
 * what Sanctum expects for stateful SPA requests.
 */
export const api: AxiosInstance = axios.create({
  baseURL: '/api/v1',
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
})

/**
 * The CSRF bootstrap call must not inherit the `/api/v1` baseURL, so it gets its
 * own tiny instance pointed at the site root.
 */
export const csrfClient: AxiosInstance = axios.create({
  baseURL: '/',
  withCredentials: true,
  headers: { Accept: 'application/json' },
})

/* --------------------------------------------------------- active organization */

/*
 * The active organization lives in a Zustand store (the source of truth). The
 * store pushes its id here so the request interceptor can attach the header
 * without importing the store - which keeps this module free of cycles.
 */
let activeOrganizationId: number | null = null

export function setActiveOrganizationId(id: number | null): void {
  activeOrganizationId = id
}

export function getActiveOrganizationId(): number | null {
  return activeOrganizationId
}

/* ------------------------------------------------------------------ single-flight state */

let csrfFlight: Promise<void> | null = null
let unauthorizedFlight: Promise<void> | null = null
let callbacks: InterceptorCallbacks | null = null
let installed = false

/**
 * Ensure the `XSRF-TOKEN` cookie exists, sharing one in-flight request across
 * every concurrent 419 so a burst of failures triggers a single round trip.
 */
export function ensureCsrfCookie(): Promise<void> {
  if (!csrfFlight) {
    csrfFlight = csrfClient
      .get('/sanctum/csrf-cookie')
      .then(() => undefined)
      // A failure here is not fatal: the replayed request simply 419s again and
      // rejects once (the `_retried` flag prevents any loop).
      .catch(() => undefined)
      .finally(() => {
        csrfFlight = null
      })
  }

  return csrfFlight
}

function signalUnauthorized(): void {
  if (unauthorizedFlight) {
    return
  }

  unauthorizedFlight = Promise.resolve()
    .then(() => callbacks?.onUnauthorized())
    .catch(() => undefined)
}

/**
 * Clear the one-shot guards. Called on every successful response, on logout, and
 * by tests, so the next session's first 401 (or the next 419 burst) is handled
 * from a clean slate.
 */
export function resetApiState(): void {
  unauthorizedFlight = null
}

/* --------------------------------------------------------------- interceptors */

function onRequest(config: InternalAxiosRequestConfig): InternalAxiosRequestConfig {
  if (activeOrganizationId !== null) {
    config.headers.set('X-Organization-Id', String(activeOrganizationId))
  }

  return config
}

function onResponse(response: AxiosResponse): AxiosResponse {
  // A successful call proves the session is alive again.
  resetApiState()

  return response
}

/** Login/register and the /auth/me bootstrap surface their failures as form errors. */
const AUTH_EXEMPT_PATHS = ['/auth/login', '/auth/register', '/auth/me']

function isAuthExempt(url: string): boolean {
  return AUTH_EXEMPT_PATHS.some((path) => url.includes(path))
}

function isOrganizationForbidden(message: string): boolean {
  return (
    /organization/i.test(message) && /(no access|do not belong|not a member|forbidden)/i.test(message)
  )
}

function extractFieldErrors(data: Record<string, unknown> | undefined): FieldErrors {
  const raw = data?.errors

  if (!raw || typeof raw !== 'object') {
    return {}
  }

  const fieldErrors: FieldErrors = {}

  for (const [field, value] of Object.entries(raw as Record<string, unknown>)) {
    if (Array.isArray(value)) {
      fieldErrors[field] = value.map(String)
    } else if (typeof value === 'string') {
      fieldErrors[field] = [value]
    }
  }

  return fieldErrors
}

function extractRetryAfter(error: AxiosError): number | null {
  const raw = error.response?.headers?.['retry-after']

  if (raw === undefined || raw === null) {
    return null
  }

  const seconds = Number.parseInt(String(raw), 10)

  return Number.isFinite(seconds) ? seconds : null
}

/**
 * Unwrap the machine-readable fields of an error body.
 *
 * Some endpoints nest them under `error` (the 402 plan-limit body is
 * `{ error: { code, message, ... } }`), while Laravel's default handler and the
 * validation error bag send them flat. Normalising here means every consumer
 * (ApiError, the 402 callback, the upgrade dialog) sees one shape.
 */
function errorPayload(
  data: Record<string, unknown> | undefined,
): Record<string, unknown> | undefined {
  const nested = data?.error

  if (nested && typeof nested === 'object' && !Array.isArray(nested)) {
    return nested as Record<string, unknown>
  }

  return data
}

function extractMessage(
  status: number,
  data: Record<string, unknown> | undefined,
  error: AxiosError,
): string {
  const message = data?.message

  if (typeof message === 'string' && message.trim() !== '') {
    return message
  }

  return friendlyMessage(status, error.message)
}

function toApiError(
  status: number,
  error: AxiosError,
  data: Record<string, unknown> | undefined,
): ApiError {
  const payload = errorPayload(data)

  return new ApiError({
    status,
    code: typeof payload?.code === 'string' ? payload.code : null,
    message: extractMessage(status, payload, error),
    fieldErrors: extractFieldErrors(data),
    retryAfter: extractRetryAfter(error),
    requestId: (error.response?.headers?.['x-request-id'] as string | undefined) ?? null,
  })
}

async function onResponseError(error: unknown): Promise<never> {
  if (!axios.isAxiosError(error)) {
    throw new ApiError({ status: 0, message: friendlyMessage(0) })
  }

  const config = error.config as RateGuardRequestConfig | undefined
  const status = error.response?.status ?? 0
  const data = error.response?.data as Record<string, unknown> | undefined
  const url = config?.url ?? ''

  // 419: the CSRF token is stale or absent. Refresh it once and replay the
  // ORIGINAL request exactly once. The controller never ran, so replaying a POST
  // is safe; a second 419 (or any retried request that 419s) rejects.
  if (status === 419 && config && !config._retried) {
    await ensureCsrfCookie()

    const retryConfig: RateGuardRequestConfig = { ...config, _retried: true }

    return api.request(retryConfig)
  }

  const apiError = toApiError(status, error, data)

  if (status === 401 && !isAuthExempt(url)) {
    signalUnauthorized()
  }

  if (status === 402 && apiError.code === 'plan_limit_reached') {
    callbacks?.onPlanLimit(errorPayload(data))
  }

  if (status === 403 && isOrganizationForbidden(apiError.message)) {
    await callbacks?.onForbiddenOrg()
  }

  if (status === 429) {
    callbacks?.onRateLimit(apiError.retryAfter ?? 60)
  }

  throw apiError
}

/**
 * Wire the interceptors once and (re)bind the callbacks. Called from the app
 * bootstrap; calling it again only swaps the callbacks, which is what makes the
 * setup testable per-test.
 */
export function installInterceptors(next: InterceptorCallbacks): void {
  callbacks = next

  if (installed) {
    return
  }

  installed = true

  api.interceptors.request.use(onRequest)
  api.interceptors.response.use(onResponse, onResponseError)
}
