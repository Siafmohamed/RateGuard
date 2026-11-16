import { useQuery } from '@tanstack/react-query'

import { fetchSession } from '@/lib/api/auth'
import { queryKeys } from '@/lib/queryKeys'

/**
 * The auth bootstrap: `GET /auth/me`. A 401 here is expected when nobody is
 * signed in and is deliberately exempt from the global redirect in the
 * interceptor, so this hook simply reports the error.
 */
export function useSession() {
  return useQuery({
    queryKey: queryKeys.session,
    queryFn: ({ signal }) => fetchSession(signal),
    retry: false,
    staleTime: 5 * 60_000,
  })
}
