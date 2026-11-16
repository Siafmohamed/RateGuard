import type { Organization } from '@/lib/api/schemas'

export interface OrgSwitcherProps {
  organizations: Organization[]
  value: number | null
  onChange: (id: number) => void
}

/**
 * Switches the active organization. The chosen id lives in the Zustand store and
 * drives the `X-Organization-Id` header on every subsequent request; the role
 * shown next to it is derived from this same payload.
 */
export function OrgSwitcher({ organizations, value, onChange }: OrgSwitcherProps) {
  if (organizations.length === 0) {
    return null
  }

  return (
    <div className="flex items-center gap-2">
      <select
        aria-label="Active organization"
        value={value ?? ''}
        onChange={(event) => onChange(Number(event.target.value))}
        className="rounded-md border border-slate-300 bg-white px-2 py-1.5 text-sm text-slate-800"
      >
        {organizations.map((organization) => (
          <option key={organization.id} value={organization.id}>
            {organization.name}
          </option>
        ))}
      </select>
      {value !== null ? (
        <span className="text-xs uppercase tracking-wide text-slate-400">
          {organizations.find((organization) => organization.id === value)?.role ?? ''}
        </span>
      ) : null}
    </div>
  )
}
