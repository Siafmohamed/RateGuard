import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import { FindingsTable } from '@/components/FindingsTable'
import type { Finding } from '@/lib/api/schemas'

const FINDING: Finding = {
  id: 41,
  invoice_id: 7,
  invoice_line_id: 88,
  tracking_number: '1Z999AA10123456784',
  rule: 'fuel_surcharge_overcharge',
  severity: 'high',
  expected_cents: 1200,
  billed_cents: 24356,
  recoverable_cents: 123456,
  status: 'open',
  details: null,
}

describe('FindingsTable', () => {
  it('renders every money column from integer cents', () => {
    render(<FindingsTable findings={[FINDING]} />)

    expect(screen.getByText('fuel_surcharge_overcharge')).toBeInTheDocument()
    expect(screen.getByText('1Z999AA10123456784')).toBeInTheDocument()
    expect(screen.getByText('$12.00')).toBeInTheDocument()
    expect(screen.getByText('$243.56')).toBeInTheDocument()
    expect(screen.getByTestId('recoverable-41')).toHaveTextContent('$1,234.56')
  })

  it('shows an em dash when a finding has no tracking number', () => {
    render(<FindingsTable findings={[{ ...FINDING, id: 42, tracking_number: null }]} />)

    expect(screen.getByText('—')).toBeInTheDocument()
  })
})
