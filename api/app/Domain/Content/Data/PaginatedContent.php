<?php

namespace App\Domain\Content\Data;

/**
 * A page of posts plus the pagination metadata Laravel reads from WordPress'
 * X-WP-Total / X-WP-TotalPages headers.
 */
final readonly class PaginatedContent
{
    /**
     * @param  list<PostData>  $items
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
            items: array_map(PostData::fromWp(...), $wpItems),
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
            'items' => array_map(fn (PostData $post) => $post->toArray(), $this->items),
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
            items: array_map(PostData::fromArray(...), $data['items'] ?? []),
            total: (int) ($data['total'] ?? 0),
            totalPages: (int) ($data['total_pages'] ?? 0),
            page: (int) ($data['page'] ?? 1),
            perPage: (int) ($data['per_page'] ?? 0),
        );
    }
}
