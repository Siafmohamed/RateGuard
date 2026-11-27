import { isAxiosError } from 'axios'

import {
  fetchPost as fetchGatewayPost,
  fetchPosts as fetchGatewayPosts,
} from '@/lib/api/content'
import { isApiError } from '@/lib/api/errors'
import type { ContentPost, PostPreview } from '@/lib/api/schemas'

/**
 * Read-only article access through the Laravel content gateway
 * (`/api/v1/content/...`).
 *
 * The gateway is the only service that talks to WordPress: it fetches, caches
 * and sanitises, and it answers with a stable contract (`contentPostSchema` /
 * `postListSchema`). Calling WordPress straight from the browser only ever
 * worked in development, where the Vite dev server proxied it; in the
 * production build (nginx + php-fpm + Laravel in one container) there is no
 * such proxy, so every request failed with "File not found".
 *
 * The article pages keep rendering the shape they always have (see `Article`);
 * this module is the adapter that maps the gateway payload onto it.
 */

const PER_PAGE = 20

/** The view model the article pages render. */
export interface Article {
  id: number
  slug: string
  date: string
  title: { rendered: string }
  excerpt: { rendered: string }
  content: { rendered: string }
  imageUrl: string | null
}

/**
 * A failure the UI can branch on. `status` is the HTTP status, or 0 when the
 * request never reached the API (offline, DNS, abort).
 */
export class ArticlesApiError extends Error {
  status: number

  constructor(status: number, message: string) {
    super(message)
    this.name = 'ArticlesApiError'
    this.status = status
  }
}

/**
 * The gateway already decodes entities and strips markup (`WpFields::text`),
 * so `title` / `excerpt` arrive as plain strings; only `content_html` is
 * markup, and it is sanitised server-side before it reaches us.
 */
function toArticle(post: PostPreview | ContentPost): Article {
  return {
    id: post.id,
    slug: post.slug,
    date: post.published_at ?? '',
    title: { rendered: post.title },
    excerpt: { rendered: post.excerpt },
    // The list endpoint omits the body to keep the payload small.
    content: { rendered: 'content_html' in post ? post.content_html : '' },
    imageUrl: post.featured_image_url,
  }
}

/**
 * The transport can fail in two shapes: an `ApiError` once the axios
 * interceptors are installed (the app), or a raw `AxiosError` when they are not
 * (unit tests call the client directly).
 */
function statusOf(error: unknown): number {
  if (isApiError(error)) {
    return error.status
  }

  if (isAxiosError(error)) {
    return error.response?.status ?? 0
  }

  return 0
}

function messageFor(status: number): string {
  if (status === 0) {
    return 'Could not reach the article service.'
  }

  if (status === 404) {
    return 'That article does not exist.'
  }

  return 'The article service is unavailable.'
}

function toArticlesError(error: unknown): ArticlesApiError {
  if (error instanceof ArticlesApiError) {
    return error
  }

  const status = statusOf(error)

  return new ArticlesApiError(status, messageFor(status))
}

/** The published articles, newest first, as the gateway orders them. */
export async function fetchPosts(signal?: AbortSignal): Promise<Article[]> {
  try {
    const list = await fetchGatewayPosts({ perPage: PER_PAGE }, signal)

    return list.data.map(toArticle)
  } catch (error) {
    throw toArticlesError(error)
  }
}

/**
 * A single article by **slug**. Arabic slugs are percent-encoded, so the caller
 * encodes the slug into the URL; the gateway matches both the literal and the
 * re-encoded form.
 */
export async function fetchPost(slug: string, signal?: AbortSignal): Promise<Article> {
  try {
    return toArticle(await fetchGatewayPost(slug, signal))
  } catch (error) {
    throw toArticlesError(error)
  }
}

export function featuredImageUrl(article: Article): string | null {
  return article.imageUrl
}

/**
 * The gateway hands back plain text, but a title can still contain a stray
 * entity or angle bracket. `DOMParser` decodes entities and the plain string is
 * handed to React, which escapes it again - so a `<img onerror=...>` in a title
 * can never become markup.
 */
export function toPlainText(html: string): string {
  const parsed = new DOMParser().parseFromString(html, 'text/html')

  return (parsed.body.textContent ?? '').trim()
}

/** Format an ISO timestamp in the browser's locale. */
export function formatDate(date: string): string {
  const parsed = new Date(date)

  if (Number.isNaN(parsed.getTime())) {
    return date
  }

  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(parsed)
}
