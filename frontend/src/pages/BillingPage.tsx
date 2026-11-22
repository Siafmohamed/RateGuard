import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'

import { Button } from '@/components/ui/Button'
import { EmptyState } from '@/components/ui/EmptyState'
import { SkeletonRows } from '@/components/ui/Skeleton'
import { useActiveOrganization } from '@/hooks/useActiveOrganization'
import {
  cancelSubscription,
  fetchBilling,
  openBillingPortal,
  resumeSubscription,
} from '@/lib/api/billing'
import { isApiError } from '@/lib/api/errors'
import type { Plan } from '@/lib/api/schemas'
import { formatCents } from '@/lib/money'
import { queryKeys } from '@/lib/queryKeys'
import { can } from '@/lib/roles'
import { useUiStore } from '@/stores/ui'

/** Percentage of the plan's invoice allowance used, floored to an integer. */
function usagePercent(used: number, limit: number | null | undefined): number | null {
  if (limit === null || limit === undefined || limit <= 0) {
    return null
  }

  return Math.min(100, Math.floor((used * 100) / limit))
}

/**
 * Billing overview. Only an owner sees the mutating controls; the plan cards
 * are shown to everyone so the upgrade path is discoverable. Checkout is not
 * wired up yet (no payment provider), so the upgrade affordance is advertised
 * as "Payments coming soon" rather than firing a call that would 501.
 */
export function BillingPage() {
  const { role } = useActiveOrganization()
  const isOwner = can(role, 'billing')
  const queryClient = useQueryClient()
  const pushToast = useUiStore((state) => state.pushToast)

  const query = useQuery({
    queryKey: queryKeys.billing,
    queryFn: ({ signal }) => fetchBilling(signal),
    // The billing backend does not exist yet; a 404 must not be retried.
    retry: false,
  })

  function invalidate() {
    void queryClient.invalidateQueries({ queryKey: queryKeys.billing })
  }

  function reportError(error: unknown) {
    pushToast(error instanceof Error ? error.message : 'That action failed.')
  }

  const portalMutation = useMutation({
    mutationFn: () => openBillingPortal(),
    onSuccess: (url) => window.location.assign(url),
    onError: reportError,
  })

  const cancelMutation = useMutation({
    mutationFn: () => cancelSubscription(),
    onSuccess: () => {
      pushToast('Your subscription will end at the close of the period.', 'success')
      invalidate()
    },
    onError: reportError,
  })

  const resumeMutation = useMutation({
    mutationFn: () => resumeSubscription(),
    onSuccess: () => {
      pushToast('Your subscription is active again.', 'success')
      invalidate()
    },
    onError: reportError,
  })

  if (query.isPending) {
    return <SkeletonRows rows={5} />
  }

  if (query.isError) {
    return (
      <EmptyState
        title="Billing is not available yet"
        description={
          isApiError(query.error) && query.error.status === 404
            ? 'The billing service is not enabled for this environment.'
            : 'We could not load your billing information.'
        }
        action={
          <Button variant="secondary" onClick={() => void query.refetch()}>
            Try again
          </Button>
        }
      />
    )
  }

  const billing = query.data
  const limit = billing?.plan?.invoice_limit ?? null
  const percent = billing ? usagePercent(billing.invoices_used, limit) : null
  const currentPlanCode = billing?.plan?.code ?? null

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-semibold text-slate-900">Billing</h1>
        <p className="text-sm text-slate-500">Your plan, usage and subscription.</p>
      </div>

      <div className="grid gap-4 sm:grid-cols-3">
        <div className="rounded-lg border border-slate-200 bg-white p-4">
          <p className="text-xs uppercase tracking-wide text-slate-500">Current plan</p>
          <p className="mt-1 text-lg font-semibold text-slate-900">
            {billing?.plan?.name ?? 'No plan'}
          </p>
          {billing?.status ? (
            <p className="mt-1 text-xs text-slate-500">Status: {billing.status}</p>
          ) : null}
        </div>

        <div className="rounded-lg border border-slate-200 bg-white p-4">
          <p className="text-xs uppercase tracking-wide text-slate-500">Renews</p>
          <p className="mt-1 text-sm text-slate-800">{billing?.renews_at ?? '—'}</p>
          {billing?.ends_at ? (
            <p className="mt-1 text-xs text-slate-500">Ends: {billing.ends_at}</p>
          ) : null}
        </div>

        <div className="rounded-lg border border-slate-200 bg-white p-4">
          <p className="text-xs uppercase tracking-wide text-slate-500">Invoices this month</p>
          <p className="mt-1 text-lg font-semibold tabular-nums text-slate-900">
            {billing ? `${billing.invoices_used} / ${limit ?? '∞'}` : '—'}
          </p>
          <p className="mt-1 text-xs text-slate-500">
            {limit === null ? 'Unlimited invoices on this plan' : 'invoices used this month'}
          </p>
          {percent !== null ? (
            <div
              role="progressbar"
              aria-valuemin={0}
              aria-valuemax={100}
              aria-valuenow={percent}
              aria-label="Plan usage"
              className="mt-2 h-2 w-full overflow-hidden rounded bg-slate-200"
            >
              <div className="h-full bg-indigo-600" style={{ width: `${percent}%` }} />
            </div>
          ) : null}
        </div>
      </div>

      {isOwner ? (
        <div className="flex flex-wrap gap-2">
          <Button
            variant="secondary"
            loading={portalMutation.isPending}
            onClick={() => portalMutation.mutate()}
          >
            Manage payment method
          </Button>
          {billing?.status === 'active' ? (
            <Button
              variant="danger"
              loading={cancelMutation.isPending}
              onClick={() => cancelMutation.mutate()}
            >
              Cancel subscription
            </Button>
          ) : null}
          {billing?.status === 'canceled' || billing?.status === 'cancelled' ? (
            <Button loading={resumeMutation.isPending} onClick={() => resumeMutation.mutate()}>
              Resume subscription
            </Button>
          ) : null}
        </div>
      ) : (
        <p className="text-sm text-slate-500">
          Only an owner can change the subscription. Ask an owner of this organization.
        </p>
      )}

      <section className="space-y-3">
        <h2 className="text-base font-semibold text-slate-900">Plans</h2>

        {billing && billing.plans.length === 0 ? (
          <EmptyState title="No plans available" />
        ) : null}

        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {billing?.plans.map((plan: Plan) => (
            <div
              key={plan.code}
              className="flex flex-col rounded-lg border border-slate-200 bg-white p-4"
            >
              <p className="text-sm font-semibold text-slate-900">{plan.name}</p>
              <p className="mt-1 text-2xl font-semibold tabular-nums text-slate-900">
                {formatCents(plan.price_cents)}
                <span className="text-sm font-normal text-slate-500"> / month</span>
              </p>
              <p className="mt-1 text-sm text-slate-500">
                {plan.invoice_limit === null
                  ? 'Unlimited invoices'
                  : `Up to ${plan.invoice_limit} invoices`}
              </p>

              <div className="mt-4">
                {plan.code === currentPlanCode ? (
                  <Button variant="secondary" disabled>
                    Current plan
                  </Button>
                ) : (
                  // No payment provider is integrated yet, so the upgrade path is
                  // advertised rather than faked: the button is inert and says why.
                  // The backend still answers 501 payments_not_available if called
                  // directly, and the client turns that into a friendly toast.
                  <Button disabled title="Payments coming soon">
                    Payments coming soon
                  </Button>
                )}
              </div>
            </div>
          ))}
        </div>
      </section>
    </div>
  )
}
