<?php

namespace Tests\Feature\Seo;

use App\Domain\Content\Models\Post;
use App\Domain\Content\Support\ContentSanitizer;
use App\Domain\Content\Support\WpFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The SEO shell Laravel serves for /blog and /docs. Post pages are built from
 * the imported `posts` table (posts no longer hit WordPress), while docs are
 * still WordPress *pages* served through the cached content gateway
 * (Http::fake). The shell is exercised both with a built SPA present and missing.
 */
class SeoShellTest extends TestCase
{
    use RefreshDatabase;

    private ?string $indexPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.url', 'https://rateguard.test');

        config()->set('rateguard.content.base_url', 'http://cms.test');
        config()->set('rateguard.content.user', 'apiuser');
        config()->set('rateguard.content.app_password', 'super-secret-app-password');
        config()->set('rateguard.content.retries', 2);
        config()->set('rateguard.content.retry_delay_ms', 0);
        config()->set('rateguard.content.stale_ttl', 86400);

        // Deterministic "no build yet" by default: the dev-fallback shell.
        config()->set('rateguard.spa.index_path', base_path('tests/missing-spa-index.html'));

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        if ($this->indexPath !== null && File::exists($this->indexPath)) {
            File::delete($this->indexPath);
        }

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function wpPost(array $overrides = []): array
    {
        // A shallow replace on purpose: an override like ['yoast_head_json' => []]
        // must be able to CLEAR a default key. array_replace_recursive would merge
        // an empty array into the default and leave the yoast title/description in
        // place, so the test could never exercise the fallback path.
        return array_replace([
            'id' => 1234,
            'date_gmt' => '2026-10-01T12:00:00',
            'modified_gmt' => '2026-10-02T08:30:00',
            'slug' => 'hello-rateguard',
            'status' => 'publish',
            'type' => 'post',
            'title' => ['rendered' => 'Hello &amp; &quot;RateGuard&quot; &mdash; <em>Guide</em>'],
            'content' => ['rendered' => '<p>Intro &amp; details.</p>'],
            'excerpt' => ['rendered' => '<p>Short &amp; sweet summary.</p>'],
            'yoast_head_json' => [
                'title' => 'Hello & RateGuard',
                'description' => 'A short guide to shipping audits.',
            ],
            '_embedded' => [
                'author' => [['id' => 7, 'name' => 'Jane Doe', 'slug' => 'jane-doe']],
                'wp:term' => [
                    [['id' => 5, 'taxonomy' => 'category', 'slug' => 'logistics', 'name' => 'Logistics &amp; Ops']],
                ],
            ],
        ], $overrides);
    }

