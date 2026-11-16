<?php

namespace Tests\Feature\Seo;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * /sitemap.xml and /robots.txt, built from the cached content lists.
 */
class SitemapRobotsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.url', 'https://rateguard.test');

        config()->set('rateguard.content.base_url', 'http://cms.test');
        config()->set('rateguard.content.user', 'apiuser');
        config()->set('rateguard.content.app_password', 'super-secret-app-password');
        config()->set('rateguard.content.retries', 2);
        config()->set('rateguard.content.retry_delay_ms', 0);

        config()->set('rateguard.spa.index_path', base_path('tests/missing-spa-index.html'));

        Http::preventStrayRequests();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function wpItem(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 1234,
            'date_gmt' => '2026-10-01T12:00:00',
            'modified_gmt' => '2026-10-02T08:30:00',
            'slug' => 'hello-rateguard',
            'status' => 'publish',
            'title' => ['rendered' => 'Hello RateGuard'],
            'content' => ['rendered' => '<p>Body</p>'],
            'excerpt' => ['rendered' => '<p>Excerpt</p>'],
        ], $overrides);
    }

    /**
     * Posts and pages are stubbed separately, keyed off the rest_route that
     * WordPressContentClient always sends.
     */
    private function fakeLists(): void
    {
        Http::fake(function (Request $request) {
            $url = urldecode($request->url());

            $item = str_contains($url, '/wp/v2/pages')
                ? $this->wpItem(['slug' => 'getting-started', 'title' => ['rendered' => 'Getting started']])
                : $this->wpItem();

            return Http::response([$item], 200, [
                'X-WP-Total' => '1',
                'X-WP-TotalPages' => '1',
            ]);
        });
    }

    public function test_the_sitemap_lists_posts_and_docs_as_valid_xml(): void
    {
        $this->fakeLists();

        $response = $this->get('/sitemap.xml')->assertOk();

        $this->assertStringContainsString('xml', (string) $response->headers->get('Content-Type'));

        $body = (string) $response->getContent();

        $this->assertNotFalse(simplexml_load_string($body), 'The sitemap is not valid XML.');

        $this->assertStringContainsString('<loc>https://rateguard.test/blog/hello-rateguard</loc>', $body);
        $this->assertStringContainsString('<loc>https://rateguard.test/docs/getting-started</loc>', $body);
        $this->assertStringContainsString('<lastmod>2026-10-02T08:30:00+00:00</lastmod>', $body);
    }

    public function test_the_sitemap_is_cached_for_an_hour(): void
    {
        $this->fakeLists();

        $this->get('/sitemap.xml')->assertOk();
        $this->get('/sitemap.xml')->assertOk();

        // Exactly one posts call and one pages call, on the first request only.
        Http::assertSentCount(2);
    }

    public function test_the_sitemap_returns_503_when_wordpress_is_down(): void
    {
        Http::fake(['cms.test/*' => Http::response(['code' => 'service_unavailable'], 503)]);

        $this->get('/sitemap.xml')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '60');
    }

    public function test_robots_txt_allows_all_disallows_the_api_and_points_at_the_sitemap(): void
    {
        $body = (string) $this->get('/robots.txt')->assertOk()->getContent();

        $this->assertStringContainsString('User-agent: *', $body);
        $this->assertStringContainsString('Allow: /', $body);
        $this->assertStringContainsString('Disallow: /api/', $body);
        $this->assertStringContainsString('Sitemap: https://rateguard.test/sitemap.xml', $body);
    }
}
