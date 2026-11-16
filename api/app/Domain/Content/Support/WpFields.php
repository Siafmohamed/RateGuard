<?php

namespace App\Domain\Content\Support;

use Carbon\CarbonImmutable;

/**
 * Small, pure helpers for reading fields out of a decoded WordPress REST
 * payload. Keeping them here means the DTOs stay declarative and the raw WP
 * array shape (rendered/date_gmt/_embedded/_links/...) never leaks outward.
 */
final class WpFields
{
    /**
     * Turn a WordPress "rendered" HTML fragment into plain text.
     */
    public static function text(mixed $html): string
    {
        if (! is_string($html) || $html === '') {
            return '';
        }

        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * WordPress dates are UTC; expose them as ISO 8601.
     */
    public static function iso(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value, 'UTC')->toIso8601String();
    }

    /**
     * Featured image URL, if WordPress embedded one.
     *
     * @param  array<string, mixed>  $wp
     */
    public static function featuredImageUrl(array $wp): ?string
    {
        $url = data_get($wp, '_embedded.wp:featuredmedia.0.source_url');

        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * Author display name, if WordPress embedded one.
     *
     * @param  array<string, mixed>  $wp
     */
    public static function author(array $wp): ?string
    {
        $name = data_get($wp, '_embedded.author.0.name');

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * Category terms referenced by a post, from the embedded "wp:term" list.
     *
     * @param  array<string, mixed>  $wp
     * @return list<array{id: int, slug: string, name: string}>
     */
    public static function categories(array $wp): array
    {
        $terms = data_get($wp, '_embedded.wp:term', []);

        if (! is_array($terms)) {
            return [];
        }

        $categories = [];

        foreach ($terms as $group) {
            foreach (is_array($group) ? $group : [] as $term) {
                if (! is_array($term) || ($term['taxonomy'] ?? null) !== 'category') {
                    continue;
                }

                $categories[] = [
                    'id' => (int) ($term['id'] ?? 0),
                    'slug' => (string) ($term['slug'] ?? ''),
                    'name' => self::text($term['name'] ?? ''),
                ];
            }
        }

        return $categories;
    }

    /**
     * SEO title/description when an SEO plugin exposes them; null otherwise.
     *
     * @param  array<string, mixed>  $wp
     * @return array{title: string, description: string}|null
     */
    public static function seo(array $wp): ?array
    {
        $title = data_get($wp, 'yoast_head_json.title');
        $description = data_get($wp, 'yoast_head_json.description');

        if (! is_string($title) && ! is_string($description)) {
            return null;
        }

        return [
            'title' => self::text($title ?? ''),
            'description' => self::text($description ?? ''),
        ];
    }
}
