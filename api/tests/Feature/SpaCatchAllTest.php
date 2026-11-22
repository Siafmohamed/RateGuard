<?php

namespace Tests\Feature;

use App\Domain\Content\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The SPA catch-all in routes/web.php.
 *
 * Every GET that is not an API call, a Phase 5 SEO route, a webhook or a static
 * asset must return the SPA shell with a 200 so a hard refresh on a client
 * route gets a real document and React can boot over it. The shell is always
 * noindex. The reserved prefixes must keep resolving as themselves.
 */
class SpaCatchAllTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.url', 'https://rateguard.test');

        config()->set('rateguard.content.base_url', 'http://cms.test');
        config()->set('rateguard.content.user', 'apiuser');
        config()->set('rateguard.content.app_password', 'super-secret-app-password');
        config()->set('rateguard.content.retries', 1);
        config()->set('rateguard.content.retry_delay_ms', 0);

        // Deterministic "no build yet" -> the dev-fallback shell, which always
        // contains <div id="root"></div>.
        config()->set('rateguard.spa.index_path', base_path('tests/missing-spa-index.html'));

        Http::preventStrayRequests();
        $this->fakeLists();
    }

    /**
     * A published post as the importer would have stored it (posts are no
     * longer fetched over HTTP).
     */
    private function createPost(): Post
    {
        return Post::create([
            'wp_id' => 1234,
            'slug' => 'hello-rateguard',
            'title' => 'Hello RateGuard',
            'excerpt' => 'Excerpt',
            'content_html' => '<p>Body</p>',
            'author' => 'Jane Doe',
            'featured_image_url' => null,
            'categories' => [],
            'seo' => null,
            'status' => 'publish',
            'published_at' => '2026-10-01T12:00:00',
            'modified_at' => '2026-10-02T08:30:00',
        ]);
    }

    /**
     * WordPress *pages* (docs) are still fetched over HTTP.
     */
    private function fakeLists(): void
    {
        Http::fake(fn (Request $request) => Http::response([[
            'id' => 1234,
            'date_gmt' => '2026-10-01T12:00:00',
            'modified_gmt' => '2026-10-02T08:30:00',
            'slug' => 'getting-started',
            'status' => 'publish',
            'title' => ['rendered' => 'Getting started'],
            'content' => ['rendered' => '<p>Body</p>'],
            'excerpt' => ['rendered' => '<p>Excerpt</p>'],
        ]], 200, [
            'X-WP-Total' => '1',
            'X-WP-TotalPages' => '1',
        ]));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function clientRoutes(): array
    {
        return [
            'home' => ['/'],
            'login' => ['/login'],
            'register' => ['/register'],
            'invoice list' => ['/app/invoices'],
            'invoice detail' => ['/app/invoices/12'],
            'billing' => ['/app/billing'],
            'unknown route' => ['/definitely-not-a-real-page'],
        ];
    }

    #[DataProvider('clientRoutes')]
    public function test_client_routes_return_the_noindex_spa_shell(string $path): void
    {
        $body = (string) $this->get($path)->assertOk()->getContent();

        $this->assertStringContainsString('<meta name="robots" content="noindex,nofollow">', $body);
        $this->assertStringContainsString('<div id="root"></div>', $body);
    }

    public function test_the_api_is_not_swallowed_by_the_catch_all(): void
    {
        $response = $this->get('/api/v1/health');

        $this->assertStringContainsString(
            'application/json',
            (string) $response->headers->get('Content-Type'),
        );

        $this->assertStringContainsString('"status"', (string) $response->getContent());
        $this->assertStringNotContainsString('<div id="root">', (string) $response->getContent());
    }

    public function test_the_blog_route_still_serves_the_indexable_seo_shell(): void
    {
        $this->createPost();

        $body = (string) $this->get('/blog')->assertOk()->getContent();

        $this->assertStringContainsString('<meta name="robots" content="index,follow">', $body);
        $this->assertStringNotContainsString('noindex,nofollow', $body);
        $this->assertStringContainsString('<div id="seo-content">', $body);
    }

    public function test_sitemap_and_robots_are_not_swallowed(): void
    {
        $this->createPost();

        $robots = $this->get('/robots.txt')->assertOk();

        $this->assertStringContainsString(
            'text/plain',
            (string) $robots->headers->get('Content-Type'),
        );

        $this->assertStringContainsString('Disallow: /api/', (string) $robots->getContent());

        $sitemap = $this->get('/sitemap.xml')->assertOk();

        $this->assertStringContainsString(
            'xml',
            (string) $sitemap->headers->get('Content-Type'),
        );

        $this->assertStringContainsString(
            '<loc>https://rateguard.test/blog/hello-rateguard</loc>',
            (string) $sitemap->getContent(),
        );
    }
}
