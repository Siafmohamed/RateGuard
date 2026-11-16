import { useEffect } from 'react'

import { useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'

import { SanitizedHtml } from '@/components/SanitizedHtml'
import { EmptyState } from '@/components/ui/EmptyState'
import { SkeletonRows } from '@/components/ui/Skeleton'
import { fetchPost } from '@/lib/api/content'
import { isApiError } from '@/lib/api/errors'
import { queryKeys } from '@/lib/queryKeys'

/**
 * A single blog post. When the Phase 5 shell inlined this post, the cache has
 * already been seeded, so the first paint is the server's copy and no request
 * is made. `content_html` is the only place raw HTML is rendered, and only
 * through `SanitizedHtml`.
 */
export function BlogPostPage() {
  const { slug } = useParams<{ slug: string }>()

  const query = useQuery({
    queryKey: queryKeys.post(slug ?? ''),
    queryFn: ({ signal }) => fetchPost(slug as string, signal),
    enabled: Boolean(slug),
  })

  const post = query.data

  useEffect(() => {
    if (post?.seo?.title) {
      document.title = post.seo.title
    }
  }, [post])

  if (query.isError && isApiError(query.error) && query.error.status === 404) {
    return (
      <div className="mx-auto max-w-3xl px-4 py-10">
        <EmptyState
          title="Post not found"
          description="That article does not exist or has been removed."
          action={
            <Link to="/blog" className="text-sm font-medium text-indigo-600">
              Back to the blog
            </Link>
          }
        />
      </div>
    )
  }

  return (
    <article className="mx-auto max-w-3xl px-4 py-10">
      {query.isPending ? <SkeletonRows rows={5} /> : null}

      {query.isError && !(isApiError(query.error) && query.error.status === 404) ? (
        <EmptyState
          title="Content is temporarily unavailable"
          description="Please try again in a moment."
        />
      ) : null}

      {post ? (
        <>
          <Link to="/blog" className="text-sm text-slate-500 hover:text-slate-800">
            ← Blog
          </Link>

          <h1 className="mt-4 text-3xl font-semibold text-slate-900">{post.title}</h1>
          <p className="mt-2 text-sm text-slate-500">
            {post.published_at ? post.published_at.slice(0, 10) : ''}
            {post.author ? ` · ${post.author}` : ''}
          </p>

          {post.featured_image_url ? (
            <img
              src={post.featured_image_url}
              alt=""
              className="mt-6 w-full rounded-lg border border-slate-200"
            />
          ) : null}

          <SanitizedHtml
            html={post.content_html}
            className="prose mt-6 max-w-none text-slate-700"
          />
        </>
      ) : null}
    </article>
  )
}
