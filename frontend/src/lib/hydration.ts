import type { QueryClient } from '@tanstack/react-query'

import { contentPageSchema, contentPostSchema, postListSchema } from '@/lib/api/schemas'
import { queryKeys } from '@/lib/queryKeys'

/**
 * The Phase 5 SEO shell (resources/views/seo/body.blade.php) inlines this object
 * through App\Domain\Seo\JsonScript just before the app bundle runs.
 */
export interface InitialContent {
  type: string
  data: unknown
  meta?: unknown
}

declare global {
  interface Window {
    __INITIAL_CONTENT__?: InitialContent
  }
}

export type InitialContentRoute =
  | { kind: 'post'; slug: string }
  | { kind: 'page'; slug: string }
  | { kind: 'post-list' }

/**
 * Read the inlined payload exactly once and drop it from `window`, so a later
 * client-side navigation can never re-seed the cache with a stale server
 * payload.
 */
export function readInitialContent(): InitialContent | null {
  if (typeof window === 'undefined') {
    return null
  }

  const initial = window.__INITIAL_CONTENT__

  delete window.__INITIAL_CONTENT__

  return initial ?? null
}

/**
 * Hydration contract with Phase 5.
 *
 * If the inlined payload's `type` (and, for a document, its `slug`) matches the
 * route being rendered, the payload is validated and used to seed the React
 * Query cache, so the first render shows server-sent content with no extra
 * request. This is a cache seeding, NOT `hydrateRoot`: the app still mounts with
 * `createRoot`.
 *
 * @returns true when the cache was seeded.
 */
export function seedFromInitialContent(
  queryClient: QueryClient,
  route: InitialContentRoute,
): boolean {
  const initial = readInitialContent()

  if (!initial) {
    return false
  }

  switch (initial.type) {
    case 'post': {
      if (route.kind !== 'post') {
        return false
      }

      const parsed = contentPostSchema.safeParse(initial.data)

      if (!parsed.success || parsed.data.slug !== route.slug) {
        return false
      }

      queryClient.setQueryData(queryKeys.post(parsed.data.slug), parsed.data)

      return true
    }

    case 'page': {
      if (route.kind !== 'page') {
        return false
      }

      const parsed = contentPageSchema.safeParse(initial.data)

      if (!parsed.success || parsed.data.slug !== route.slug) {
        return false
      }

      queryClient.setQueryData(queryKeys.page(parsed.data.slug), parsed.data)

      return true
    }

    case 'post-list': {
      if (route.kind !== 'post-list') {
        return false
      }

      const page = (initial.meta as { page?: number } | undefined)?.page ?? 1
      const parsed = postListSchema.safeParse({ data: initial.data, meta: initial.meta })

      if (!parsed.success) {
        return false
      }

      queryClient.setQueryData(queryKeys.posts(page), parsed.data)

      return true
    }

    default:
      return false
  }
}

/**
 * Remove the server-rendered `#seo-content` block. Phase 5 documents that the
 * React root REPLACES that element; because `createRoot` only owns `#root`, the
 * sibling has to be taken out explicitly once the client bundle is alive.
 */
export function detachServerRenderedShell(): void {
  if (typeof document === 'undefined') {
    return
  }

  document.getElementById('seo-content')?.remove()
}
