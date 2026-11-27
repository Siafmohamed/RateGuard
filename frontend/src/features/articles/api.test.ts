import { http, HttpResponse } from 'msw'
import { describe, expect, it } from 'vitest'

import {
  ArticlesApiError,
  fetchPost,
  fetchPosts,
  featuredImageUrl,
  formatDate,
  toPlainText,
} from '@/features/articles/api'
import { server } from '@/test/server'

/**
 * Shaped like the gateway contract: `PostPreview` for the list (no
 * `content_html`), `ContentPost` for the single post.
 */
const PREVIEW = {
  id: 1,
  slug: 'rateguard-freight-audit',
  title: 'RateGuard – Freight Audit',
  excerpt: 'How we recover overcharges.',
  published_at: '2026-11-10T09:00:00',
  modified_at: null,
  featured_image_url: 'https://example.test/cover.jpg',
  author: 'Amira',
  categories: [{ id: 3, slug: 'news', name: 'News', description: null }],
  seo: null,
}

const POST = {
  ...PREVIEW,
  content_html: '<p>Every cent.</p>',
}

const META = { total: 1, total_pages: 1, page: 1, per_page: 20 }

describe('fetchPosts', () => {
  it('reads the gateway list and maps it onto the article view model', async () => {
    const seen: { url: URL | null } = { url: null }

    server.use(
      http.get('/api/v1/content/posts', ({ request }) => {
        seen.url = new URL(request.url)

        return HttpResponse.json({ data: [PREVIEW], meta: META })
      }),
    )

    const articles = await fetchPosts()

    expect(articles).toHaveLength(1)
    expect(articles[0]).toMatchObject({
      id: 1,
      slug: 'rateguard-freight-audit',
      date: '2026-11-10T09:00:00',
    })
    expect(articles[0]?.title.rendered).toBe('RateGuard – Freight Audit')
    expect(featuredImageUrl(articles[0]!)).toBe('https://example.test/cover.jpg')

    expect(seen.url?.pathname).toBe('/api/v1/content/posts')
    expect(seen.url?.searchParams.get('per_page')).toBe('20')
  })

  it('throws an ArticlesApiError when the gateway answers an unexpected payload', async () => {
    server.use(http.get('/api/v1/content/posts', () => HttpResponse.json({ nope: true })))

    await expect(fetchPosts()).rejects.toBeInstanceOf(ArticlesApiError)
  })
})

describe('fetchPost', () => {
  it('unwraps the single-post envelope and keeps the body', async () => {
    server.use(http.get('/api/v1/content/posts/:slug', () => HttpResponse.json({ data: POST })))

    const article = await fetchPost('rateguard-freight-audit')

    expect(article.slug).toBe('rateguard-freight-audit')
    expect(article.content.rendered).toBe('<p>Every cent.</p>')
  })

  it('carries the HTTP status on the error', async () => {
    server.use(
      http.get('/api/v1/content/posts/:slug', () =>
        HttpResponse.json({ message: 'not found' }, { status: 404 }),
      ),
    )

    await expect(fetchPost('missing')).rejects.toMatchObject({ status: 404 })
  })

  it('reports a transport failure as status 0', async () => {
    server.use(http.get('/api/v1/content/posts/:slug', () => HttpResponse.error()))

    await expect(fetchPost('x')).rejects.toMatchObject({ status: 0 })
  })
})

describe('toPlainText', () => {
  it('decodes entities and strips markup', () => {
    expect(toPlainText('RateGuard &#8211; Freight Audit')).toBe('RateGuard – Freight Audit')
    expect(toPlainText('<p>How we <strong>recover</strong></p>')).toBe('How we recover')
    expect(toPlainText('')).toBe('')
  })

  it('returns entity-encoded markup as text, never as markup', () => {
    // A decoded `<script>` is just a string here; React escapes it again.
    expect(toPlainText('&lt;script&gt;alert(1)&lt;/script&gt;')).toBe('<script>alert(1)</script>')
  })
})

describe('formatDate', () => {
  it('falls back to the raw value when the date cannot be parsed', () => {
    expect(formatDate('not-a-date')).toBe('not-a-date')
  })
})
