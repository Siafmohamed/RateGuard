import { screen } from '@testing-library/react'
import { http, HttpResponse } from 'msw'
import { describe, expect, it } from 'vitest'

import { BillingPage } from '@/pages/BillingPage'
import type { Role } from '@/lib/api/schemas'
import { useActiveOrganizationStore } from '@/stores/activeOrganization'
import { renderWithProviders } from '@/test/render'
import { server } from '@/test/server'

const BILLING = {
  plan: { code: 'basic', name: 'Basic', price_cents: 4900, invoice_limit: 50 },
  status: 'active',
  renews_at: '2026-12-01',
  ends_at: null,
  invoices_used: 10,
  plans: [
    { code: 'basic', name: 'Basic', price_cents: 4900, invoice_limit: 50 },
    { code: 'pro', name: 'Pro', price_cents: 9900, invoice_limit: null },
  ],
}

function arrange(role: Role) {
  server.use(
    http.get('/api/v1/organizations', () =>
      HttpResponse.json({
        organizations: [{ id: 1, name: 'Acme Freight', slug: 'acme', role }],
      }),
    ),
    http.get('/api/v1/billing', () => HttpResponse.json(BILLING)),
  )

  // The switcher normally sets this; the store is the source of truth here.
  useActiveOrganizationStore.setState({ organizationId: 1 })

  return renderWithProviders(<BillingPage />)
}

describe('BillingPage', () => {
  it('hides the owner-only subscription controls from a member', async () => {
    arrange('member')

    expect(await screen.findByRole('button', { name: 'Current plan' })).toBeInTheDocument()

    expect(screen.queryByRole('button', { name: 'Manage payment method' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Cancel subscription' })).not.toBeInTheDocument()
    expect(screen.getByText(/Only an owner can change the subscription/)).toBeInTheDocument()

    // The upgrade affordance stays visible but is inert for a non-owner.
    const upgrade = screen.getByRole('button', { name: 'Payments coming soon' })

    expect(upgrade).toBeDisabled()
  })

  it('shows the owner-only subscription controls to an owner', async () => {
    arrange('owner')

    expect(
      await screen.findByRole('button', { name: 'Manage payment method' }),
    ).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Cancel subscription' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Payments coming soon' })).toBeDisabled()
  })

  it('advertises that payments are unavailable and never calls checkout', async () => {
    let checkoutCalls = 0

    // If the UI ever fired the checkout request, this handler would record it.
    server.use(
      http.post('/api/v1/billing/checkout', () => {
        checkoutCalls += 1

        return HttpResponse.json(
          {
            error: {
              code: 'payments_not_available',
              message: 'Online payments are not available yet.',
            },
          },
          { status: 501 },
        )
      }),
    )

    arrange('owner')

    const upgrade = await screen.findByRole('button', { name: 'Payments coming soon' })

    expect(upgrade).toBeDisabled()
    // Nothing was requested, so there is no 501 in the console to explain.
    expect(checkoutCalls).toBe(0)
  })

  it('renders the usage bar from the integer counts', async () => {
    arrange('owner')

    // 10 of 50 -> 20%.
    const bar = await screen.findByRole('progressbar', { name: 'Plan usage' })

    expect(bar).toHaveAttribute('aria-valuenow', '20')
  })
})
