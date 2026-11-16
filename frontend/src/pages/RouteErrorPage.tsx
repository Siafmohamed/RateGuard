import { isRouteErrorResponse, Link, useRouteError } from 'react-router-dom'

/**
 * Rendered by React Router when a route throws (a failed loader, a bad
 * parameter...). The class `ErrorBoundary` covers render errors; this covers the
 * errors the router itself owns.
 */
export function RouteErrorPage() {
  const error = useRouteError()

  const status = isRouteErrorResponse(error) ? error.status : 500
  const message = isRouteErrorResponse(error)
    ? error.statusText || 'The request failed.'
    : 'An unexpected error occurred while rendering this page.'

  return (
    <div className="mx-auto max-w-lg px-4 py-20 text-center" role="alert">
      <p className="text-sm font-semibold text-red-600">{status}</p>
      <h1 className="mt-2 text-2xl font-semibold text-slate-900">Something went wrong</h1>
      <p className="mt-2 text-sm text-slate-500">{message}</p>
      <Link
        to="/app/invoices"
        className="mt-6 inline-block text-sm font-medium text-indigo-600 hover:text-indigo-500"
      >
        Back to invoices
      </Link>
    </div>
  )
}
