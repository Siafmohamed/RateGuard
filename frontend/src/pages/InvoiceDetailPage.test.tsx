import { screen, waitFor } from '@testing-library/react'
import { http, HttpResponse } from 'msw'
import { Route, Routes } from 'react-router-dom'
import { describe, expect, it } from 'vitest'

import { InvoiceDetailPage } from '@/pages/InvoiceDetailPage'
import { renderWithProviders } from '@/test/render'
import { server } from '@/test/server'

const META = { current_page: 1, last_page: 1, per_page: 15, total: 0, from: null, to: null }

/**
 * The API wraps a single invoice in a `data` envelope. Parsing the outer object
 * left every field undefined, which surfaced as a wall of Zod errors.
 */
const INVOICE = {
  id: 32,
  organization_id: 40,
  carrier_id: 28,
  carrier: { id: 28, code: 'UPS', name: 'United Parcel Service' },
  contract_id: null,
  invoice_number: '11',
  invoice_date: '2026-11-20',
  currency: 'USD',
  total_cents: 126500,
  status: 'audited',
  error_message:
    'No contract is linked to this invoice, so overcharge rules could not run. Link a contract for carrier UPS to enable contract-based auditing.',
  audited_at: '2026-11-20T16:03:55+00:00',
  findings_count: 0,
  created_at: '2026-11-20T16:03:50+00:00',
  updated_at: '2026-11-20T16:03:55+00:00',
}

function renderDetail() {
  return renderWithProviders(
    <Routes>
      <Route path="/app/invoices/:id" element={<InvoiceDetailPage />} />
    </Routes>,
    { route: '/app/invoices/32' },
  )
}

describe('InvoiceDetailPage', () => {
  it('renders a single invoice parsed from the data envelope', async () => {
    server.use(
      http.get('/api/v1/organizations', () => HttpResponse.json({ organizations: [] })),
      http.get('/api/v1/invoices/32', () => HttpResponse.json({ data: INVOICE })),
      http.get('/api/v1/invoices/32/findings', () => HttpResponse.json({ data: [], meta: META })),
    )

    renderDetail()

    await waitFor(() => expect(screen.getByRole('heading', { name: '11' })).toBeInTheDocument())

    expect(screen.getByText(/UPS — United Parcel Service/)).toBeInTheDocument()
    expect(screen.getByText('$1,265.00')).toBeInTheDocument()
    expect(screen.getByText('Audited')).toBeInTheDocument()

    // No contract is linked, so the audit explains itself rather than silently
    // reporting "0 findings".
    expect(screen.getByRole('alert')).toHaveTextContent('No contract is linked')
  })

  it('lists the invoice findings', async () => {
    const finding = {
      id: 41,
      invoice_id: 32,
      invoice_line_id: 88,
      tracking_number: '1Z999AA10123456784',
      rule: 'duplicate_charge',
      severity: 'low',
      expected_cents: 0,
      billed_cents: 186,
      recoverable_cents: 186,
      status: 'open',
      details: null,
    }

    server.use(
      http.get('/api/v1/organizations', () => HttpResponse.json({ organizations: [] })),
      http.get('/api/v1/invoices/32', () =>
        HttpResponse.json({ data: { ...INVOICE, findings_count: 1, recoverable_total_cents: 186 } }),
      ),
      http.get('/api/v1/invoices/32/findings', () =>
        HttpResponse.json({ data: [finding], meta: { ...META, total: 1, to: 1 } }),
      ),
    )

    renderDetail()

    await waitFor(() => expect(screen.getByText('duplicate_charge')).toBeInTheDocument())

    expect(screen.getByText('1Z999AA10123456784')).toBeInTheDocument()
    expect(screen.getByTestId('recoverable-41')).toHaveTextContent('$1.86')
  })
})
