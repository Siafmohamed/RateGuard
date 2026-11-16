import { act, screen, waitFor } from '@testing-library/react'
import { http, HttpResponse } from 'msw'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { InvoicesListPage } from '@/pages/InvoicesListPage'
import { renderWithProviders } from '@/test/render'
import { server } from '@/test/server'

function invoice(status: string) {
  return {
    id: 1,
    organization_id: 1,
    carrier_id: 2,
    carrier: { id: 2, code: 'UPS', name: 'United Parcel Service' },
    contract_id: null,
    invoice_number: 'INV-1',
    invoice_date: '2026-01-05',
    currency: 'USD',
    total_cents: 123456,
    status,
    error_message: null,
    audited_at: null,
    findings_count: 0,
    recoverable_total_cents: null,
    created_at: null,
    updated_at: null,
  }
}

const META = { current_page: 1, last_page: 1, per_page: 15, total: 1, from: 1, to: 1 }

function serve(status: string) {
  let requests = 0

  server.use(
    http.get('/api/v1/invoices', () => {
      requests += 1

      return HttpResponse.json({ data: [invoice(status)], meta: META })
    }),
  )

  return { count: () => requests }
}

async function flush(ms: number) {
  await act(async () => {
    await vi.advanceTimersByTimeAsync(ms)
  })
}

/**
 * Fake timers with `shouldAdvanceTime` so the faked clock still ticks in real
 * time: testing-library's `waitFor` polls with `setTimeout`, which would never
 * fire under a frozen clock.
 */
function useAdvancingFakeTimers() {
  vi.useFakeTimers({ shouldAdvanceTime: true })
}

afterEach(() => {
  vi.useRealTimers()
})

describe('InvoicesListPage polling', () => {
  it('stops polling once the invoice is audited', async () => {
    const requests = serve('audited')

    useAdvancingFakeTimers()
    renderWithProviders(<InvoicesListPage />)

    await waitFor(() => expect(screen.getByText('INV-1')).toBeInTheDocument())

    await flush(6000)

    expect(requests.count()).toBe(1)
  })

  it('stops polling once the invoice has failed', async () => {
    const requests = serve('failed')

    useAdvancingFakeTimers()
    renderWithProviders(<InvoicesListPage />)

    await waitFor(() => expect(screen.getByText('INV-1')).toBeInTheDocument())

    await flush(6000)

    expect(requests.count()).toBe(1)
  })

  it('keeps polling while the invoice is still in the pipeline', async () => {
    const requests = serve('auditing')

    useAdvancingFakeTimers()
    renderWithProviders(<InvoicesListPage />)

    await waitFor(() => expect(screen.getByText('INV-1')).toBeInTheDocument())

    await flush(2500)

    expect(requests.count()).toBeGreaterThanOrEqual(2)
  })

  it('pauses polling while the tab is hidden', async () => {
    const requests = serve('auditing')

    Object.defineProperty(document, 'hidden', { configurable: true, get: () => true })

    useAdvancingFakeTimers()
    renderWithProviders(<InvoicesListPage />)

    try {
      await waitFor(() => expect(screen.getByText('INV-1')).toBeInTheDocument())

      await act(async () => {
        document.dispatchEvent(new Event('visibilitychange'))
      })

      await flush(6000)

      expect(requests.count()).toBe(1)
    } finally {
      delete (document as { hidden?: boolean }).hidden
      document.dispatchEvent(new Event('visibilitychange'))
    }
  })
})
