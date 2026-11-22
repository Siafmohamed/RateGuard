import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { http, HttpResponse } from 'msw'
import { describe, expect, it, vi } from 'vitest'

import { UploadInvoiceDialog } from '@/components/UploadInvoiceDialog'
import {
  INVALID_INVOICE_CSV_HEADER_MESSAGE,
  MAX_UPLOAD_BYTES,
} from '@/lib/api/invoices'
import { renderWithProviders } from '@/test/render'
import { server } from '@/test/server'

function getFileInput(): HTMLInputElement {
  const input = document.getElementById('file')

  if (!(input instanceof HTMLInputElement)) {
    throw new Error('The file input is missing.')
  }

  return input
}

function renderDialog() {
  server.use(http.get('/api/v1/carriers', () => HttpResponse.json({ data: [] })))

  return renderWithProviders(<UploadInvoiceDialog open onClose={vi.fn()} />)
}

describe('UploadInvoiceDialog', () => {
  it('rejects a file whose type is not csv/txt', async () => {
    // The input advertises accept=".csv,.txt"; disable the user-event filter so
    // the component's own validation is the thing under test.
    const user = userEvent.setup({ applyAccept: false })

    renderDialog()

    await user.upload(getFileInput(), new File(['x'], 'rates.pdf', { type: 'application/pdf' }))

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Only .csv and .txt files are supported.',
    )
  })

  it('rejects a file larger than 5 MB', async () => {
    const user = userEvent.setup()

    renderDialog()

    const file = new File(['x'], 'rates.csv', { type: 'text/csv' })

    Object.defineProperty(file, 'size', { value: MAX_UPLOAD_BYTES + 1 })

    await user.upload(getFileInput(), file)

    expect(await screen.findByRole('alert')).toHaveTextContent('That file is larger than 5 MB.')
  })

  it('rejects a CSV without the invoice header', async () => {
    const user = userEvent.setup()

    renderDialog()

    await user.upload(
      getFileInput(),
      new File(
        ['index,ean,stock,price\n1,2010743556564,669,135'],
        'inventory.csv',
        { type: 'text/csv' },
      ),
    )

    expect(await screen.findByRole('alert')).toHaveTextContent(
      INVALID_INVOICE_CSV_HEADER_MESSAGE,
    )
  })

  it('accepts a small CSV with the required invoice header', async () => {
    const user = userEvent.setup()

    renderDialog()

    expect(
      screen.getByText(
        'Required columns: tracking_number, charge_type, description, billed_weight_kg, amount',
      ),
    ).toBeInTheDocument()

    await user.upload(
      getFileInput(),
      new File(
        [
          'tracking_number,charge_type,description,billed_weight_kg,amount\n' +
            '1ZTEST0001,fuel,Fuel surcharge,,1.86',
        ],
        'invoice.csv',
        { type: 'text/csv' },
      ),
    )

    expect(document.querySelector('[role="alert"]')).toBeNull()
  })
})
