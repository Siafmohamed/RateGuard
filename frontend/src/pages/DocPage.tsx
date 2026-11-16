import { useEffect } from 'react'

import { useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'

import { SanitizedHtml } from '@/components/SanitizedHtml'
import { EmptyState } from '@/components/ui/EmptyState'
import { SkeletonRows } from '@/components/ui/Skeleton'
import { fetchPage } from '@/lib/api/content'
import { isApiError } from '@/lib/api/errors'
import { queryKeys } from '@/lib/queryKeys'

/**
 * A documentation page served by the content gateway (WordPress page). Like the
 * blog, it relies on the shell's inlined payload when the route was rendered on
 * the server, and falls back to a client fetch otherwise.
 */
export function DocPage() {
  const { slug } = useParams<{ slug: string }>()

  const query = useQuery({
    queryKey: queryKeys.page(slug ?? ''),
    queryFn: ({ signal }) => fetchPage(slug as string, signal),
    enabled: Boolean(slug),
  })

  const page = query.data

  useEffect(() => {
    if (page?.seo?.title) {
      document.title = page.seo.title
    }
  }, [page])

  if (query.isError && isApiError(query.error) && query.error.status === 404) {
    return (
      <div className="mx-auto max-w-3xl px-4 py-10">
        <EmptyState
          title="Page not found"
          description="That documentation page does not exist."
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
        <EmptyState title="Content is temporarily unavailable" />
      ) : null}

      {page ? (
        <>
          <h1 className="text-3xl font-semibold text-slate-900">{page.title}</h1>
          <SanitizedHtml
            html={page.content_html}
            className="prose mt-6 max-w-none text-slate-700"
          />
        </>
      ) : null}
    </article>
  )
}
