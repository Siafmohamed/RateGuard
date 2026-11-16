import { useState } from 'react'

import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'

import { InvoiceStatusBadge } from '@/components/InvoiceStatusBadge'
import { Pagination } from '@/components/Pagination'
import { UploadInvoiceDialog } from '@/components/UploadInvoiceDialog'
import { Button } from '@/components/ui/Button'
import { EmptyState } from '@/components/ui/EmptyState'
import { SkeletonRows } from '@/components/ui/Skeleton'
import { useDocumentVisible } from '@/hooks/useDocumentVisible'
import { listInvoices } from '@/lib/api/invoices'
import { hasInvoiceInFlight } from '@/lib/api/schemas'
import { formatCents } from '@/lib/money'
import { queryKeys } from '@/lib/queryKeys'

/** The pipeline moves in seconds, so a 2s beat is responsive without hammering. */
const POLL_INTERVAL_MS = 2000

/**
 * The invoice list. It polls every 2s only while at least one invoice is still
 * moving through the pipeline: a page that is entirely `audited`/`failed` never
 * schedules another request, and a hidden tab pauses polling entirely.
 */
export function InvoicesListPage() {
  const [page, setPage] = useState(1)
  const [uploadOpen, setUploadOpen] = useState(false)
  const visible = useDocumentVisible()

  const query = useQuery({
    queryKey: queryKeys.invoices(page),
    queryFn: ({ signal }) => listInvoices(page, signal),
    refetchInterval: (query) => {
      const data = query.state.data

      if (!visible || !data) {
        return false
      }

      return hasInvoiceInFlight(data.data) ? POLL_INTERVAL_MS : false
    },
  })

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-900">Invoices</h1>
          <p className="text-sm text-slate-500">
            Upload carrier invoices and track their audit progress.
          </p>
        </div>
        <Button onClick={() => setUploadOpen(true)}>Upload invoice</Button>
      </div>

      {query.isPending ? <SkeletonRows rows={6} /> : null}

      {query.isError ? (
        <EmptyState
          title="We could not load your invoices"
          description={query.error instanceof Error ? query.error.message : undefined}
          action={
            <Button variant="secondary" onClick={() => void query.refetch()}>
              Try again
            </Button>
          }
        />
      ) : null}

      {query.data && query.data.data.length === 0 ? (
        <EmptyState
          title="No invoices yet"
          description="Upload a CSV or TXT invoice from a carrier to start an audit."
          action={<Button onClick={() => setUploadOpen(true)}>Upload invoice</Button>}
        />
      ) : null}

      {query.data && query.data.data.length > 0 ? (
        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
          <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-slate-200 text-sm">
              <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                  <th scope="col" className="px-4 py-2 font-medium">
                    Invoice
                  </th>
                  <th scope="col" className="px-4 py-2 font-medium">
                    Carrier
                  </th>
                  <th scope="col" className="px-4 py-2 font-medium">
                    Date
                  </th>
                  <th scope="col" className="px-4 py-2 text-right font-medium">
                    Total
                  </th>
                  <th scope="col" className="px-4 py-2 font-medium">
                    Status
                  </th>
                  <th scope="col" className="px-4 py-2 text-right font-medium">
                    Findings
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {query.data.data.map((invoice) => (
                  <tr key={invoice.id} data-testid="invoice-row" className="hover:bg-slate-50">
                    <td className="px-4 py-2">
                      <Link
                        to={`/app/invoices/${invoice.id}`}
                        className="font-medium text-indigo-600 hover:text-indigo-500"
                      >
                        {invoice.invoice_number}
                      </Link>
                    </td>
                    <td className="px-4 py-2 text-slate-600">
                      {invoice.carrier ? `${invoice.carrier.code} — ${invoice.carrier.name}` : '—'}
                    </td>
                    <td className="px-4 py-2 text-slate-600">{invoice.invoice_date ?? '—'}</td>
                    <td className="px-4 py-2 text-right tabular-nums">
                      {formatCents(invoice.total_cents, { currency: invoice.currency })}
                    </td>
                    <td className="px-4 py-2">
                      <InvoiceStatusBadge status={invoice.status} />
                    </td>
                    <td className="px-4 py-2 text-right tabular-nums text-slate-600">
                      {invoice.findings_count ?? '—'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <Pagination
            page={query.data.meta.current_page}
            lastPage={query.data.meta.last_page}
            onPageChange={setPage}
          />
        </div>
      ) : null}

      <UploadInvoiceDialog open={uploadOpen} onClose={() => setUploadOpen(false)} />
    </div>
  )
}
