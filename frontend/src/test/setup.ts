import '@testing-library/jest-dom/vitest'

import { cleanup } from '@testing-library/react'
import { afterAll, afterEach, beforeAll, beforeEach } from 'vitest'

import { resetApiState, setActiveOrganizationId } from '@/lib/api/client'
import { useActiveOrganizationStore } from '@/stores/activeOrganization'
import { useUiStore } from '@/stores/ui'

import { server } from './server'

beforeAll(() => {
  server.listen({ onUnhandledFrame: 'error' })
})

beforeEach(() => {
  // Module-level interceptor state is shared across tests in a file; reset it so
  // every test starts from a clean single-flight/org slate.
  resetApiState()
  setActiveOrganizationId(null)
  useActiveOrganizationStore.setState({ organizationId: null })
  useUiStore.setState({ toasts: [], upgradeOpen: false, upgradeDetails: null })
})

afterEach(() => {
  server.resetHandlers()
  cleanup()
  window.localStorage.clear()
  window.sessionStorage.clear()
})

afterAll(() => {
  server.close()
})
