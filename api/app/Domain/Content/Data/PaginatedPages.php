<?php

namespace App\Domain\Content\Data;

/**
 * A page of WordPress pages plus the pagination metadata read from the
 * X-WP-Total / X-WP-TotalPages headers.
 *
 * The mirror of PaginatedContent for pages: the SEO sitemap needs to walk every
 * published page, and pages are not posts (no categories), so they get their own
 * container rather than being coerced into PostData.
 */
final readonly class PaginatedPages
{
    /**
     * @param  list<PageData>  $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $totalPages,
        public int $page,
        public int $perPage,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $wpItems
     */
    public static function fromWp(array $wpItems, int $total, int $totalPages, int $page, int $perPage): self
    {
        return new self(
            items: array_map(PageData::fromWp(...), $wpItems),
            total: $total,
            totalPages: $totalPages,
            page: $page,
            perPage: $perPage,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'items' => array_map(fn (PageData $page) => $page->toArray(), $this->items),
            'total' => $this->total,
            'total_pages' => $this->totalPages,
            'page' => $this->page,
            'per_page' => $this->perPage,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            items: array_map(PageData::fromArray(...), $data['items'] ?? []),
            total: (int) ($data['total'] ?? 0),
            totalPages: (int) ($data['total_pages'] ?? 0),
            page: (int) ($data['page'] ?? 1),
            perPage: (int) ($data['per_page'] ?? 0),
        );
    }
}
