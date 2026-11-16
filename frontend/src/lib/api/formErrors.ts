import type { FieldValues, Path, UseFormSetError } from 'react-hook-form'

import { isApiError } from './errors'

/**
 * Apply a 422 (or any) `ApiError`'s field errors onto a React Hook Form via
 * `setError`, so server-side validation lights up the same fields the client
 * validated. Returns true when at least one field error was applied.
 *
 * Fields the form does not know about are still set (React Hook Form tolerates
 * it); callers can additionally show `error.message` in a form-level banner.
 */
export function applyApiFieldErrors<T extends FieldValues>(
  setError: UseFormSetError<T>,
  error: unknown,
): boolean {
  if (!isApiError(error) || !error.hasFieldErrors) {
    return false
  }

  for (const [field, messages] of Object.entries(error.fieldErrors)) {
    setError(field as Path<T>, { type: 'server', message: messages[0] ?? 'Invalid value.' })
  }

  return true
}
