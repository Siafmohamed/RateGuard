import { Badge, type BadgeTone } from '@/components/ui/Badge'

const STATUS_CONFIG: Record<string, { tone: BadgeTone; label: string }> = {
  uploaded: { tone: 'neutral', label: 'Uploaded' },
  parsing: { tone: 'info', label: 'Parsing' },
  normalized: { tone: 'info', label: 'Normalized' },
  auditing: { tone: 'info', label: 'Auditing' },
  audited: { tone: 'success', label: 'Audited' },
  failed: { tone: 'danger', label: 'Failed' },
}

export function InvoiceStatusBadge({ status }: { status: string }) {
  const config = STATUS_CONFIG[status] ?? { tone: 'neutral' as BadgeTone, label: status }

  return <Badge tone={config.tone}>{config.label}</Badge>
}
