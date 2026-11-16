import { useQuery } from '@tanstack/react-query'

import { listOrganizations } from '@/lib/api/organizations'
import type { Organization, Role } from '@/lib/api/schemas'
import { queryKeys } from '@/lib/queryKeys'
import { useActiveOrganizationId } from '@/stores/activeOrganization'

export function useOrganizations() {
  return useQuery({
    queryKey: queryKeys.organizations,
    queryFn: ({ signal }) => listOrganizations(signal),
  })
}

export interface ActiveOrganization {
  organizations: Organization[]
  organization: Organization | null
  role: Role | null
}

/**
 * Resolve the active organization and the caller's role inside it. The role is
 * used for UI affordances only (`can(...)`); the server policies stay the
 * authority.
 */
export function useActiveOrganization(): ActiveOrganization {
  const { data } = useOrganizations()
  const activeId = useActiveOrganizationId()

  const organizations = data ?? []
  const organization = organizations.find((item) => item.id === activeId) ?? null

  return {
    organizations,
    organization,
    role: organization?.role ?? null,
  }
}
