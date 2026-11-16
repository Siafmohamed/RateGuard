import { api } from './client'
import {
  contentPageEnvelopeSchema,
  contentPostEnvelopeSchema,
  postListSchema,
  type ContentPage,
  type ContentPost,
  type PostList,
} from './schemas'
import { parseResponse } from './validate'

export interface PostsQuery {
  page?: number
  perPage?: number
  category?: string
}

/** Public content gateway - no auth, no tenant scoping. */
export async function fetchPosts(query: PostsQuery = {}, signal?: AbortSignal): Promise<PostList> {
  const { data } = await api.get('/content/posts', {
    params: {
      page: query.page,
      per_page: query.perPage,
      category: query.category,
    },
    signal,
  })

  return parseResponse(postListSchema, data, 'GET /content/posts')
}

export async function fetchPost(slug: string, signal?: AbortSignal): Promise<ContentPost> {
  const { data } = await api.get(`/content/posts/${slug}`, { signal })

  return parseResponse(contentPostEnvelopeSchema, data, 'GET /content/posts/{slug}').data
}

export async function fetchPage(slug: string, signal?: AbortSignal): Promise<ContentPage> {
  const { data } = await api.get(`/content/pages/${slug}`, { signal })

  return parseResponse(contentPageEnvelopeSchema, data, 'GET /content/pages/{slug}').data
}
