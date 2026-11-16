import { StrictMode } from 'react'

import { QueryClientProvider } from '@tanstack/react-query'
import { createRoot } from 'react-dom/client'

import './index.css'

import { App } from './App'
import { installInterceptors } from './lib/api/client'
import type { Organization } from './lib/api/schemas'
import {
  detachServerRenderedShell,
  readInitialContent,
  seedFromInitialContent,
  type InitialContentRoute,
} from './lib/hydration'
import { queryKeys } from './lib/queryKeys'
import { createQueryClient } from './lib/queryClient'
import { router } from './router'
import { useActiveOrganizationStore } from './stores/activeOrganization'
import { useUiStore } from './stores/ui'

const queryClient = createQueryClient()

/**
 * Map the server-rendered pathname onto the Phase 5 hydration contract. Only
 * the three public content routes ever carry an inlined payload.
 */
function initialContentRouteFor(pathname: string): InitialContentRoute | null {
  if (pathname === '/blog' || pathname === '/blog/') {
    return { kind: 'post-list' }
  }

  const post = /^\/blog\/([a-z0-9-]+)$/.exec(pathname)

  if (post) {
    return { kind: 'post', slug: post[1] }
  }

  const page = /^\/docs\/([a-z0-9-]+)$/.exec(pathname)

  if (page) {
    return { kind: 'page', slug: page[1] }
  }

  return null
}

/*
 * Hydration, in order:
 *  1. seed the cache from the inlined payload (a match on type - and slug for a
 *     document - is required), and
 *  2. read-and-delete `window.__INITIAL_CONTENT__` exactly once, whether or not
 *     it was usable, so a later navigation can never re-seed from it, and
 *  3. drop the server-rendered `#seo-content` sibling that `createRoot` does not
 *     own.
 */
const initialRoute = initialContentRouteFor(window.location.pathname)

if (initialRoute) {
  seedFromInitialContent(queryClient, initialRoute)
} else {
  // No matching route: still consume the global so it is read at most once.
  readInitialContent()
}

detachServerRenderedShell()

/*
 * Wire the interceptor callbacks. The interceptors themselves import neither
 * the router nor the stores; the side effects live here, at the composition
 * root, where importing both is safe.
 */
installInterceptors({
  onUnauthorized: () => {
    queryClient.clear()
    useActiveOrganizationStore.getState().reset()
    void router.navigate('/login')
  },

  onPlanLimit: (details) => {
    useUiStore.getState().openUpgrade(details)
  },

  onRateLimit: (seconds) => {
    useUiStore
      .getState()
      .pushToast(`Too many requests. Please try again in ${seconds}s.`, 'info')
  },

  onForbiddenOrg: async () => {
    // The organization is gone: fall back to the first membership and refetch
    // everything under the new tenant.
    const organizations =
      queryClient.getQueryData<Organization[]>(queryKeys.organizations) ?? []

    useActiveOrganizationStore.getState().setOrganizationId(organizations[0]?.id ?? null)

    await queryClient.invalidateQueries()
  },
})

const container = document.getElementById('root')

if (!container) {
  throw new Error('RateGuard: #root container is missing from the SPA shell.')
}

// `createRoot`, never `hydrateRoot`: the server-rendered shell is replaced, not
// adopted (the markup React produced on the server is only a fallback for
// crawlers and no-JS clients).
createRoot(container).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <App />
    </QueryClientProvider>
  </StrictMode>,
)
