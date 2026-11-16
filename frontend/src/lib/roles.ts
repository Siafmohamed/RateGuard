import type { Role } from '@/lib/api/schemas'

const LEVEL: Record<Role, number> = { owner: 3, admin: 2, member: 1 }

export type Permission = 'upload' | 'reaudit' | 'billing'

/** Minimum role required for each capability, mirroring the server policies. */
const REQUIRED_LEVEL: Record<Permission, number> = {
  upload: LEVEL.member,
  reaudit: LEVEL.admin,
  billing: LEVEL.owner,
}

/**
 * UI convenience only - it decides what to render or enable, never whether a
 * request is allowed. The server policies remain the authority, and this must
 * never be treated as an access-control boundary.
 */
export function can(role: Role | null | undefined, permission: Permission): boolean {
  if (!role) {
    return false
  }

  return LEVEL[role] >= REQUIRED_LEVEL[permission]
}
