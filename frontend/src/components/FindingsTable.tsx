import { SeverityBadge } from '@/components/SeverityBadge'
import type { Finding } from '@/lib/api/schemas'
import { formatCents } from '@/lib/money'

export interface FindingsTableProps {
  findings: Finding[]
}

const HEADINGS = [
  'Rule',
  'Severity',
  'Tracking number',
  'Expected',
  'Billed',
  'Recoverable',
] as const

/** Findings table; every money column renders integer cents through formatCents. */
export function FindingsTable({ findings }: FindingsTableProps) {
  return (
    <div className="overflow-x-auto">
      <table className="min-w-full divide-y divide-slate-200 text-sm">
        <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
          <tr>
            {HEADINGS.map((heading) => (
              <th key={heading} scope="col" className="px-4 py-2 font-medium">
                {heading}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {findings.map((finding) => (
            <tr key={finding.id} className="hover:bg-slate-50">
              <td className="px-4 py-2 font-medium text-slate-800">{finding.rule}</td>
              <td className="px-4 py-2">
                <SeverityBadge severity={finding.severity} />
              </td>
              <td className="px-4 py-2 text-slate-600">{finding.tracking_number ?? '—'}</td>
              <td className="px-4 py-2 text-right tabular-nums">
                {formatCents(finding.expected_cents)}
              </td>
              <td className="px-4 py-2 text-right tabular-nums">
                {formatCents(finding.billed_cents)}
              </td>
              <td
                data-testid={`recoverable-${finding.id}`}
                className="px-4 py-2 text-right font-medium tabular-nums text-emerald-700"
              >
                {formatCents(finding.recoverable_cents)}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
