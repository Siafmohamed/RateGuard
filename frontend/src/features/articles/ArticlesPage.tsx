import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'

import { Button } from '@/components/ui/Button'
import { EmptyState } from '@/components/ui/EmptyState'
import { SkeletonRows } from '@/components/ui/Skeleton'
import { fetchPosts, formatDate, featuredImageUrl, toPlainText } from '@/features/articles/api'

/**
 * Public article index, read through the Laravel content gateway
 * (see features/articles). Titles and excerpts are always rendered as plain
 * text, never as markup.
 */
export function ArticlesPage() {
  const query = useQuery({
    queryKey: ['articles'],
    queryFn: ({ signal }) => fetchPosts(signal),
    staleTime: 60_000,
  })

  const articles = query.data ?? []

  return (
    <div className="mx-auto max-w-3xl px-4 py-10">
      <h1 className="text-2xl font-semibold text-slate-900">Articles</h1>
      <p className="mt-1 text-sm text-slate-500">Freight audit insights from the RateGuard team.</p>

      {query.isPending ? (
        <div className="mt-6">
          <SkeletonRows rows={4} />
        </div>
      ) : null}

      {query.isError ? (
        <div className="mt-6">
          <EmptyState
            title="Articles are temporarily unavailable"
            description="We could not load the articles. Please try again."
            action={<Button onClick={() => void query.refetch()}>Retry</Button>}
          />
        </div>
      ) : null}

      {query.isSuccess && articles.length === 0 ? (
        <div className="mt-6">
          <EmptyState title="No articles yet" description="Check back soon." />
        </div>
      ) : null}

      {query.isSuccess && articles.length > 0 ? (
        <ul className="mt-6 divide-y divide-slate-200">
          {articles.map((article) => {
            const image = featuredImageUrl(article)
            const excerpt = toPlainText(article.excerpt.rendered)

            return (
              <li key={article.id} className="py-5">
                {/* Slug, percent-encoded: the gateway's single-post route is by
                    slug, and Arabic slugs are stored percent-encoded. */}
                <Link
                  to={`/articles/${encodeURIComponent(article.slug)}`}
                  className="group flex gap-4"
                >
                  {image ? (
                    <img
                      src={image}
                      alt=""
                      className="h-20 w-28 shrink-0 rounded-md border border-slate-200 object-cover"
                    />
                  ) : null}

                  <div className="min-w-0">
                    {/* dir="auto" so Arabic renders right-to-left and English left-to-right. */}
                    <h2
                      dir="auto"
                      className="text-lg font-semibold text-slate-900 group-hover:text-indigo-600"
                    >
                      {toPlainText(article.title.rendered)}
                    </h2>
                    <p dir="auto" className="mt-1 text-sm text-slate-600">
                      {excerpt || 'No summary available.'}
                    </p>
                    <p className="mt-1 text-xs text-slate-400">{formatDate(article.date)}</p>
                  </div>
                </Link>
              </li>
            )
          })}
        </ul>
      ) : null}
    </div>
  )
}
