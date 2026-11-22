import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { http, HttpResponse } from 'msw'
import { describe, expect, it } from 'vitest'

import { ArticlesPage } from '@/features/articles/ArticlesPage'
import { renderWithProviders } from '@/test/render'
import { server } from '@/test/server'

const POST_WITH_CONTENT = {
  id: 1,
  date: '2026-11-10T09:00:00',
  title: { rendered: 'RateGuard &#8211; Freight Audit' },
  excerpt: { rendered: '<p>How we recover overcharges.</p>' },
  content: { rendered: '<p>Every cent.</p>' },
}

const POST_WITHOUT_CONTENT = {
  id: 5,
  date: '2026-11-12T09:00:00',
  title: { rendered: 'مقالة بلا محتوى' },
  excerpt: { rendered: '' },
  content: { rendered: '' },
}

/** The list is the only WordPress call this page makes. */
function listHandler(payload: unknown, status = 200) {
  return http.get('/wp-api/index.php', () => HttpResponse.json(payload, { status }))
}

describe('ArticlesPage', () => {
  it('renders the list, decoding HTML entities in the title', async () => {
    server.use(listHandler([POST_WITH_CONTENT, POST_WITHOUT_CONTENT]))

    renderWithProviders(<ArticlesPage />)

    // `&#8211;` must arrive as a readable en dash, not as raw markup or text.
    const headings = await screen.findAllByRole('heading', { level: 2 })

    expect(headings).toHaveLength(2)
    expect(screen.getByRole('heading', { name: 'RateGuard – Freight Audit' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'مقالة بلا محتوى' })).toBeInTheDocument()
    expect(screen.getByText('How we recover overcharges.')).toBeInTheDocument()

    // A post with an empty excerpt gets a placeholder rather than a blank row.
    expect(screen.getByText('No summary available.')).toBeInTheDocument()

    // Articles are addressed by id: Arabic slugs are percent-encoded.
    expect(screen.getByRole('link', { name: /RateGuard/ })).toHaveAttribute('href', '/articles/1')
  })

  it('shows the empty state when WordPress has no published posts', async () => {
    server.use(listHandler([]))

    renderWithProviders(<ArticlesPage />)

    expect(await screen.findByText('No articles yet')).toBeInTheDocument()
  })

  it('shows an error state with a working retry', async () => {
    let calls = 0

    server.use(
      http.get('/wp-api/index.php', () => {
        calls += 1

        // Fail once, then succeed: the retry button must re-issue the query.
        return calls === 1
          ? HttpResponse.json({ message: 'boom' }, { status: 500 })
          : HttpResponse.json([POST_WITH_CONTENT])
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
