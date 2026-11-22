<?php

namespace App\Domain\Content\Repositories;

use App\Domain\Content\Data\PaginatedContent;
use App\Domain\Content\Data\PostData;
use App\Domain\Content\Exceptions\ContentNotFoundException;
use App\Domain\Content\Exceptions\ContentUnavailableException;
use App\Domain\Content\Models\Post;
use Illuminate\Database\QueryException;

/**
 * Reads imported WordPress posts out of the local `posts` table.
 *
 * This is the database-backed counterpart of the REST client: it returns the
 * very same DTOs (PaginatedContent / PostData), so the API resources, the SEO
 * shell and React all keep seeing the exact same wire contract. Nothing here
 * talks to WordPress - SyncPostsCommand owns the import.
 */
class PostRepository
{
    public function paginate(int $page, int $perPage, ?string $category = null): PaginatedContent
    {
        try {
            $query = Post::query()
                ->where('status', 'publish')
                ->orderByDesc('published_at')
                ->orderByDesc('wp_id');

            if ($category !== null) {
                // `categories` is a JSON array of objects, so a JSON path
                // expression cannot match it portably; the slug is matched as
                // text instead. The slug is regex-validated by the controller
                // (^[a-z0-9-]+$), so it cannot break out of the pattern.
                $query->where('categories', 'like', '%"slug":"'.$category.'"%');
            }

            $paginator = $query->paginate(perPage: $perPage, page: $page);
        } catch (QueryException $exception) {
            throw ContentUnavailableException::forRequest($exception->getMessage());
        }

        return new PaginatedContent(
            items: $paginator->getCollection()
                ->map(fn (Post $post): PostData => $this->toData($post))
                ->all(),
            total: $paginator->total(),
            totalPages: $paginator->lastPage(),
            page: $paginator->currentPage(),
            perPage: $paginator->perPage(),
        );
    }

    public function find(string $slug): PostData
    {
        try {
            $post = Post::query()
                ->where('status', 'publish')
                ->where(function ($query) use ($slug): void {
                    // The router hands us the slug percent-decoded, while
                    // WordPress stores non-Latin slugs percent-encoded in
                    // lower-case hex (e.g. "%d9%85..."), so match both the
                    // literal slug and its re-encoded form.
                    $query->where('slug', $slug)
                        ->orWhere('slug', strtolower(rawurlencode($slug)));
                })
                ->first();
        } catch (QueryException $exception) {
            throw ContentUnavailableException::forRequest($exception->getMessage());
        }

        if ($post === null) {
            throw ContentNotFoundException::forSlug($slug);
        }

        return $this->toData($post);
    }

    /**
     * Map a stored row onto the same DTO the REST client produced.
     */
    private function toData(Post $post): PostData
    {
        return new PostData(
            id: $post->wp_id,
            slug: $post->slug,
            title: $post->title,
            excerpt: $post->excerpt,
            contentHtml: $post->content_html,
            publishedAt: $post->published_at?->toIso8601String(),
            modifiedAt: $post->modified_at?->toIso8601String(),
            featuredImageUrl: $post->featured_image_url,
            author: $post->author,
            categories: $post->categories ?? [],
            seo: $post->seo,
        );
    }
}
