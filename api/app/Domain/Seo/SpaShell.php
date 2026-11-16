<?php

namespace App\Domain\Seo;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Renders the SEO shell - the HTML Laravel sends for /blog and /docs before the
 * React SPA mounts.
 *
 * Two modes:
 *  - Built SPA present: the file at config('rateguard.spa.index_path') is read
 *    (cached, revalidated against its mtime), the head tags are injected before
 *    </head> and the server-rendered body right after <body>. A fresh copy is
 *    produced per request, so the head is injected exactly once.
 *  - No build yet (development): a minimal Blade document is rendered from
 *    resources/views/spa/shell.blade.php that boots the Vite dev server at
 *    config('rateguard.spa.dev_url') instead.
 *
 * This is a server-rendered shell + a CLIENT mount, NOT SSR: React boots with
 * createRoot(), which REPLACES the #seo-content element. There is no
 * hydrateRoot() call anywhere - do not describe this as hydration.
 */
final class SpaShell
{
    private const INDEX_CACHE_KEY = 'seo:spa:index';

    public function __construct(
        private readonly ViewFactory $views,
    ) {}

    /**
     * @param  list<array{url: string, title: string}>|null  $list
     * @param  array<string, mixed>|null  $initialContent
     */
    public function render(
        SeoMeta $meta,
        ?string $contentHtml = null,
        ?array $list = null,
        ?array $initialContent = null,
        int $status = 200,
    ): Response {
        return response($this->html($meta, $contentHtml, $list, $initialContent), $status);
    }

    /**
     * @param  list<array{url: string, title: string}>|null  $list
     * @param  array<string, mixed>|null  $initialContent
     */
    private function html(SeoMeta $meta, ?string $contentHtml, ?array $list, ?array $initialContent): string
    {
        $index = $this->indexHtml();

        if ($index === null) {
            return $this->views->make('spa.shell', [
                'meta' => $meta,
                'contentHtml' => $contentHtml,
                'list' => $list,
                'initialContent' => $initialContent,
            ])->render();
        }

        $head = $this->views->make('seo.head', ['meta' => $meta])->render();
        $body = $this->views->make('seo.body', [
            'meta' => $meta,
            'contentHtml' => $contentHtml,
            'list' => $list,
            'initialContent' => $initialContent,
        ])->render();

        return $this->inject($index, $head, $body);
    }

    /**
     * Read the built index.html, caching the read and revalidating it against
     * the file mtime so a new build is picked up without a cache flush.
     */
    private function indexHtml(): ?string
    {
        $path = (string) config('rateguard.spa.index_path');

        if ($path === '' || ! is_file($path)) {
            return null;
        }

        $mtime = (int) filemtime($path);
        $cached = Cache::get(self::INDEX_CACHE_KEY);

        if (
            is_array($cached)
            && ($cached['mtime'] ?? null) === $mtime
            && is_string($cached['html'] ?? null)
        ) {
            return $cached['html'];
        }

        $html = (string) file_get_contents($path);

        Cache::put(self::INDEX_CACHE_KEY, ['mtime' => $mtime, 'html' => $html], now()->addDay());

        return $html;
    }

    /**
     * Insert the head before </head> and the body right after the opening
     * <body ...> tag. If either marker is missing the document is returned
     * unchanged (better a shell without tags than a corrupted document).
     *
     * The Vite build ships a static <title>RateGuard</title> placeholder, but
     * $head always carries the authoritative title. The placeholder is dropped
     * first so a served document never has two <title> elements.
     */
    private function inject(string $html, string $head, string $body): string
    {
        $html = (string) preg_replace('#<title\b[^>]*>.*?</title>\s*#is', '', $html, 1);

        if (($head_at = stripos($html, '</head>')) !== false) {
            $html = substr_replace($html, $head, $head_at, 0);
        }

        if (preg_match('/<body[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE) === 1) {
            $body_at = $matches[0][1] + strlen($matches[0][0]);

            $html = substr_replace($html, $body, $body_at, 0);
        }

        return $html;
    }
}
