import { api } from './client'
import { carrierListSchema, type Carrier } from './schemas'
import { parseResponse } from './validate'

/**
 * Carriers are global reference data (no tenant scoping), so this returns the
 * same list for every organization. Auth is still required.
 */
export async function listCarriers(signal?: AbortSignal): Promise<Carrier[]> {
  const { data } = await api.get('/carriers', { signal })

  return parseResponse(carrierListSchema, data, 'GET /carriers').data
}
