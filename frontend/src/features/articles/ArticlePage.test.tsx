import { screen } from '@testing-library/react'
import { http, HttpResponse, type JsonBodyType } from 'msw'
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

/** A gateway `ContentPost`, shaped for the single-post envelope. */
const BASE_POST = {
  id: 1,
  slug: 'a-dangerous-post',
  title: 'A dangerous post',
  excerpt: '',
  content_html: '',
  published_at: '2026-11-10T09:00:00',
  modified_at: null,
  featured_image_url: null,
  author: null,
  categories: [],
  seo: null,
}

function renderArticle(slug: string) {
  return renderWithProviders(
    <Routes>
      <Route path="/articles/:slug" element={<ArticlePage />} />
    </Routes>,
    { route: `/articles/${slug}` },
  )
}

function postHandler(payload: JsonBodyType, status = 200) {
  return http.get('/api/v1/content/posts/:slug', () => HttpResponse.json(payload, { status }))
}

describe('ArticlePage', () => {
  it('sanitises content before it reaches the DOM', async () => {
    server.use(postHandler({ data: { ...BASE_POST, content_html: XSS_CONTENT } }))

    const { container } = renderArticle('a-dangerous-post')

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
        data: { ...BASE_POST, id: 5, slug: 'empty', title: 'مقالة بلا محتوى' },
      }),
    )

    renderArticle('empty')

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
