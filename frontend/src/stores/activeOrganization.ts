import { create } from 'zustand'

import { setActiveOrganizationId } from '@/lib/api/client'

/*
 * The active organization id is the ONLY client state we keep in Zustand.
 *
 * It is deliberately not persisted: it is not a credential, but keeping it in
 * memory means there is nothing auth-related in localStorage/sessionStorage at
 * all. Every id change is mirrored into the API layer, which attaches the
 * `X-Organization-Id` header - the store never has to be imported by the
 * interceptors.
 */
interface ActiveOrganizationState {
  organizationId: number | null
  setOrganizationId: (id: number | null) => void
  reset: () => void
}

export const useActiveOrganizationStore = create<ActiveOrganizationState>((set) => ({
  organizationId: null,

  setOrganizationId: (id) => {
    setActiveOrganizationId(id)
    set({ organizationId: id })
  },

  reset: () => {
    setActiveOrganizationId(null)
    set({ organizationId: null })
  },
}))

export function useActiveOrganizationId(): number | null {
  return useActiveOrganizationStore((state) => state.organizationId)
}
