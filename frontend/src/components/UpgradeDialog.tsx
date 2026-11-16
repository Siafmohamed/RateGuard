import { useNavigate } from 'react-router-dom'

import { Button } from '@/components/ui/Button'
import { planLimitDetailsSchema } from '@/lib/api/schemas'
import { useUiStore } from '@/stores/ui'

/**
 * Global dialog opened by the interceptor's `onPlanLimit` callback when the API
 * answers 402 with `code: plan_limit_reached`.
 */
export function UpgradeDialog() {
  const open = useUiStore((state) => state.upgradeOpen)
  const details = useUiStore((state) => state.upgradeDetails)
  const closeUpgrade = useUiStore((state) => state.closeUpgrade)
  const navigate = useNavigate()

  if (!open) {
    return null
  }

  const parsed = planLimitDetailsSchema.safeParse(details)
  const description = parsed.success
    ? parsed.data.message
    : 'You have reached the limit included in your current plan.'

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="upgrade-dialog-title"
      className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4"
    >
      <div className="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
        <h2 id="upgrade-dialog-title" className="text-lg font-semibold text-slate-900">
          Plan limit reached
        </h2>
        <p className="mt-2 text-sm text-slate-600">{description}</p>

        {parsed.success && parsed.data.limit !== undefined ? (
          <p className="mt-2 text-sm text-slate-500">
            Limit: {parsed.data.limit}
            {parsed.data.used !== undefined ? ` · Used: ${parsed.data.used}` : ''}
          </p>
        ) : null}

        <div className="mt-6 flex justify-end gap-2">
          <Button variant="secondary" onClick={closeUpgrade}>
            Not now
          </Button>
          <Button
            onClick={() => {
              closeUpgrade()
              navigate('/app/billing')
            }}
          >
            View plans
          </Button>
        </div>
      </div>
    </div>
  )
}
