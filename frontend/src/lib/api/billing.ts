import { api } from './client'
import {
  billingOverviewSchema,
  checkoutSchema,
  planListSchema,
  type BillingOverview,
  type PlanList,
} from './schemas'
import { parseResponse } from './validate'

/*
 * The billing endpoints. The plan catalogue and the quota come from the API;
 * the payment-provider endpoints (checkout, portal) report 501 until a provider
 * is integrated, so the UI must treat them as "coming soon" rather than broken.
 */

export async function fetchBilling(signal?: AbortSignal): Promise<BillingOverview> {
  const { data } = await api.get('/billing', { signal })

  return parseResponse(billingOverviewSchema, data, 'GET /billing')
}

/** The read-only plan catalogue (any member may read it). */
export async function fetchPlans(signal?: AbortSignal): Promise<PlanList> {
  const { data } = await api.get('/billing/plans', { signal })

  return parseResponse(planListSchema, data, 'GET /billing/plans')
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
