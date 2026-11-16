<?php

namespace App\Http\Controllers\Web;

use App\Domain\Content\Data\PostData;
use App\Domain\Content\Exceptions\ContentNotFoundException;
use App\Domain\Content\Exceptions\ContentUnavailableException;
use App\Domain\Content\Repositories\ContentRepository;
use App\Domain\Seo\SeoMeta;
use App\Domain\Seo\SpaShell;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Public SEO shell. Laravel serves real HTML (head + content) for /blog and
 * /docs, built from the CACHED content gateway - WordPress is never called from
 * here directly (the repository owns that). React then mounts over the shell.
 *
 * These routes carry no auth and no tenant middleware: the content is public
 * marketing material, not tenant data.
 */
class SeoController extends Controller
{
    private const SITEMAP_TTL = 3600;

    private const SITEMAP_PER_PAGE = 100;

    /** Guard against an unbounded crawl if WordPress reports a huge page count. */
    private const SITEMAP_MAX_PAGES = 20;

    public function __construct(
        private readonly ContentRepository $content,
        private readonly SpaShell $shell,
    ) {}

    public function posts(): Response
    {
        try {
            $result = $this->content->posts(1, 10);
        } catch (ContentUnavailableException) {
            return $this->unavailable();
        }

        $list = array_map(
            fn (PostData $post): array => [
                'url' => $this->absolute("blog/{$post->slug}"),
                'title' => $post->title,
            ],
            $result->items,
        );

        $initial = [
            'type' => 'post-list',
            'data' => array_map($this->postPreview(...), $result->items),
            'meta' => [
                'total' => $result->total,
                'total_pages' => $result->totalPages,
                'page' => $result->page,
                'per_page' => $result->perPage,
            ],
        ];

        return $this->respond(SeoMeta::forList(), null, $list, $initial);
    }

    public function post(string $slug): Response
    {
        try {
            $post = $this->content->post($slug);
        } catch (ContentNotFoundException) {
            return $this->notFound();
        } catch (ContentUnavailableException) {
            return $this->unavailable();
        }

        return $this->respond(
            meta: SeoMeta::forPost($post),
            contentHtml: $post->contentHtml,
            initialContent: ['type' => 'post', 'data' => $post->toArray()],
            modifiedAt: $post->modifiedAt,
            slug: $slug,
        );
    }

    public function page(string $slug): Response
    {
        // TODO: docs are WordPress *pages* for now. When a dedicated "docs" CPT
        // (or a docs taxonomy) is registered, resolve the doc from it instead.
        try {
            $page = $this->content->page($slug);
        } catch (ContentNotFoundException) {
            return $this->notFound();
        } catch (ContentUnavailableException) {
            return $this->unavailable();
        }

        return $this->respond(
            meta: SeoMeta::forPage($page),
            contentHtml: $page->contentHtml,
            initialContent: ['type' => 'page', 'data' => $page->toArray()],
            modifiedAt: $page->modifiedAt,
            slug: $slug,
        );
    }

    public function sitemap(): Response
    {
        try {
            $xml = Cache::remember(
                'seo:sitemap',
                self::SITEMAP_TTL,
                fn (): string => $this->buildSitemap(),
            );
        } catch (ContentUnavailableException) {
            return $this->unavailable();
        }

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function robots(): Response
    {
        $body = implode("\n", [
            'User-agent: *',
            'Allow: /',
            'Disallow: /api/',
            '',
            'Sitemap: '.$this->baseUrl().'/sitemap.xml',
            '',
        ]);

        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * Build and return the shell with the shared cache/etag headers.
     *
     * @param  list<array{url: string, title: string}>|null  $list
     * @param  array<string, mixed>|null  $initialContent
     */
    private function respond(
        SeoMeta $meta,
        ?string $contentHtml = null,
        ?array $list = null,
        ?array $initialContent = null,
        ?string $modifiedAt = null,
        ?string $slug = null,
    ): Response {
        $response = $this->shell->render($meta, $contentHtml, $list, $initialContent, 200);

        // Fresh for a minute, then revalidated in the background.
        $response->headers->set('Cache-Control', 'public, max-age=60, stale-while-revalidate=300');

        // A stale copy was served because WordPress is unreachable.
        if ($this->content->isStale()) {
            $response->headers->set('X-Content-Stale', 'true');
        }

        // ETag from the content modified_at + slug, so a repeat visit is a 304.
        if ($modifiedAt !== null && $slug !== null) {
            $response->setEtag(md5($modifiedAt.'|'.$slug));

            if ($response->isNotModified(request())) {
                return $response;
            }
        }

        return $response;
    }

    /**
     * A real 404 status, with the shell so React can render its own not-found
     * page, but noindex so crawlers record the 404.
     */
    private function notFound(): Response
    {
        return $this->error(404);
    }

    /**
     * WordPress is down and there is no stale copy: 503 + Retry-After, never a
     * 200 and never a 500.
     */
    private function unavailable(): Response
    {
        $response = $this->error(503);
        $response->headers->set('Retry-After', '60');

        return $response;
    }

    private function error(int $status): Response
    {
        $response = $this->shell->render(SeoMeta::forError($status), null, null, null, $status);

        // An error shell must never be cached as if it were real content.
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    private function buildSitemap(): string
    {
        $urls = [];

        $page = 1;
        do {
            $result = $this->content->posts($page, self::SITEMAP_PER_PAGE);

            foreach ($result->items as $post) {
                $urls[] = [
                    'loc' => $this->absolute("blog/{$post->slug}"),
                    'lastmod' => $post->modifiedAt,
                ];
            }

            $page++;
        } while ($page <= min($result->totalPages, self::SITEMAP_MAX_PAGES));

        $page = 1;
        do {
            $result = $this->content->pages($page, self::SITEMAP_PER_PAGE);

            foreach ($result->items as $doc) {
                $urls[] = [
                    'loc' => $this->absolute("docs/{$doc->slug}"),
                    'lastmod' => $doc->modifiedAt,
                ];
            }

            $page++;
        } while ($page <= min($result->totalPages, self::SITEMAP_MAX_PAGES));

        return $this->renderSitemap($urls);
    }

    /**
     * @param  list<array{loc: string, lastmod: ?string}>  $urls
     */
    private function renderSitemap(array $urls): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($urls as $url) {
            $xml .= "  <url>\n";
            $xml .= '    <loc>'.$this->xmlEscape($url['loc'])."</loc>\n";

            if (! empty($url['lastmod'])) {
                $xml .= '    <lastmod>'.$this->xmlEscape($url['lastmod'])."</lastmod>\n";
            }

            $xml .= "  </url>\n";
        }

        return $xml.'</urlset>';
    }

    private function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * Slim post shape for the list page's initial data - no content_html, so
     * the inlined payload stays small.
     *
     * @return array<string, mixed>
     */
    private function postPreview(PostData $post): array
    {
        return [
            'id' => $post->id,
            'slug' => $post->slug,
            'title' => $post->title,
            'excerpt' => $post->excerpt,
            'published_at' => $post->publishedAt,
            'modified_at' => $post->modifiedAt,
            'featured_image_url' => $post->featuredImageUrl,
            'author' => $post->author,
            'categories' => $post->categories,
            'seo' => $post->seo,
        ];
    }

    private function absolute(string $path): string
    {
        return $this->baseUrl().'/'.ltrim($path, '/');
    }

    /**
     * Absolute site base from configuration only - never the request Host.
     */
    private function baseUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }
}
