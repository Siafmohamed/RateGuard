import { useEffect } from 'react'

import { useUiStore, type Toast } from '@/stores/ui'

const TONES: Record<Toast['tone'], string> = {
  error: 'bg-red-600',
  info: 'bg-slate-800',
  success: 'bg-emerald-600',
}

const DISMISS_AFTER_MS = 6000

function ToastItem({ toast }: { toast: Toast }) {
  const dismissToast = useUiStore((state) => state.dismissToast)

  useEffect(() => {
    const timer = window.setTimeout(() => dismissToast(toast.id), DISMISS_AFTER_MS)

    return () => window.clearTimeout(timer)
  }, [dismissToast, toast.id])

  return (
    <div
      role="status"
      className={`pointer-events-auto flex items-start justify-between gap-3 rounded-md px-4 py-3 text-sm text-white shadow-lg ${TONES[toast.tone]}`}
    >
      <span>{toast.message}</span>
      <button
        type="button"
        onClick={() => dismissToast(toast.id)}
        aria-label="Dismiss notification"
        className="text-white/80 hover:text-white"
      >
        &times;
      </button>
    </div>
  )
}

/**
 * The single aria-live region for transient feedback (429 rate limits, network
 * errors, confirmations). Screen readers announce additions politely.
 */
export function ToastRegion() {
  const toasts = useUiStore((state) => state.toasts)

  return (
    <div
      aria-live="polite"
      aria-atomic="false"
      className="pointer-events-none fixed bottom-4 right-4 z-50 flex w-80 max-w-[calc(100vw-2rem)] flex-col gap-2"
    >
      {toasts.map((toast) => (
        <ToastItem key={toast.id} toast={toast} />
      ))}
    </div>
  )
}
