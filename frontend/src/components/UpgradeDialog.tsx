import { useQuery } from '@tanstack/react-query'

import { Button } from '@/components/ui/Button'
import { fetchPlans } from '@/lib/api/billing'
import { planLimitDetailsSchema } from '@/lib/api/schemas'
import { formatCents } from '@/lib/money'
import { queryKeys } from '@/lib/queryKeys'
import { useUiStore } from '@/stores/ui'

/**
 * Global dialog opened by the interceptor's `onPlanLimit` callback when the API
 * answers 402 with `code: plan_limit_reached`. It lists the plan catalogue
 * (GET /billing/plans); the upgrade action stays disabled until a payment
 * provider exists, so payment is advertised but never faked.
 */
export function UpgradeDialog() {
  const open = useUiStore((state) => state.upgradeOpen)
  const details = useUiStore((state) => state.upgradeDetails)
  const closeUpgrade = useUiStore((state) => state.closeUpgrade)

  // Only fetch while the dialog is open, so a closed dialog costs nothing.
  const plansQuery = useQuery({
    queryKey: queryKeys.billingPlans,
    queryFn: ({ signal }) => fetchPlans(signal),
    enabled: open,
    retry: false,
  })

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

        {plansQuery.data ? (
          <ul className="mt-4 space-y-2">
            {plansQuery.data.plans.map((plan) => (
              <li
                key={plan.code}
                className="flex items-center justify-between gap-3 rounded-md border border-slate-200 px-3 py-2"
              >
                <span className="text-sm font-medium text-slate-900">{plan.name}</span>
                <span className="text-xs text-slate-500">
                  {formatCents(plan.price_cents)} / month ·{' '}
                  {plan.invoice_limit === null
                    ? 'Unlimited invoices'
                    : `${plan.invoice_limit} invoices`}
                </span>
              </li>
            ))}
          </ul>
        ) : null}

        <div className="mt-6 flex justify-end gap-2">
          <Button variant="secondary" onClick={closeUpgrade}>
            Not now
          </Button>
          {/* No payment provider yet: the action is advertised, not faked. */}
          <Button disabled title="Payments coming soon">
            Payments coming soon
          </Button>
        </div>
      </div>
    </div>
  )
}
