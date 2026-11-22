import { useState } from 'react'

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'

import { FindingsTable } from '@/components/FindingsTable'
import { InvoiceStatusBadge } from '@/components/InvoiceStatusBadge'
import { Pagination } from '@/components/Pagination'
import { Button } from '@/components/ui/Button'
import { EmptyState } from '@/components/ui/EmptyState'
import { SkeletonRows } from '@/components/ui/Skeleton'
import { useActiveOrganization } from '@/hooks/useActiveOrganization'
import { useDocumentVisible } from '@/hooks/useDocumentVisible'
import { isApiError } from '@/lib/api/errors'
import { auditInvoice, getInvoice, listFindings } from '@/lib/api/invoices'
import { isTerminalInvoiceStatus } from '@/lib/api/schemas'
import { formatCents } from '@/lib/money'
import { queryKeys } from '@/lib/queryKeys'
import { can } from '@/lib/roles'
import { useUiStore } from '@/stores/ui'

const POLL_INTERVAL_MS = 2000

/**
 * A single invoice: live status, the recoverable total, and its findings.
 *
 * Polling stops the moment the invoice reaches a terminal state (`audited` or
 * `failed`) or the tab is hidden - a 409 from a re-audit is reported as-is
 * rather than being retried.
 */
export function InvoiceDetailPage() {
  const params = useParams<{ id: string }>()
  const invoiceId = Number(params.id)
  const validId = Number.isFinite(invoiceId) && invoiceId > 0

  const visible = useDocumentVisible()
  const { role } = useActiveOrganization()
  const queryClient = useQueryClient()
  const pushToast = useUiStore((state) => state.pushToast)

  const [findingsPage, setFindingsPage] = useState(1)

  const invoiceQuery = useQuery({
    queryKey: queryKeys.invoice(invoiceId),
    queryFn: ({ signal }) => getInvoice(invoiceId, signal),
    enabled: validId,
    refetchInterval: (query) => {
      const invoice = query.state.data

      if (!visible || !invoice) {
        return false
      }

      return isTerminalInvoiceStatus(invoice.status) ? false : POLL_INTERVAL_MS
    },
  })

  const findingsQuery = useQuery({
    queryKey: queryKeys.findings(invoiceId, findingsPage),
    queryFn: ({ signal }) => listFindings(invoiceId, findingsPage, signal),
    enabled: validId,
  })

  const auditMutation = useMutation({
    mutationFn: () => auditInvoice(invoiceId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.invoice(invoiceId) })
      void queryClient.invalidateQueries({ queryKey: ['invoices'] })
    },
    onError: (error) => {
      if (isApiError(error) && error.status === 409) {
        pushToast(error.message, 'info')

        return
      }

      pushToast(error instanceof Error ? error.message : 'The audit could not be started.')
    },
  })

  if (!validId) {
    return (
      <EmptyState
        title="Invoice not found"
        description="That invoice id is not valid."
        action={
          <Link to="/app/invoices" className="text-sm font-medium text-indigo-600">
            Back to invoices
          </Link>
        }
      />
    )
  }

  const invoice = invoiceQuery.data
  const recoverable = invoice?.recoverable_total_cents

  return (
    <div className="space-y-6">
      <Link to="/app/invoices" className="text-sm text-slate-500 hover:text-slate-800">
        ← Invoices
      </Link>

      {invoiceQuery.isPending ? <SkeletonRows rows={4} /> : null}

      {invoiceQuery.isError ? (
        <EmptyState
          title="We could not load this invoice"
          description={
            invoiceQuery.error instanceof Error ? invoiceQuery.error.message : undefined
          }
          action={
            <Button variant="secondary" onClick={() => void invoiceQuery.refetch()}>
              Try again
            </Button>
          }
        />
      ) : null}

      {invoice ? (
        <>
          <div className="flex flex-wrap items-start justify-between gap-4">
            <div>
              <div className="flex items-center gap-3">
                <h1 className="text-xl font-semibold text-slate-900">
                  {invoice.invoice_number}
                </h1>
                <InvoiceStatusBadge status={invoice.status} />
              </div>
              <p className="mt-1 text-sm text-slate-500">
                {invoice.carrier ? `${invoice.carrier.code} — ${invoice.carrier.name}` : '—'}
                {invoice.invoice_date ? ` · ${invoice.invoice_date}` : ''}
              </p>
            </div>

            {can(role, 'reaudit') ? (
              <Button
                variant="secondary"
                loading={auditMutation.isPending}
                onClick={() => auditMutation.mutate()}
              >
                Re-run audit
              </Button>
            ) : null}
          </div>

          {invoice.error_message ? (
            <p
              role="alert"
              className={
                invoice.status === 'failed'
                  ? 'rounded-md bg-red-50 px-4 py-3 text-sm text-red-700'
                  : 'rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-800'
              }
            >
              {invoice.error_message}
            </p>
          ) : null}

          <div className="grid gap-4 sm:grid-cols-3">
            <div className="rounded-lg border border-slate-200 bg-white p-4">
              <p className="text-xs uppercase tracking-wide text-slate-500">Invoice total</p>
              <p className="mt-1 text-lg font-semibold tabular-nums text-slate-900">
                {formatCents(invoice.total_cents, { currency: invoice.currency })}
              </p>
            </div>

            <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-4">
              <p className="text-xs uppercase tracking-wide text-emerald-700">Recoverable</p>
              <p
                data-testid="recoverable-total"
                className="mt-1 text-lg font-semibold tabular-nums text-emerald-800"
              >
                {recoverable === null || recoverable === undefined
                  ? '—'
                  : formatCents(recoverable, { currency: invoice.currency })}
              </p>
            </div>

            <div className="rounded-lg border border-slate-200 bg-white p-4">
              <p className="text-xs uppercase tracking-wide text-slate-500">Findings</p>
              <p className="mt-1 text-lg font-semibold tabular-nums text-slate-900">
                {invoice.findings_count ?? '—'}
              </p>
            </div>
          </div>

          <section className="space-y-3">
            <h2 className="text-base font-semibold text-slate-900">Findings</h2>

            {findingsQuery.isPending ? <SkeletonRows rows={4} /> : null}

            {findingsQuery.isError ? (
              <EmptyState
                title="We could not load the findings"
                description={
                  findingsQuery.error instanceof Error ? findingsQuery.error.message : undefined
                }
              />
            ) : null}

            {findingsQuery.data && findingsQuery.data.data.length === 0 ? (
              <EmptyState
                title="No findings"
                description="No overcharges were detected on this invoice."
              />
            ) : null}

            {findingsQuery.data && findingsQuery.data.data.length > 0 ? (
              <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
                <FindingsTable findings={findingsQuery.data.data} />
                <Pagination
                  page={findingsQuery.data.meta.current_page}
                  lastPage={findingsQuery.data.meta.last_page}
                  onPageChange={setFindingsPage}
                />
              </div>
            ) : null}
          </section>
        </>
      ) : null}
    </div>
  )
}