    /**
     * Seed the local table exactly as the importer would, from a
     * WordPress-shaped payload.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function createPost(array $overrides = []): Post
    {
        $wp = $this->wpPost($overrides);

        return Post::create([
            'wp_id' => (int) $wp['id'],
            'slug' => (string) $wp['slug'],
            'title' => WpFields::text(data_get($wp, 'title.rendered')),
            'excerpt' => WpFields::text(data_get($wp, 'excerpt.rendered')),
            'content_html' => ContentSanitizer::sanitize(data_get($wp, 'content.rendered')),
            'author' => WpFields::author($wp),
            'featured_image_url' => WpFields::featuredImageUrl($wp),
            'categories' => WpFields::categories($wp),
            'seo' => WpFields::seo($wp),
            'status' => 'publish',
            'published_at' => WpFields::iso($wp['date_gmt'] ?? null),
            'modified_at' => WpFields::iso($wp['modified_gmt'] ?? null),
        ]);
    }

    /**
     * Fake a WordPress *page* response for the /docs routes.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function fakePage(array $overrides = [], int $status = 200): void
    {
        $page = array_replace([
            'id' => 1234,
            'date_gmt' => '2026-10-01T12:00:00',
            'modified_gmt' => '2026-10-02T08:30:00',
            'slug' => 'getting-started',
            'status' => 'publish',
            'title' => ['rendered' => 'Getting started'],
            'content' => ['rendered' => '<p>Intro &amp; details.</p>'],
            'excerpt' => ['rendered' => '<p>Short &amp; sweet summary.</p>'],
        ], $overrides);

        Http::fake([
            'cms.test/*' => Http::response([$page], $status, [
                'X-WP-Total' => '1',
                'X-WP-TotalPages' => '1',
            ]),
        ]);
    }

    private function writeIndexFile(): string
    {
        $this->indexPath = storage_path('framework/testing/spa-index.html');

        File::ensureDirectoryExists(dirname($this->indexPath));

        File::put(
            $this->indexPath,
            '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            .'<title>RateGuard</title></head>'
            .'<body><div id="root"></div><script type="module" src="/assets/app.js"></script></body></html>',
        );

        config()->set('rateguard.spa.index_path', $this->indexPath);

        return $this->indexPath;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractJsonLd(string $body): array
    {
        $matched = preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $body, $matches);

        $this->assertSame(1, $matched, 'The response has no JSON-LD block.');

        return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_a_post_page_serves_the_required_head_tags_and_valid_json_ld(): void
    {
        $this->createPost();

        $response = $this->get('/blog/hello-rateguard');

        $response->assertOk();

        $body = (string) $response->getContent();

        $this->assertStringContainsString('<title>Hello &amp; RateGuard | RateGuard</title>', $body);
        $this->assertStringContainsString('<meta name="description" content="A short guide to shipping audits.">', $body);
        $this->assertStringContainsString('<meta name="robots" content="index,follow">', $body);
        $this->assertStringContainsString('<link rel="canonical" href="https://rateguard.test/blog/hello-rateguard">', $body);

        $this->assertStringContainsString('<meta property="og:type" content="article">', $body);
        $this->assertStringContainsString('<meta property="og:title" content="Hello &amp; RateGuard">', $body);
        $this->assertStringContainsString('<meta property="og:description" content="A short guide to shipping audits.">', $body);
        $this->assertStringContainsString('<meta property="og:url" content="https://rateguard.test/blog/hello-rateguard">', $body);
        $this->assertStringContainsString('<meta property="og:site_name" content="RateGuard">', $body);
        $this->assertStringContainsString('<meta property="article:published_time" content="2026-10-01T12:00:00+00:00">', $body);
        $this->assertStringContainsString('<meta property="article:modified_time" content="2026-10-02T08:30:00+00:00">', $body);

        // No featured image => the plain Twitter card.
        $this->assertStringContainsString('<meta name="twitter:card" content="summary">', $body);

        $jsonLd = $this->extractJsonLd($body);

        $this->assertSame('https://schema.org', $jsonLd['@context']);
        $this->assertSame('BlogPosting', $jsonLd['@type']);
        $this->assertSame('Hello & RateGuard', $jsonLd['headline']);
        $this->assertSame('2026-10-01T12:00:00+00:00', $jsonLd['datePublished']);
        $this->assertSame('2026-10-02T08:30:00+00:00', $jsonLd['dateModified']);
        $this->assertSame('Jane Doe', $jsonLd['author']['name']);
        $this->assertSame('https://rateguard.test/blog/hello-rateguard', $jsonLd['mainEntityOfPage']['@id']);
        $this->assertSame('RateGuard', $jsonLd['publisher']['name']);
        $this->assertArrayNotHasKey('image', $jsonLd);

        // Crawler-visible content + initial data are present.
        $this->assertStringContainsString('<div id="seo-content">', $body);
        $this->assertStringContainsString('<p>Intro &amp; details.</p>', $body);
        $this->assertStringContainsString('window.__INITIAL_CONTENT__', $body);
    }

    public function test_html_in_the_title_and_excerpt_is_escaped_and_cannot_break_out(): void
    {
        // The title/excerpt carry a literal "</script><script>alert(1)" once the
        // HTML entities WordPress emits are decoded.
        $payload = '&lt;/script&gt;&lt;script&gt;alert(1)&lt;/script&gt;';

        $this->createPost([
            'title' => ['rendered' => $payload],
            'excerpt' => ['rendered' => '<p>'.$payload.'</p>'],
            'yoast_head_json' => [],
        ]);

        $body = (string) $this->get('/blog/hello-rateguard')->assertOk()->getContent();

        $this->assertStringNotContainsString('</script><script>alert(1)', $body);
        $this->assertStringContainsString('&lt;/script&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $body);

        // The initial-data script is json_encode()d with the HEX flags.
        $this->assertStringContainsString('\u003C', $body);
    }

    public function test_the_canonical_link_ignores_a_spoofed_host_header(): void
    {
        $this->createPost();

        $body = (string) $this->call('GET', 'https://evil.example/blog/hello-rateguard')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            '<link rel="canonical" href="https://rateguard.test/blog/hello-rateguard">',
            $body,
        );
        $this->assertStringNotContainsString('evil.example', $body);
    }

    public function test_an_unknown_slug_returns_a_404_with_noindex_and_the_shell(): void
    {
        $this->createPost();

        $response = $this->get('/blog/does-not-exist');

        $response->assertNotFound();

        $body = (string) $response->getContent();

        $this->assertStringContainsString('<meta name="robots" content="noindex,nofollow">', $body);
        $this->assertStringContainsString('<div id="seo-content">', $body);
        $this->assertStringContainsString('<div id="root"></div>', $body);
    }

    public function test_a_non_latin_slug_serves_the_seo_shell(): void
    {
        // WordPress stores the slug percent-encoded; the route must still match
        // once the router has decoded it.
        $this->createPost(['slug' => '%d9%85%d8%b1%d8%ad%d8%a8%d8%a7']);

        $this->get('/blog/'.rawurlencode('مرحبا'))
            ->assertOk()
            ->assertSee('https://rateguard.test/blog/%d9%85%d8%b1%d8%ad%d8%a8%d8%a7', false);
    }

    public function test_it_returns_503_with_retry_after_when_wordpress_is_down(): void
    {
        Http::fake(['cms.test/*' => Http::response(['code' => 'service_unavailable'], 503)]);

        $response = $this->get('/docs/getting-started');

        $response->assertStatus(503)->assertHeader('Retry-After', '60');

        $this->assertStringContainsString('noindex,nofollow', (string) $response->getContent());
    }

    public function test_a_stale_copy_still_returns_200_with_the_stale_header(): void
    {
        config()->set('rateguard.content.fresh_ttl', 1);

        // One stub for the whole test, flipping to a 503 once WordPress "goes
        // down": a second Http::fake() would NOT replace the first.
        $down = false;
        Http::fake(function () use (&$down) {
            if ($down) {
                return Http::response(['code' => 'service_unavailable'], 503);
            }

            return Http::response([[
                'id' => 7,
                'slug' => 'getting-started',
                'title' => ['rendered' => 'Getting started'],
                'content' => ['rendered' => '<p>Body</p>'],
                'excerpt' => ['rendered' => '<p>Excerpt</p>'],
                'date_gmt' => '2026-10-01T12:00:00',
                'modified_gmt' => '2026-10-02T08:30:00',
            ]], 200, [
                'X-WP-Total' => '1',
                'X-WP-TotalPages' => '1',
            ]);
        });

        $this->get('/docs/getting-started')->assertOk()->assertHeaderMissing('X-Content-Stale');

        $down = true;
        $this->travel(3)->seconds();

        $this->get('/docs/getting-started')
            ->assertOk()
            ->assertHeader('X-Content-Stale', 'true');
    }

    public function test_the_second_request_is_served_from_cache_without_calling_wordpress(): void
    {
        $this->fakePage();

        $this->get('/docs/getting-started')->assertOk();
        $this->get('/docs/getting-started')->assertOk();

        Http::assertSentCount(1);
    }

    public function test_an_etag_yields_a_304_on_repeat_requests(): void
    {
        $this->createPost();

        $first = $this->get('/blog/hello-rateguard')->assertOk();
        $etag = $first->headers->get('ETag');

        $this->assertNotNull($etag);

        $this->withHeaders(['If-None-Match' => $etag])
            ->get('/blog/hello-rateguard')
            ->assertStatus(304);
    }

    public function test_the_dev_fallback_shell_is_used_when_there_is_no_build(): void
    {
        $this->createPost();

        $body = (string) $this->get('/blog/hello-rateguard')->assertOk()->getContent();

        $this->assertStringContainsString('<div id="root"></div>', $body);
        $this->assertStringContainsString('http://localhost:5173/src/main.tsx', $body);
        $this->assertStringContainsString('<div id="seo-content">', $body);
    }

    public function test_the_built_index_html_is_used_with_the_head_injected_once(): void
    {
        $this->writeIndexFile();

        $this->createPost();

        $body = (string) $this->get('/blog/hello-rateguard')->assertOk()->getContent();

        // Not the dev shell.
        $this->assertStringNotContainsString('http://localhost:5173/src/main.tsx', $body);

        // Head injected exactly once, and the Vite <title> placeholder is gone:
        // a served document must carry exactly one <title>.
        $this->assertSame(1, substr_count($body, '<title>'));
        $this->assertStringNotContainsString('<title>RateGuard</title>', $body);
        $this->assertSame(1, substr_count($body, '<title>Hello &amp; RateGuard | RateGuard</title>'));
        $this->assertSame(1, substr_count($body, '<meta property="og:type" content="article">'));

        // The app script is untouched, and the content fragment is present.
        $this->assertStringContainsString('<script type="module" src="/assets/app.js"></script>', $body);
        $this->assertStringContainsString('<div id="seo-content">', $body);
        $this->assertStringContainsString('<div id="root"></div>', $body);
    }

    public function test_the_blog_index_lists_posts_without_article_json_ld(): void
    {
        $this->createPost();

        $body = (string) $this->get('/blog')->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="https://rateguard.test/blog">', $body);
        $this->assertStringContainsString('<meta property="og:type" content="website">', $body);
        $this->assertStringContainsString('<meta name="robots" content="index,follow">', $body);
        $this->assertStringContainsString('https://rateguard.test/blog/hello-rateguard', $body);
        $this->assertStringNotContainsString('application/ld+json', $body);
    }

    public function test_a_docs_page_uses_the_tech_article_json_ld(): void
    {
        $this->fakePage();

        $body = (string) $this->get('/docs/getting-started')->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="https://rateguard.test/docs/getting-started">', $body);
        $this->assertStringContainsString('<meta property="og:type" content="website">', $body);
        $this->assertStringNotContainsString('article:published_time', $body);

        $jsonLd = $this->extractJsonLd($body);

        $this->assertSame('TechArticle', $jsonLd['@type']);
        $this->assertSame('Getting started', $jsonLd['headline']);
    }
}
