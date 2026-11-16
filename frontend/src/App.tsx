import { RouterProvider } from 'react-router-dom'

import { ErrorBoundary } from '@/components/ErrorBoundary'
import { router } from '@/router'

/**
 * Composition root: a render-error boundary wrapping the router tree. Route
 * errors are handled by the router's own `errorElement` (see src/router.tsx).
 */
export function App() {
  return (
    <ErrorBoundary>
      <RouterProvider router={router} />
    </ErrorBoundary>
  )
}
