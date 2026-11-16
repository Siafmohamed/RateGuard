<?php

namespace App\Domain\Content\Data;

use App\Domain\Content\Support\ContentSanitizer;
use App\Domain\Content\Support\WpFields;

/**
 * A single WordPress post, mapped to a stable shape.
 *
 * Immutable and free of raw WP keys: the wire format is whatever the resources
 * decide, and the cache stores the array form (see toArray/fromArray).
 */
final readonly class PostData
{
    /**
     * @param  list<array{id: int, slug: string, name: string}>  $categories
     * @param  array{title: string, description: string}|null  $seo
     */
    public function __construct(
        public int $id,
        public string $slug,
        public string $title,
        public string $excerpt,
        public string $contentHtml,
        public ?string $publishedAt,
        public ?string $modifiedAt,
        public ?string $featuredImageUrl,
        public ?string $author,
        public array $categories,
        public ?array $seo,
    ) {}

    /**
     * @param  array<string, mixed>  $wp
     */
    public static function fromWp(array $wp): self
    {
        return new self(
            id: (int) ($wp['id'] ?? 0),
            slug: (string) ($wp['slug'] ?? ''),
            title: WpFields::text(data_get($wp, 'title.rendered')),
            excerpt: WpFields::text(data_get($wp, 'excerpt.rendered')),
            contentHtml: ContentSanitizer::sanitize(data_get($wp, 'content.rendered')),
            publishedAt: WpFields::iso($wp['date_gmt'] ?? null),
            modifiedAt: WpFields::iso($wp['modified_gmt'] ?? null),
            featuredImageUrl: WpFields::featuredImageUrl($wp),
            author: WpFields::author($wp),
            categories: WpFields::categories($wp),
            seo: WpFields::seo($wp),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'excerpt' => $this->excerpt,
            'content_html' => $this->contentHtml,
            'published_at' => $this->publishedAt,
            'modified_at' => $this->modifiedAt,
            'featured_image_url' => $this->featuredImageUrl,
            'author' => $this->author,
            'categories' => $this->categories,
            'seo' => $this->seo,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) ($data['id'] ?? 0),
            slug: (string) ($data['slug'] ?? ''),
            title: (string) ($data['title'] ?? ''),
            excerpt: (string) ($data['excerpt'] ?? ''),
            contentHtml: (string) ($data['content_html'] ?? ''),
            publishedAt: $data['published_at'] ?? null,
            modifiedAt: $data['modified_at'] ?? null,
            featuredImageUrl: $data['featured_image_url'] ?? null,
            author: $data['author'] ?? null,
            categories: $data['categories'] ?? [],
            seo: $data['seo'] ?? null,
        );
    }
}
