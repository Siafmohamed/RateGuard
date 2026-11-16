import { api } from './client'
import { billingOverviewSchema, checkoutSchema, type BillingOverview } from './schemas'
import { parseResponse } from './validate'

/*
 * NOTE: the billing backend does not exist yet (api/app/Domain/Billing only
 * holds a .gitkeep). These functions implement the documented contract so the UI
 * is complete and wired; they will start returning real data once the endpoints
 * are built. Until then, /app/billing renders its error/empty state.
 */

export async function fetchBilling(signal?: AbortSignal): Promise<BillingOverview> {
  const { data } = await api.get('/billing', { signal })

  return parseResponse(billingOverviewSchema, data, 'GET /billing')
}

/** Returns the hosted checkout URL to redirect the browser to. */
export async function createCheckout(planCode: string): Promise<string> {
  const { data } = await api.post('/billing/checkout', { plan: planCode })

  return parseResponse(checkoutSchema, data, 'POST /billing/checkout').url
}

/** Returns the hosted customer-portal URL. */
export async function openBillingPortal(): Promise<string> {
  const { data } = await api.post('/billing/portal')

  return parseResponse(checkoutSchema, data, 'POST /billing/portal').url
}

export async function cancelSubscription(): Promise<void> {
  await api.post('/billing/cancel')
}

export async function resumeSubscription(): Promise<void> {
  await api.post('/billing/resume')
}
