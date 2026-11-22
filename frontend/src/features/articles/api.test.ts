import { http, HttpResponse } from 'msw'
import { describe, expect, it } from 'vitest'

import {
  ArticlesApiError,
  articleListSchema,
  articleSchema,
  fetchPost,
  fetchPosts,
  formatDate,
  toPlainText,
} from '@/features/articles/api'
import { server } from '@/test/server'

/**
 * Shaped like the real payload: Arabic titles, numeric entities, and one post
 * whose content and excerpt are legitimately empty (WordPress post id 5).
 */
const POST_WITH_CONTENT = {
  id: 1,
  date: '2026-11-10T09:00:00',
  title: { rendered: 'RateGuard &#8211; Freight Audit' },
  excerpt: { rendered: '<p>How we recover overcharges.</p>' },
  content: { rendered: '<p>Every cent.</p>' },
  _embedded: { 'wp:featuredmedia': [{ source_url: 'https://example.test/cover.jpg' }] },
}

const POST_WITHOUT_CONTENT = {
  id: 5,
  date: '2026-11-12T09:00:00',
  title: { rendered: 'مقالة بلا محتوى' },
  excerpt: { rendered: '' },
  content: { rendered: '' },
}

describe('articles schema', () => {
  it('accepts a post with content', () => {
    const parsed = articleSchema.parse(POST_WITH_CONTENT)

    expect(parsed._embedded?.['wp:featuredmedia']?.[0]?.source_url).toBe(
      'https://example.test/cover.jpg',
    )
  })

  it('accepts a post whose content and excerpt are empty', () => {
    const parsed = articleSchema.parse(POST_WITHOUT_CONTENT)

    expect(parsed.content.rendered).toBe('')
    expect(parsed.excerpt.rendered).toBe('')
  })

  it('accepts the bare array the collection endpoint returns', () => {
    const parsed = articleListSchema.parse([POST_WITH_CONTENT, POST_WITHOUT_CONTENT])

    expect(parsed).toHaveLength(2)
  })

  it('rejects a post without an id', () => {
    const withoutId = {
      date: POST_WITH_CONTENT.date,
      title: POST_WITH_CONTENT.title,
      excerpt: POST_WITH_CONTENT.excerpt,
      content: POST_WITH_CONTENT.content,
    }

    expect(articleSchema.safeParse(withoutId).success).toBe(false)
  })

  it('rejects a post whose title is not a rendered object', () => {
    const malformed = { ...POST_WITH_CONTENT, title: 'just a string' }

    expect(articleSchema.safeParse(malformed).success).toBe(false)
  })
})

describe('fetchPosts', () => {
  it('requests the posts route through index.php and returns validated articles', async () => {
    const seen: { url: URL | null } = { url: null }

    server.use(
      http.get('/wp-api/index.php', ({ request }) => {
        seen.url = new URL(request.url)

        return HttpResponse.json([POST_WITH_CONTENT, POST_WITHOUT_CONTENT])
      }),
    )

    const articles = await fetchPosts()

    expect(articles).toHaveLength(2)
    expect(articles[0]?.id).toBe(1)

    expect(seen.url?.searchParams.get('rest_route')).toBe('/wp/v2/posts')
    expect(seen.url?.searchParams.get('per_page')).toBe('20')
    expect(seen.url?.searchParams.get('_embed')).toBe('1')
  })

  it('throws a clear error when the service returns an unexpected payload', async () => {
    server.use(http.get('/wp-api/index.php', () => HttpResponse.json({ message: 'nope' })))

    await expect(fetchPosts()).rejects.toBeInstanceOf(ArticlesApiError)
  })
})

describe('fetchPost', () => {
  it('carries the HTTP status on the error', async () => {
    server.use(
      http.get('/wp-api/index.php', () =>
        HttpResponse.json({ message: 'not found' }, { status: 404 }),
      ),
    )

    await expect(fetchPost(999)).rejects.toMatchObject({ status: 404 })
  })

  it('reports a transport failure as status 0', async () => {
    server.use(http.get('/wp-api/index.php', () => HttpResponse.error()))

    await expect(fetchPost(1)).rejects.toMatchObject({ status: 0 })
  })
})

describe('toPlainText', () => {
  it('decodes WordPress entities and strips markup', () => {
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
