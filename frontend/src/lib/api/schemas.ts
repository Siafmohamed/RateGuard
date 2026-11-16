import { z } from 'zod'

/*
 * Zod schemas for every API payload the SPA consumes. They are the single
 * source of truth for the wire contract: the typed API functions in
 * src/lib/api/*.ts parse responses through them, so a backend drift surfaces as
 * a loud error in dev/test instead of an undefined deep in a component.
 */

/* --------------------------------------------------------------------- auth */

/** A pivot role can be missing on odd rows; fall back to the least privileged. */
export const roleSchema = z.enum(['owner', 'admin', 'member']).catch('member')
export type Role = z.infer<typeof roleSchema>

export const userSchema = z.object({
  id: z.number(),
  name: z.string(),
  email: z.string(),
})
export type User = z.infer<typeof userSchema>

export const authSessionSchema = z.object({ user: userSchema })
export type AuthSession = z.infer<typeof authSessionSchema>

/* -------------------------------------------------------------- organization */

export const organizationSchema = z.object({
  id: z.number(),
  name: z.string(),
  slug: z.string(),
  role: roleSchema,
})
export type Organization = z.infer<typeof organizationSchema>

export const organizationListSchema = z.object({
  organizations: z.array(organizationSchema),
})
export type OrganizationList = z.infer<typeof organizationListSchema>

/* ------------------------------------------------------------------ carriers */

export const carrierSchema = z.object({
  id: z.number(),
  code: z.string(),
  name: z.string(),
})
export type Carrier = z.infer<typeof carrierSchema>

export const carrierListSchema = z.object({ data: z.array(carrierSchema) })
export type CarrierList = z.infer<typeof carrierListSchema>

/* ------------------------------------------------------------ pagination */

export const paginationMetaSchema = z.object({
  current_page: z.number(),
  last_page: z.number(),
  per_page: z.number(),
  total: z.number(),
  from: z.number().nullable(),
  to: z.number().nullable(),
})
export type PaginationMeta = z.infer<typeof paginationMetaSchema>

/* ----------------------------------------------------------------- invoices */

export const invoiceStatusSchema = z.enum([
  'uploaded',
  'parsing',
  'normalized',
  'auditing',
  'audited',
  'failed',
])
export type KnownInvoiceStatus = z.infer<typeof invoiceStatusSchema>

/**
 * The column is a plain string on the server (legacy demo rows use "imported"),
 * so the wire value is validated leniently. Anything that is not a terminal
 * state is treated as still in flight, which is what drives polling.
 */
export const invoiceSchema = z.object({
  id: z.number(),
  organization_id: z.number(),
  carrier_id: z.number().nullable(),
  carrier: carrierSchema.nullish(),
  contract_id: z.number().nullable(),
  invoice_number: z.string(),
  invoice_date: z.string().nullable(),
  currency: z.string(),
  total_cents: z.number(),
  status: z.string(),
  error_message: z.string().nullable(),
  audited_at: z.string().nullable(),
  findings_count: z.number().optional(),
  recoverable_total_cents: z.number().nullish(),
  created_at: z.string().nullable(),
  updated_at: z.string().nullable(),
})
export type Invoice = z.infer<typeof invoiceSchema>

export const invoicePageSchema = z.object({
  data: z.array(invoiceSchema),
  meta: paginationMetaSchema,
})
export type InvoicePage = z.infer<typeof invoicePageSchema>

const TERMINAL_STATUSES = new Set(['audited', 'failed'])

/** `audited` / `failed` are the only states the pipeline will not leave again. */
export function isTerminalInvoiceStatus(status: string): boolean {
  return TERMINAL_STATUSES.has(status)
}

/** True while at least one invoice is still moving through the pipeline. */
export function hasInvoiceInFlight(invoices: readonly Invoice[]): boolean {
  return invoices.some((invoice) => !isTerminalInvoiceStatus(invoice.status))
}

/* ----------------------------------------------------------------- findings */

export const findingSchema = z.object({
  id: z.number(),
  invoice_id: z.number(),
  invoice_line_id: z.number().nullable(),
  tracking_number: z.string().nullish(),
  rule: z.string(),
  severity: z.string(),
  expected_cents: z.number(),
  billed_cents: z.number(),
  recoverable_cents: z.number(),
  status: z.string(),
  details: z.unknown(),
})
export type Finding = z.infer<typeof findingSchema>

export const findingPageSchema = z.object({
  data: z.array(findingSchema),
  meta: paginationMetaSchema,
})
export type FindingPage = z.infer<typeof findingPageSchema>

/* ------------------------------------------------------------------ content */

export const categorySchema = z.object({
  id: z.number(),
  slug: z.string(),
  name: z.string(),
  description: z.string().nullish(),
  count: z.number().optional(),
})
export type Category = z.infer<typeof categorySchema>

export const seoSchema = z.object({
  title: z.string(),
  description: z.string(),
})

export const contentPostSchema = z.object({
  id: z.number(),
  slug: z.string(),
  title: z.string(),
  excerpt: z.string(),
  content_html: z.string(),
  published_at: z.string().nullable(),
  modified_at: z.string().nullable(),
  featured_image_url: z.string().nullable(),
  author: z.string().nullable(),
  categories: z.array(categorySchema),
  seo: seoSchema.nullable(),
})
export type ContentPost = z.infer<typeof contentPostSchema>

export const contentPageSchema = contentPostSchema.omit({ categories: true })
export type ContentPage = z.infer<typeof contentPageSchema>

/** The list endpoint omits `content_html` to keep the payload small. */
export const postPreviewSchema = contentPostSchema.omit({ content_html: true })
export type PostPreview = z.infer<typeof postPreviewSchema>

export const postListSchema = z.object({
  data: z.array(postPreviewSchema),
  meta: z.object({
    total: z.number(),
    total_pages: z.number(),
    page: z.number(),
    per_page: z.number(),
  }),
})
export type PostList = z.infer<typeof postListSchema>

export const contentPostEnvelopeSchema = z.object({ data: contentPostSchema })
export const contentPageEnvelopeSchema = z.object({ data: contentPageSchema })

/* ------------------------------------------------------------------ billing */

/*
 * NOTE: there is no billing backend yet (api/app/Domain/Billing is empty).
 * These schemas document the contract the UI is built against; the API
 * functions that use them will start working once the backend exists.
 */

export const planSchema = z.object({
  code: z.string(),
  name: z.string(),
  price_cents: z.number(),
  invoice_limit: z.number().nullable(),
})
export type Plan = z.infer<typeof planSchema>

export const billingOverviewSchema = z.object({
  plan: planSchema.nullable(),
  status: z.string().nullable(),
  renews_at: z.string().nullable(),
  ends_at: z.string().nullable(),
  invoices_used: z.number(),
  plans: z.array(planSchema),
})
export type BillingOverview = z.infer<typeof billingOverviewSchema>

export const checkoutSchema = z.object({ url: z.string() })

/** Payload carried by the 402 `plan_limit_reached` response. */
export const planLimitDetailsSchema = z.object({
  code: z.literal('plan_limit_reached'),
  message: z.string(),
  limit: z.number().optional(),
  used: z.number().optional(),
  plan: z.string().optional(),
})
export type PlanLimitDetails = z.infer<typeof planLimitDetailsSchema>
