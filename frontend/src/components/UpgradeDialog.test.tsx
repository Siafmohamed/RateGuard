import { screen } from '@testing-library/react'
import { http, HttpResponse } from 'msw'
import { afterEach, describe, expect, it } from 'vitest'

import { UpgradeDialog } from '@/components/UpgradeDialog'
import { api, installInterceptors } from '@/lib/api/client'
import { useUiStore } from '@/stores/ui'
import { renderWithProviders } from '@/test/render'
import { server } from '@/test/server'

const CATALOGUE = {
  plans: [
    { code: 'free', name: 'Free', price_cents: 0, invoice_limit: 10 },
    { code: 'pro', name: 'Pro', price_cents: 9900, invoice_limit: 200 },
    { code: 'enterprise', name: 'Enterprise', price_cents: 49900, invoice_limit: null },
  ],
}

const DETAILS = {
  code: 'plan_limit_reached',
  message: 'You have used all 10 invoices included in the free plan this month.',
  limit: 10,
  used: 10,
  plan: 'free',
}

function servePlans() {
  server.use(http.get('/api/v1/billing/plans', () => HttpResponse.json(CATALOGUE)))
}

afterEach(() => {
  useUiStore.setState({ upgradeOpen: false, upgradeDetails: null })
})

describe('UpgradeDialog', () => {
  it('lists the plan catalogue from the API and disables the payment action', async () => {
    servePlans()
    useUiStore.getState().openUpgrade(DETAILS)

    renderWithProviders(<UpgradeDialog />)

    expect(screen.getByRole('dialog')).toBeInTheDocument()
    expect(screen.getByText('Plan limit reached')).toBeInTheDocument()

    // Plans come from GET /billing/plans, including the unlimited one.
    expect(await screen.findByText('Pro')).toBeInTheDocument()
    expect(screen.getByText('Enterprise')).toBeInTheDocument()
    expect(screen.getByText(/Unlimited invoices/)).toBeInTheDocument()

    // No payment provider yet: the CTA is advertised but inert.
    expect(screen.getByRole('button', { name: 'Payments coming soon' })).toBeDisabled()
  })

  it('opens by itself when the API answers 402 plan_limit_reached (nested body)', async () => {
    servePlans()

    installInterceptors({
      onUnauthorized: () => undefined,
      onPlanLimit: (details) => useUiStore.getState().openUpgrade(details),
      onRateLimit: () => undefined,
      onForbiddenOrg: () => undefined,
    })

    server.use(
      http.post('/api/v1/invoices/upload', () =>
        HttpResponse.json({ error: DETAILS }, { status: 402 }),
      ),
    )

    renderWithProviders(<UpgradeDialog />)

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()

    await expect(api.post('/invoices/upload', new FormData())).rejects.toBeTruthy()

    // The interceptor unwrapped the nested body, so the dialog can show it.
    expect(await screen.findByRole('dialog')).toBeInTheDocument()
    expect(screen.getByText(/Limit: 10/)).toBeInTheDocument()
    expect(await screen.findByText('Pro')).toBeInTheDocument()
  })
})
