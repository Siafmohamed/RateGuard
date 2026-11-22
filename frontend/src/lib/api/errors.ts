/**
 * Every failed request is normalised into an `ApiError`, so callers (and React
 * Query) never have to know about axios. The interceptors only emit through the
 * callbacks handed to `installInterceptors`, which keeps them free of router and
 * store imports - and therefore testable in isolation.
 */

/** Laravel's `errors` bag: field name -> list of messages. */
export type FieldErrors = Record<string, string[]>

export interface ApiErrorInit {
  status: number
  code?: string | null
  message: string
  fieldErrors?: FieldErrors
  retryAfter?: number | null
  requestId?: string | null
}

export class ApiError extends Error {
  /** HTTP status, or 0 for a transport-level failure (offline, DNS, timeout). */
  readonly status: number
  /** Machine-readable code from the body, e.g. `plan_limit_reached`. */
  readonly code: string | null
  readonly fieldErrors: FieldErrors
  /** Seconds from `Retry-After`, when the server sent one (429 / 503). */
  readonly retryAfter: number | null
  readonly requestId: string | null

  constructor(init: ApiErrorInit) {
    super(init.message)
    this.name = 'ApiError'
    this.status = init.status
    this.code = init.code ?? null
    this.fieldErrors = init.fieldErrors ?? {}
    this.retryAfter = init.retryAfter ?? null
    this.requestId = init.requestId ?? null
  }

  get isNetworkError(): boolean {
    return this.status === 0
  }

  get hasFieldErrors(): boolean {
    return Object.keys(this.fieldErrors).length > 0
  }

  /** True for anything the client should not blindly retry. */
  get isClientError(): boolean {
    return this.status >= 400 && this.status < 500
  }
}

const FRIENDLY_MESSAGES: Record<number, string> = {
  400: 'That request could not be processed.',
  401: 'Your session has expired. Please sign in again.',
  402: 'Your plan limit has been reached.',
  403: 'You do not have access to this resource.',
  404: 'We could not find what you were looking for.',
  409: 'That action conflicts with the current state.',
  413: 'That file is too large.',
  419: 'Your session expired. Please try again.',
  422: 'Please correct the highlighted fields.',
  429: 'Too many requests. Please slow down.',
  500: 'Something went wrong on our end.',
  501: 'Online payments are not available yet.',
  502: 'The server is temporarily unavailable.',
  503: 'The service is temporarily unavailable.',
  504: 'The server took too long to respond.',
}

/**
 * A user-facing message for a failure, preferring the server's own message when
 * it sent one (Laravel's `message` is already human-readable) and falling back
 * to a friendly per-status default.
 */
export function friendlyMessage(status: number, serverMessage?: string | null): string {
  if (serverMessage && serverMessage.trim() !== '') {
    return serverMessage
  }

  if (status === 0) {
    return 'We could not reach the server. Check your connection and try again.'
  }

  return FRIENDLY_MESSAGES[status] ?? 'Something went wrong. Please try again.'
}

/**
 * Type guard so call sites can narrow an `unknown` thrown value without
 * instanceof gymnastics.
 */
export function isApiError(error: unknown): error is ApiError {
  return error instanceof ApiError
}
