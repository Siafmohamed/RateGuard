import { z } from 'zod'

/**
 * Read-only access to the WordPress REST API, straight from the browser.
 *
 * WordPress runs on Plain permalinks here, so `/wp-json/...` 404s at Apache;
 * every call therefore goes through `/index.php?rest_route=<route>`, which is
 * what WordPress itself supports without rewriting.
 *
 * In development the browser calls `/wp-api` and the Vite dev server proxies it
 * to WordPress (same origin, so no CORS). In production set `VITE_WP_BASE_URL`
 * to the WordPress origin and it is called directly - which does need CORS on
 * the WordPress side.
 */
const WP_BASE_URL = import.meta.env.VITE_WP_BASE_URL ?? '/wp-api'

const PER_PAGE = 20

/** WordPress wraps most scalar fields as `{ rendered: '<html>' }`. */
const renderedSchema = z.object({ rendered: z.string() })

const featuredMediaSchema = z.object({ source_url: z.string().optional() })

export const articleSchema = z.object({
  id: z.number(),
  date: z.string(),
  title: renderedSchema,
  // Both are legitimately empty for a draft-quality post, so no `.min(1)`.
  excerpt: renderedSchema,
  content: renderedSchema,
  _embedded: z
    .object({
      'wp:featuredmedia': z.array(featuredMediaSchema).optional(),
    })
    .optional(),
})
export type Article = z.infer<typeof articleSchema>

/** The collection endpoint returns a bare JSON array. */
export const articleListSchema = z.array(articleSchema)

/**
 * A failure the UI can branch on. `status` is the HTTP status, or 0 when the
 * request never reached WordPress (offline, DNS, CORS, abort).
 */
export class ArticlesApiError extends Error {
  status: number

  constructor(status: number, message: string) {
    super(message)
    this.name = 'ArticlesApiError'
    this.status = status
  }
}

/** Build `/wp-api/index.php?rest_route=/wp/v2/posts&per_page=20&_embed=1`. */
function endpoint(route: string, params: Record<string, string | number> = {}): string {
  const query = new URLSearchParams({ rest_route: route })

  for (const [key, value] of Object.entries(params)) {
    query.set(key, String(value))
  }

  return `${WP_BASE_URL}/index.php?${query.toString()}`
}

async function requestJson(url: string, signal?: AbortSignal): Promise<unknown> {
  let response: Response

  try {
    response = await fetch(url, { signal, headers: { Accept: 'application/json' } })
  } catch (error) {
    // An aborted request is React Query cancelling, not a failure to report.
    if (error instanceof DOMException && error.name === 'AbortError') {
      throw error
    }

    throw new ArticlesApiError(0, 'Could not reach the article service.')
  }

  if (!response.ok) {
    throw new ArticlesApiError(
      response.status,
      response.status === 404
        ? 'That article does not exist.'
        : 'The article service is unavailable.',
    )
  }

  try {
    return await response.json()
  } catch {
    throw new ArticlesApiError(response.status, 'The article service returned invalid JSON.')
  }
}

function validate<T>(schema: z.ZodType<T>, payload: unknown): T {
  const result = schema.safeParse(payload)

  if (!result.success) {
    throw new ArticlesApiError(0, 'The article service returned an unexpected response.')
  }

  return result.data
}

/** The published articles, newest first, as WordPress orders them. */
export async function fetchPosts(signal?: AbortSignal): Promise<Article[]> {
  const payload = await requestJson(endpoint('/wp/v2/posts', { per_page: PER_PAGE, _embed: 1 }), signal)

  return validate(articleListSchema, payload)
}

/**
 * A single article by **id**. Slugs are percent-encoded Arabic here, so ids are
 * the only stable key in a URL.
 */
export async function fetchPost(id: string | number, signal?: AbortSignal): Promise<Article> {
  const payload = await requestJson(endpoint(`/wp/v2/posts/${id}`, { _embed: 1 }), signal)

  return validate(articleSchema, payload)
}

export function featuredImageUrl(article: Article): string | null {
  return article._embedded?.['wp:featuredmedia']?.[0]?.source_url ?? null
}

/**
 * WordPress hands back HTML with numeric entities (`&#8211;`, `&amp;`) in the
 * title and excerpt. We never render those as HTML: `DOMParser` decodes them
 * and the plain string is handed to React, which escapes it again - so a
 * `&lt;img onerror=...&gt;` in a title can never become markup.
 */
export function toPlainText(html: string): string {
  const parsed = new DOMParser().parseFromString(html, 'text/html')

  return (parsed.body.textContent ?? '').trim()
}

/** Format a WordPress date in the browser's locale. */
export function formatDate(date: string): string {
  const parsed = new Date(date)

  if (Number.isNaN(parsed.getTime())) {
    return date
  }

  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(parsed)
}
