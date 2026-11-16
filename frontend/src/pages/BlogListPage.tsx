import { useState } from 'react'

import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'

import { Pagination } from '@/components/Pagination'
import { EmptyState } from '@/components/ui/EmptyState'
import { SkeletonRows } from '@/components/ui/Skeleton'
import { fetchPosts } from '@/lib/api/content'
import { queryKeys } from '@/lib/queryKeys'

const PER_PAGE = 10

/**
 * Public blog index. The server-rendered shell may have inlined the first page
 * (see `seedFromInitialContent`), in which case this renders instantly with no
 * extra request.
 */
export function BlogListPage() {
  const [page, setPage] = useState(1)

  const query = useQuery({
    queryKey: queryKeys.posts(page),
    queryFn: ({ signal }) => fetchPosts({ page, perPage: PER_PAGE }, signal),
  })

  return (
    <div className="mx-auto max-w-3xl px-4 py-10">
      <h1 className="text-2xl font-semibold text-slate-900">Blog</h1>
      <p className="mt-1 text-sm text-slate-500">Freight audit insights from the RateGuard team.</p>

      {query.isPending ? (
        <div className="mt-6">
          <SkeletonRows rows={4} />
        </div>
      ) : null}

      {query.isError ? (
        <div className="mt-6">
          <EmptyState
            title="Content is temporarily unavailable"
            description="Please try again in a moment."
          />
        </div>
      ) : null}

      {query.data && query.data.data.length === 0 ? (
        <div className="mt-6">
          <EmptyState title="No posts yet" />
        </div>
      ) : null}

      {query.data && query.data.data.length > 0 ? (
        <>
          <ul className="mt-6 divide-y divide-slate-200">
            {query.data.data.map((post) => (
              <li key={post.id} className="py-5">
                <Link to={`/blog/${post.slug}`} className="group block">
                  <h2 className="text-lg font-semibold text-slate-900 group-hover:text-indigo-600">
                    {post.title}
                  </h2>
                  <p className="mt-1 text-sm text-slate-600">{post.excerpt}</p>
                  {post.published_at ? (
                    <p className="mt-1 text-xs text-slate-400">
                      {post.published_at.slice(0, 10)}
                      {post.author ? ` · ${post.author}` : ''}
                    </p>
                  ) : null}
                </Link>
              </li>
            ))}
          </ul>

          <Pagination
            page={query.data.meta.page}
            lastPage={query.data.meta.total_pages}
            onPageChange={setPage}
          />
        </>
      ) : null}
    </div>
  )
}
