import { QueryClient } from '@tanstack/react-query'
import { afterEach, describe, expect, it } from 'vitest'

import { queryKeys } from './queryKeys'

import { detachServerRenderedShell, readInitialContent, seedFromInitialContent } from './hydration'

const POST = {
  id: 1,
  slug: 'rate-audit-basics',
  title: 'Rate audit basics',
  excerpt: 'How a freight audit works.',
  content_html: '<p>Hello</p>',
  published_at: '2026-01-02T00:00:00Z',
  modified_at: '2026-01-03T00:00:00Z',
  featured_image_url: null,
  author: 'Ops',
  categories: [{ id: 3, slug: 'guides', name: 'Guides', description: null, count: 4 }],
  seo: { title: 'Rate audit basics', description: 'How a freight audit works.' },
}

const PAGE = {
  id: 9,
  slug: 'getting-started',
  title: 'Getting started',
  excerpt: 'Install and upload.',
  content_html: '<p>Docs</p>',
  published_at: null,
  modified_at: null,
  featured_image_url: null,
  author: null,
  seo: null,
}

function freshClient(): QueryClient {
  return new QueryClient({ defaultOptions: { queries: { retry: false } } })
}

afterEach(() => {
  delete window.__INITIAL_CONTENT__
})

describe('seedFromInitialContent', () => {
  it('seeds the cache when type and slug both match, then deletes the global', () => {
    const queryClient = freshClient()

    window.__INITIAL_CONTENT__ = { type: 'post', data: POST }

    expect(seedFromInitialContent(queryClient, { kind: 'post', slug: POST.slug })).toBe(true)

    expect(queryClient.getQueryData(queryKeys.post(POST.slug))).toEqual(POST)
    // Read at most once: a later navigation must not re-seed from it.
    expect(window.__INITIAL_CONTENT__).toBeUndefined()
  })

  it('refuses to seed when the slug does not match the route, but still consumes it', () => {
    const queryClient = freshClient()

    window.__INITIAL_CONTENT__ = { type: 'post', data: POST }

    expect(seedFromInitialContent(queryClient, { kind: 'post', slug: 'another-post' })).toBe(false)

    expect(queryClient.getQueryData(queryKeys.post('another-post'))).toBeUndefined()
    expect(window.__INITIAL_CONTENT__).toBeUndefined()
  })

  it('refuses to seed when the type does not match the route', () => {
    const queryClient = freshClient()

    window.__INITIAL_CONTENT__ = { type: 'page', data: PAGE }

    expect(seedFromInitialContent(queryClient, { kind: 'post', slug: PAGE.slug })).toBe(false)
    expect(window.__INITIAL_CONTENT__).toBeUndefined()
  })

  it('seeds a page document', () => {
    const queryClient = freshClient()

    window.__INITIAL_CONTENT__ = { type: 'page', data: PAGE }

    expect(seedFromInitialContent(queryClient, { kind: 'page', slug: PAGE.slug })).toBe(true)
    expect(queryClient.getQueryData(queryKeys.page(PAGE.slug))).toEqual(PAGE)
  })

  it('seeds the post list using the page from the meta payload', () => {
    const queryClient = freshClient()

    const preview = { ...POST }
    delete (preview as Partial<typeof POST>).content_html

    window.__INITIAL_CONTENT__ = {
      type: 'post-list',
      data: [preview],
      meta: { total: 1, total_pages: 1, page: 3, per_page: 10 },
    }

    expect(seedFromInitialContent(queryClient, { kind: 'post-list' })).toBe(true)
    expect(queryClient.getQueryData(queryKeys.posts(3))).toEqual({
      data: [preview],
      meta: { total: 1, total_pages: 1, page: 3, per_page: 10 },
    })
  })

  it('ignores a payload that does not match its schema', () => {
    const queryClient = freshClient()

    window.__INITIAL_CONTENT__ = { type: 'post', data: { slug: 'incomplete' } }

    expect(seedFromInitialContent(queryClient, { kind: 'post', slug: 'incomplete' })).toBe(false)
  })

  it('returns false when there is nothing to hydrate', () => {
    expect(seedFromInitialContent(freshClient(), { kind: 'post-list' })).toBe(false)
  })
})

describe('readInitialContent', () => {
  it('returns the payload once and then undefined', () => {
    window.__INITIAL_CONTENT__ = { type: 'post', data: POST }

    expect(readInitialContent()?.type).toBe('post')
    expect(readInitialContent()).toBeNull()
  })
})

describe('detachServerRenderedShell', () => {
  it('removes the server-rendered #seo-content block', () => {
    const shell = document.createElement('div')

    shell.id = 'seo-content'
    document.body.append(shell)

    detachServerRenderedShell()

    expect(document.getElementById('seo-content')).toBeNull()
  })

  it('is a no-op when the block is absent', () => {
    expect(() => detachServerRenderedShell()).not.toThrow()
  })
})
