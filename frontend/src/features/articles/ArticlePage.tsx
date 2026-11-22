import { useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'

import { SanitizedHtml } from '@/components/SanitizedHtml'
import { Button } from '@/components/ui/Button'
import { EmptyState } from '@/components/ui/EmptyState'
import { SkeletonRows } from '@/components/ui/Skeleton'
import {
  ArticlesApiError,
  fetchPost,
  featuredImageUrl,
  formatDate,
  toPlainText,
} from '@/features/articles/api'

/**
 * A single article, addressed by **id** (WordPress slugs here are
 * percent-encoded Arabic, so they are unusable in a URL).
 *
 * `content.rendered` is the only raw HTML on this page and it goes through
 * `SanitizedHtml`, the app's single `dangerouslySetInnerHTML` site.
 */
export function ArticlePage() {
  const { id } = useParams<{ id: string }>()

  const query = useQuery({
    queryKey: ['article', id],
    queryFn: ({ signal }) => fetchPost(id as string, signal),
    enabled: Boolean(id),
    staleTime: 60_000,
  })

  const article = query.data
  const image = article ? featuredImageUrl(article) : null
  const notFound = query.error instanceof ArticlesApiError && query.error.status === 404

  if (notFound) {
    return (
      <div className="mx-auto max-w-3xl px-4 py-10">
        <EmptyState
          title="Article not found"
          description="That article does not exist or has been removed."
          action={
            <Link to="/articles" className="text-sm font-medium text-indigo-600">
              Back to articles
            </Link>
          }
        />
      </div>
    )
  }

  return (
    <article dir="auto" className="mx-auto max-w-3xl px-4 py-10">
      {query.isPending ? <SkeletonRows rows={5} /> : null}

      {query.isError && !notFound ? (
        <EmptyState
          title="Articles are temporarily unavailable"
          description="We could not load this article. Please try again."
          action={<Button onClick={() => void query.refetch()}>Retry</Button>}
        />
      ) : null}

      {article ? (
        <>
          <Link to="/articles" className="text-sm text-slate-500 hover:text-slate-800">
            ← Articles
          </Link>

          <h1 dir="auto" className="mt-4 text-3xl font-semibold text-slate-900">
            {toPlainText(article.title.rendered)}
          </h1>
          <p className="mt-2 text-sm text-slate-500">{formatDate(article.date)}</p>

          {image ? (
            <img
              src={image}
              alt=""
              className="mt-6 w-full rounded-lg border border-slate-200"
            />
          ) : null}

          {/* WordPress can legitimately publish an empty body, which would
              otherwise render a blank page with no explanation. */}
          {toPlainText(article.content.rendered) ? (
            <SanitizedHtml
              html={article.content.rendered}
              className="prose mt-6 max-w-none text-slate-700"
            />
          ) : (
            <div className="mt-6">
              <EmptyState
                title="This article has no content yet"
                description="The author has not published the body of this article."
              />
            </div>
          )}
        </>
      ) : null}
    </article>
  )
}
