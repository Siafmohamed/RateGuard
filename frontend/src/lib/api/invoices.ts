import { z } from 'zod'

import { api } from './client'
import {
  findingPageSchema,
  invoicePageSchema,
  invoiceSchema,
  type FindingPage,
  type Invoice,
  type InvoicePage,
} from './schemas'
import { parseResponse } from './validate'

export const uploadInvoiceInputSchema = z.object({
  carrier: z.string().min(1, 'Choose a carrier.'),
  invoice_number: z.string().min(1, 'Invoice number is required.').max(255),
  invoice_date: z.string().min(1, 'Invoice date is required.'),
})
export type UploadInvoiceInput = z.infer<typeof uploadInvoiceInputSchema>

/** Mirror of the server rule: csv/txt only. */
export const ACCEPTED_UPLOAD_TYPES = '.csv,.txt'
/** Mirror of the server rule: `max:5120` kilobytes. */
export const MAX_UPLOAD_BYTES = 5 * 1024 * 1024

const ACCEPTED_MIME_TYPES = new Set([
  'text/csv',
  'text/plain',
  'application/csv',
  'application/vnd.ms-excel',
  'application/octet-stream',
  '',
])

/**
 * Client-side pre-check that mirrors the server rules, so the common mistakes
 * are caught before a 5 MB upload is attempted. The server remains the
 * authority; a 413/422 is still handled in the UI.
 */
export function validateInvoiceFile(file: File): string | null {
  if (file.size > MAX_UPLOAD_BYTES) {
    return 'That file is larger than 5 MB.'
  }

  const extension = file.name.toLowerCase().split('.').pop() ?? ''
  const extensionOk = extension === 'csv' || extension === 'txt'
  const typeOk = ACCEPTED_MIME_TYPES.has(file.type)

  if (!extensionOk && !typeOk) {
    return 'Only .csv and .txt files are supported.'
  }

  return null
}

export async function listInvoices(page = 1, signal?: AbortSignal): Promise<InvoicePage> {
  const { data } = await api.get('/invoices', { params: { page }, signal })

  return parseResponse(invoicePageSchema, data, 'GET /invoices')
}

export async function getInvoice(id: number, signal?: AbortSignal): Promise<Invoice> {
  const { data } = await api.get(`/invoices/${id}`, { signal })

  return parseResponse(invoiceSchema, data, 'GET /invoices/{invoice}')
}

export async function listFindings(
  id: number,
  page = 1,
  signal?: AbortSignal,
): Promise<FindingPage> {
  const { data } = await api.get(`/invoices/${id}/findings`, { params: { page }, signal })

  return parseResponse(findingPageSchema, data, 'GET /invoices/{invoice}/findings')
}

/** Re-runs the normalize -> audit pipeline. A 409 means one is already running. */
export async function auditInvoice(id: number): Promise<Invoice> {
  const { data } = await api.post(`/invoices/${id}/audit`)

  return parseResponse(invoiceSchema, data, 'POST /invoices/{invoice}/audit')
}

/**
 * Upload an invoice. The multipart body uses the exact field names the server
 * validates: `file`, `carrier` (its code), `invoice_number`, `invoice_date`.
 * The endpoint answers 202 with the freshly created invoice.
 */
export async function uploadInvoice(
  input: UploadInvoiceInput & { file: File },
  options: { onProgress?: (percent: number) => void; signal?: AbortSignal } = {},
): Promise<Invoice> {
  const form = new FormData()
  form.append('carrier', input.carrier)
  form.append('invoice_number', input.invoice_number)
  form.append('invoice_date', input.invoice_date)
  form.append('file', input.file)

  const { data } = await api.post('/invoices/upload', form, {
    signal: options.signal,
    onUploadProgress: (event) => {
      if (!options.onProgress || !event.total) {
        return
      }

      options.onProgress(Math.round((event.loaded / event.total) * 100))
    },
  })

  return parseResponse(invoiceSchema, data, 'POST /invoices/upload')
}
