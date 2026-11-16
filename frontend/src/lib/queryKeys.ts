/**
 * Centralised React Query keys. Grouping them here keeps invalidation honest:
 * `queryKeys.invoice(id)` is a prefix of `queryKeys.findings(id, page)`, so one
 * invalidate refreshes both.
 */
export const queryKeys = {
  session: ['session'] as const,
  organizations: ['organizations'] as const,
  carriers: ['carriers'] as const,
  invoices: (page: number) => ['invoices', page] as const,
  invoice: (id: number) => ['invoice', id] as const,
  findings: (id: number, page: number) => ['invoice', id, 'findings', page] as const,
  billing: ['billing'] as const,
  posts: (page: number, category?: string) => ['content', 'posts', page, category ?? null] as const,
  post: (slug: string) => ['content', 'post', slug] as const,
  page: (slug: string) => ['content', 'page', slug] as const,
} as const
