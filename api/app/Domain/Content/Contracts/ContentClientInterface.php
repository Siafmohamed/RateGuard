<?php

namespace App\Domain\Content\Contracts;

use App\Domain\Content\Data\CategoryData;
use App\Domain\Content\Data\PaginatedContent;
use App\Domain\Content\Data\PaginatedPages;
use App\Domain\Content\Data\PageData;
use App\Domain\Content\Data\PostData;

/**
 * Reads published content from the headless CMS.
 *
 * Implementations must return mapped DTOs only - no raw CMS payload escapes
 * them - and must signal "missing" and "unavailable" distinctly so the API can
 * answer 404 and 503 respectively (never 500).
 */
interface ContentClientInterface
{
    /**
     * A page of published posts, optionally filtered by category slug.
     */
    public function posts(int $page, int $perPage, ?string $category = null): PaginatedContent;

    /**
     * A page of published pages.
     */
    public function pages(int $page, int $perPage): PaginatedPages;

    /**
     * A single published post.
     *
     * @throws \App\Domain\Content\Exceptions\ContentNotFoundException
     */
    public function post(string $slug): PostData;

    /**
     * A single published page.
     *
     * @throws \App\Domain\Content\Exceptions\ContentNotFoundException
     */
    public function page(string $slug): PageData;

    /**
     * All categories.
     *
     * @return list<CategoryData>
     */
    public function categories(): array;
}
