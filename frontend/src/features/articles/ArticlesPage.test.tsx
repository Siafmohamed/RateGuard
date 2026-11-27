import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { http, HttpResponse, type JsonBodyType } from 'msw'
import { describe, expect, it } from 'vitest'

import { ArticlesPage } from '@/features/articles/ArticlesPage'
import { renderWithProviders } from '@/test/render'
import { server } from '@/test/server'

/**
 * Gateway payloads. `GET /content/posts` returns `{ data: PostPreview[], meta }`
 * and the text fields are already entity-decoded server-side (WpFields::text),
 * so a title arrives with a real en dash, not `&#8211;`.
 */
const POST_WITH_CONTENT = {
  id: 1,
  slug: 'rateguard-freight-audit',
  title: 'RateGuard – Freight Audit',
  excerpt: 'How we recover overcharges.',
  published_at: '2026-11-10T09:00:00',
  modified_at: null,
  featured_image_url: null,
  author: null,
  categories: [],
  seo: null,
}

const POST_WITHOUT_CONTENT = {
  id: 5,
  slug: 'مقالة-بلا-محتوى',
  title: 'مقالة بلا محتوى',
  excerpt: '',
  published_at: '2026-11-12T09:00:00',
  modified_at: null,
  featured_image_url: null,
  author: null,
  categories: [],
  seo: null,
}

/** Build the paginated list envelope the gateway returns. */
function listPayload(...posts: JsonBodyType[]): JsonBodyType {
  return {
    data: posts,
    meta: { total: posts.length, total_pages: 1, page: 1, per_page: 20 },
  }
}

/** The list is the only gateway call this page makes. */
function listHandler(payload: JsonBodyType, status = 200) {
  return http.get('/api/v1/content/posts', () => HttpResponse.json(payload, { status }))
}

describe('ArticlesPage', () => {
  it('renders the list and links each article by its slug', async () => {
    server.use(listHandler(listPayload(POST_WITH_CONTENT, POST_WITHOUT_CONTENT)))

    renderWithProviders(<ArticlesPage />)

    const headings = await screen.findAllByRole('heading', { level: 2 })

    expect(headings).toHaveLength(2)
    expect(screen.getByRole('heading', { name: 'RateGuard – Freight Audit' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'مقالة بلا محتوى' })).toBeInTheDocument()
    expect(screen.getByText('How we recover overcharges.')).toBeInTheDocument()

    // A post with an empty excerpt gets a placeholder rather than a blank row.
    expect(screen.getByText('No summary available.')).toBeInTheDocument()

    expect(screen.getByRole('link', { name: /RateGuard/ })).toHaveAttribute(
      'href',
      '/articles/rateguard-freight-audit',
    )
  })

  it('percent-encodes an Arabic slug so it survives the URL', async () => {
    const arabic = { ...POST_WITH_CONTENT, id: 7, slug: '%d9%85%d9%82%d8%a7%d9%84%d8%a9' }

    server.use(listHandler(listPayload(arabic)))

    renderWithProviders(<ArticlesPage />)

    const link = await screen.findByRole('link', { name: /RateGuard/ })

    expect(link).toHaveAttribute('href', `/articles/${encodeURIComponent(arabic.slug)}`)
  })

  it('shows the empty state when the gateway has no published posts', async () => {
    server.use(listHandler(listPayload()))

    renderWithProviders(<ArticlesPage />)

    expect(await screen.findByText('No articles yet')).toBeInTheDocument()
  })

  it('shows an error state with a working retry', async () => {
    let calls = 0

    server.use(
      http.get('/api/v1/content/posts', () => {
        calls += 1

        // Fail once, then succeed: the retry button must re-issue the query.
        return calls === 1
          ? HttpResponse.json({ message: 'boom' }, { status: 500 })
          : HttpResponse.json(listPayload(POST_WITH_CONTENT))
      }),
    )

    renderWithProviders(<ArticlesPage />)

    expect(await screen.findByText('Articles are temporarily unavailable')).toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Retry' }))

    await waitFor(() =>
      expect(screen.getByRole('heading', { name: 'RateGuard – Freight Audit' })).toBeInTheDocument(),
    )
  })
})
