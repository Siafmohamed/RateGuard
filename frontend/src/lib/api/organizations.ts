import { api } from './client'
import { organizationListSchema, type Organization } from './schemas'
import { parseResponse } from './validate'

/** The organizations the authenticated user belongs to, each with their role. */
export async function listOrganizations(signal?: AbortSignal): Promise<Organization[]> {
  const { data } = await api.get('/organizations', { signal })

  return parseResponse(organizationListSchema, data, 'GET /organizations').organizations
}
