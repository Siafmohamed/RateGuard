import { Navigate, Outlet, useLocation } from 'react-router-dom'

import { SkeletonRows } from '@/components/ui/Skeleton'
import { useSession } from '@/hooks/useSession'

/**
 * Gate for the authenticated application routes. While the session bootstrap is
 * pending we render a skeleton (never a premature redirect); once it settles
 * without a user, the visitor is sent to /login carrying the page they wanted,
 * so they land back there after signing in.
 */
export function ProtectedRoute() {
  const location = useLocation()
  const { data, isPending, isError } = useSession()

  if (isPending) {
    return (
      <div className="mx-auto max-w-3xl p-6">
        <SkeletonRows rows={3} />
      </div>
    )
  }

  if (isError || !data) {
    return <Navigate to="/login" replace state={{ from: location.pathname + location.search }} />
  }

  return <Outlet />
}
