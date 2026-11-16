import { QueryClient } from '@tanstack/react-query'

import { ApiError } from '@/lib/api/errors'

/**
 * Shared defaults.
 *
 * Retries are limited to failures that are plausibly transient: 5xx, or a
 * transport error (status 0). Every 4xx - and any non-API error such as a Zod
 * mismatch - is surfaced immediately. At most two retries, as specified.
 */
export function createQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: 15_000,
        refetchOnWindowFocus: false,
        retry: (failureCount, error) => {
          if (!(error instanceof ApiError)) {
            return false
          }

          const transient = error.status === 0 || error.status >= 500

          return transient && failureCount < 2
        },
      },
      mutations: {
        // Mutations are never retried: the only safe replay (a 419) is handled
        // inside the interceptor, where we know the controller never ran.
        retry: false,
      },
    },
  })
}
