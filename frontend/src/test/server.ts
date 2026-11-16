import { setupServer } from 'msw/node'

/**
 * MSW server shared by the whole suite. No test may hit the real network: the
 * default is to fail on any request that has no handler, and individual tests
 * layer their own handlers with `server.use(...)`.
 */
export const server = setupServer()
