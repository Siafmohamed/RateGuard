import type { ZodType } from 'zod'

/**
 * Validate a response body at the API-function boundary.
 *
 * In development and tests a mismatch throws, so drift is caught immediately
 * (and surfaces in the Vitest suite). In production we log and carry on with the
 * raw payload rather than blanking the screen over a cosmetic field change.
 */
export function parseResponse<T>(schema: ZodType<T>, data: unknown, context: string): T {
  if (import.meta.env.PROD) {
    const result = schema.safeParse(data)

    if (!result.success) {
      console.error(`[api] unexpected response shape for ${context}`, result.error, data)

      return data as T
    }

    return result.data
  }

  return schema.parse(data)
}
