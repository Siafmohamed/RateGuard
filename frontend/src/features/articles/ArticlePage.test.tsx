import { screen } from '@testing-library/react'
import { http, HttpResponse } from 'msw'
import { Route, Routes } from 'react-router-dom'
import { describe, expect, it } from 'vitest'

import { ArticlePage } from '@/features/articles/ArticlePage'
import { renderWithProviders } from '@/test/render'
import { server } from '@/test/server'

const XSS_CONTENT =
  '<p>Safe intro</p>' +
  '<script>window.__pwned = true</script>' +
  '<img src="x" onerror="window.__pwned = true">' +
  '<a href="javascript:alert(1)">bad</a>'

function renderArticle(id: string) {
  return renderWithProviders(
    <Routes>
      <Route path="/articles/:id" element={<ArticlePage />} />
    </Routes>,
    { route: `/articles/${id}` },
  )
}

function postHandler(payload: unknown, status = 200) {
  return http.get('/wp-api/index.php', () => HttpResponse.json(payload, { status }))
}

describe('ArticlePage', () => {
  it('sanitises content.rendered before it reaches the DOM', async () => {
    server.use(
      postHandler({
        id: 1,
        date: '2026-11-10T09:00:00',
        title: { rendered: 'A dangerous post' },
        excerpt: { rendered: '' },
        content: { rendered: XSS_CONTENT },
      }),
    )

    const { container } = renderArticle('1')

    expect(await screen.findByText('Safe intro')).toBeInTheDocument()

    // The harmless markup survives inside the sanitised wrapper...
    const sanitised = container.querySelector('.prose')

    expect(sanitised?.textContent).toContain('Safe intro')

    // ...but nothing executable does.
    expect(container.querySelector('script')).toBeNull()
    expect(container.querySelector('[onerror]')).toBeNull()
    expect(container.querySelector('a[href^="javascript:"]')).toBeNull()
  })

  it('explains an empty article instead of rendering a blank page', async () => {
    server.use(
      postHandler({
        id: 5,
        date: '2026-11-12T09:00:00',
        title: { rendered: 'مقالة بلا محتوى' },
        excerpt: { rendered: '' },
        content: { rendered: '' },
      }),
    )

    renderArticle('5')

    expect(await screen.findByText('This article has no content yet')).toBeInTheDocument()
  })

  it('shows a not-found state with a way back to the index', async () => {
    server.use(postHandler({ message: 'not found' }, 404))

    renderArticle('999')

    expect(await screen.findByText('Article not found')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Back to articles' })).toHaveAttribute(
      'href',
      '/articles',
    )
  })
})
