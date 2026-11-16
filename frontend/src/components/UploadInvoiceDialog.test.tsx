import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { http, HttpResponse } from 'msw'
import { describe, expect, it, vi } from 'vitest'

import { UploadInvoiceDialog } from '@/components/UploadInvoiceDialog'
import { MAX_UPLOAD_BYTES } from '@/lib/api/invoices'
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

  it('accepts a small csv file', async () => {
    const user = userEvent.setup()

    renderDialog()

    await user.upload(
      getFileInput(),
      new File(['carrier,total\nUPS,100'], 'rates.csv', { type: 'text/csv' }),
    )

    expect(document.querySelector('[role="alert"]')).toBeNull()
  })
})
