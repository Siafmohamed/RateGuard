import { Component, type ErrorInfo, type ReactNode } from 'react'

interface ErrorBoundaryProps {
  children: ReactNode
}

interface ErrorBoundaryState {
  error: Error | null
}

/**
 * Route-level boundary: a rendering failure in one screen shows a recoverable
 * message instead of unmounting the whole application.
 */
export class ErrorBoundary extends Component<ErrorBoundaryProps, ErrorBoundaryState> {
  constructor(props: ErrorBoundaryProps) {
    super(props)

    this.state = { error: null }
  }

  static getDerivedStateFromError(error: Error): ErrorBoundaryState {
    return { error }
  }

  override componentDidCatch(error: Error, info: ErrorInfo): void {
    console.error('[ui] unhandled render error', error, info)
  }

  override render(): ReactNode {
    const { error } = this.state

    if (!error) {
      return this.props.children
    }

    return (
      <div role="alert" className="mx-auto max-w-lg rounded-lg border border-red-200 bg-white p-6">
        <h2 className="text-base font-semibold text-slate-900">Something went wrong</h2>
        <p className="mt-2 text-sm text-slate-600">
          This page could not be displayed. Reloading usually fixes it.
        </p>
        <pre className="mt-3 overflow-x-auto rounded bg-slate-50 p-3 text-xs text-slate-500">
          {error.message}
        </pre>
        <button
          type="button"
          onClick={() => window.location.reload()}
          className="mt-4 rounded-md bg-indigo-600 px-3.5 py-2 text-sm font-medium text-white hover:bg-indigo-500"
        >
          Reload
        </button>
      </div>
    )
  }
}
