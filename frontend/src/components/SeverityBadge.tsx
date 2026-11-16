import { Badge, type BadgeTone } from '@/components/ui/Badge'

const SEVERITY_TONES: Record<string, BadgeTone> = {
  high: 'danger',
  medium: 'warning',
  low: 'neutral',
}

export function SeverityBadge({ severity }: { severity: string }) {
  const tone = SEVERITY_TONES[severity.toLowerCase()] ?? 'neutral'

  return <Badge tone={tone}>{severity}</Badge>
}
