<?php

namespace App\Domain\Seo;

use App\Domain\Content\Data\PageData;
use App\Domain\Content\Data\PostData;
use Illuminate\Support\Str;

/**
 * Everything the <head> needs, derived from a content DTO (or from the list /
 * error state). Pure and framework-light.
 *
 * Security: canonical / og:url are ALWAYS built from config('app.url'), never
 * from the request Host header, so a spoofed Host cannot poison the canonical
 * link (and with it search-engine indexing).
 */
final readonly class SeoMeta
{
    public const SITE_NAME = 'RateGuard';

    /** Target ceiling for the whole <title>, suffix included. */
    private const TITLE_MAX = 60;

    private const TITLE_SUFFIX = ' | RateGuard';

    private const TITLE_ELLIPSIS = '…';

    private const DESCRIPTION_MAX = 155;

    /**
     * @param  string  $title  full document title, suffix included
     * @param  string  $ogTitle  bare title (no site suffix) for og:title
     * @param  array<string, mixed>|null  $jsonLd
     */
    public function __construct(
        public string $title,
        public string $ogTitle,
        public ?string $description,
        public ?string $canonical,
        public string $robots,
        public string $ogType,
        public ?string $ogImage,
        public ?string $publishedAt,
        public ?string $modifiedAt,
        public ?array $jsonLd,
        public string $siteName = self::SITE_NAME,
    ) {}

    public static function forPost(PostData $post): self
    {
        $headline = self::contentTitle($post->seo, $post->title);

        return new self(
            title: self::documentTitle($headline),
            ogTitle: $headline,
            description: self::description($post->seo, $post->excerpt),
            canonical: self::canonical("blog/{$post->slug}"),
            robots: 'index,follow',
            ogType: 'article',
            ogImage: $post->featuredImageUrl,
            publishedAt: $post->publishedAt,
            modifiedAt: $post->modifiedAt,
            jsonLd: self::articleJsonLd(
                type: 'BlogPosting',
                headline: $headline,
                image: $post->featuredImageUrl,
                author: $post->author,
                publishedAt: $post->publishedAt,
                modifiedAt: $post->modifiedAt,
                path: "blog/{$post->slug}",
            ),
        );
    }

    public static function forPage(PageData $page): self
    {
        $headline = self::contentTitle($page->seo, $page->title);

        return new self(
            title: self::documentTitle($headline),
            ogTitle: $headline,
            description: self::description($page->seo, $page->excerpt),
            canonical: self::canonical("docs/{$page->slug}"),
            robots: 'index,follow',
            ogType: 'website',
            ogImage: $page->featuredImageUrl,
            publishedAt: $page->publishedAt,
            modifiedAt: $page->modifiedAt,
            jsonLd: self::articleJsonLd(
                type: 'TechArticle',
                headline: $headline,
                image: $page->featuredImageUrl,
                author: $page->author,
                publishedAt: $page->publishedAt,
                modifiedAt: $page->modifiedAt,
                path: "docs/{$page->slug}",
            ),
        );
    }

    /**
     * The /blog index: title/meta only - no Article JSON-LD.
     */
    public static function forList(): self
    {
        $title = 'Blog';

        return new self(
            title: self::documentTitle($title),
            ogTitle: $title,
            description: 'Product updates, guides and shipping-audit insights from '.self::SITE_NAME.'.',
            canonical: self::canonical('blog'),
            robots: 'index,follow',
            ogType: 'website',
            ogImage: null,
            publishedAt: null,
            modifiedAt: null,
            jsonLd: null,
        );
    }

    /**
     * A CLIENT route (/login, /register, /app/*): the shell exists only so the
     * SPA can boot on a hard refresh. There is nothing here for a crawler, and
     * these pages must not compete with the indexable /blog and /docs routes,
     * so they are always noindex,nofollow and never canonicalised.
     */
    public static function forApp(): self
    {
        return new self(
            title: self::documentTitle(''),
            ogTitle: self::SITE_NAME,
            description: null,
            canonical: null,
            robots: 'noindex,nofollow',
            ogType: 'website',
            ogImage: null,
            publishedAt: null,
            modifiedAt: null,
            jsonLd: null,
        );
    }

    /**
     * The 404 / 503 shell. Always noindex,nofollow, and never canonicalised: a
     * canonical pointing at a page that does not exist is worse than none.
     */
    public static function forError(int $status): self
    {
        $title = $status === 404 ? 'Page not found' : 'Temporarily unavailable';

        return new self(
            title: self::documentTitle($title),
            ogTitle: $title,
            description: null,
            canonical: null,
            robots: 'noindex,nofollow',
            ogType: 'website',
            ogImage: null,
            publishedAt: null,
            modifiedAt: null,
            jsonLd: null,
        );
    }

    /**
     * SEO-plugin title if present, else the content title.
     *
     * @param  array{title: string, description: string}|null  $seo
     */
    private static function contentTitle(?array $seo, string $fallback): string
    {
        $title = trim((string) ($seo['title'] ?? ''));

        if ($title !== '') {
            return $title;
        }

        $title = trim($fallback);

        return $title !== '' ? $title : self::SITE_NAME;
    }

    /**
     * SEO description if present, else the excerpt with any HTML stripped.
     *
     * @param  array{title: string, description: string}|null  $seo
     */
    private static function description(?array $seo, string $excerpt): ?string
    {
        $text = trim((string) ($seo['description'] ?? ''));

        if ($text === '') {
            $text = trim(html_entity_decode(strip_tags($excerpt), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        if ($text === '') {
            return null;
        }

        return Str::limit((string) preg_replace('/\s+/u', ' ', $text), self::DESCRIPTION_MAX);
    }

    /**
     * Keep the document title within ~60 characters: the suffix is always kept,
     * the title itself is truncated cleanly on a character boundary.
     */
    private static function documentTitle(string $base): string
    {
        $base = trim($base);

        if ($base === '') {
            return self::SITE_NAME;
        }

        $budget = self::TITLE_MAX
            - mb_strwidth(self::TITLE_SUFFIX)
            - mb_strwidth(self::TITLE_ELLIPSIS);

        return Str::limit($base, $budget, self::TITLE_ELLIPSIS).self::TITLE_SUFFIX;
    }

    /**
     * Absolute URL from config('app.url') + path (never the request Host).
     */
    private static function canonical(string $path): string
    {
        $base = rtrim((string) config('app.url'), '/');

        return $path === '' ? $base : $base.'/'.ltrim($path, '/');
    }

    /**
     * Schema.org article markup. Empty fields are omitted entirely.
     *
     * @return array<string, mixed>
     */
    private static function articleJsonLd(
        string $type,
        string $headline,
        ?string $image,
        ?string $author,
        ?string $publishedAt,
        ?string $modifiedAt,
        string $path,
    ): array {
        $url = self::canonical($path);

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => $type,
            'headline' => $headline,
            'datePublished' => $publishedAt,
            'dateModified' => $modifiedAt,
            'image' => $image,
            'author' => $author !== null && $author !== ''
                ? ['@type' => 'Person', 'name' => $author]
                : null,
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
            'publisher' => ['@type' => 'Organization', 'name' => self::SITE_NAME],
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
