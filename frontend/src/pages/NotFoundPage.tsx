import { Link } from 'react-router-dom'

export function NotFoundPage() {
  return (
    <div className="mx-auto max-w-lg px-4 py-20 text-center">
      <p className="text-sm font-semibold text-indigo-600">404</p>
      <h1 className="mt-2 text-2xl font-semibold text-slate-900">Page not found</h1>
      <p className="mt-2 text-sm text-slate-500">
        The page you were looking for does not exist or has moved.
      </p>
      <div className="mt-6 flex justify-center gap-4 text-sm">
        <Link to="/app/invoices" className="font-medium text-indigo-600 hover:text-indigo-500">
          Go to invoices
        </Link>
        <Link to="/blog" className="font-medium text-indigo-600 hover:text-indigo-500">
          Read the blog
        </Link>
      </div>
    </div>
  )
}
