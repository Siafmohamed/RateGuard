<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Contracts\ContentClientInterface;
use App\Domain\Content\Exceptions\ContentNotFoundException;
use App\Domain\Content\Exceptions\ContentUnavailableException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The client is the only place that speaks to WordPress. Its job is to move the
 * failure modes into two signals - "not found" and "unavailable" - while never
 * letting raw WP JSON out, and to retry only what is worth retrying.
 */
class WordPressContentClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('rateguard.content.base_url', 'http://cms.test');
        config()->set('rateguard.content.user', 'apiuser');
        config()->set('rateguard.content.app_password', 'app pass 1234');
        config()->set('rateguard.content.retries', 2);
        config()->set('rateguard.content.retry_delay_ms', 0);

        Http::preventStrayRequests();
    }

    private function client(): ContentClientInterface
    {
        return app(ContentClientInterface::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        $path = base_path('tests/Fixtures/wp-post.json');

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_posts_returns_mapped_items_and_reads_pagination_from_headers(): void
    {
        Http::fake([
            'cms.test/*' => Http::response([$this->fixture()], 200, [
                'X-WP-Total' => '5',
                'X-WP-TotalPages' => '3',
            ]),
        ]);

        $result = $this->client()->posts(1, 10);

        $this->assertCount(1, $result->items);
        $this->assertSame(5, $result->total);
        $this->assertSame(3, $result->totalPages);
        $this->assertSame(1, $result->page);
        $this->assertSame(10, $result->perPage);
        $this->assertSame('hello-rateguard', $result->items[0]->slug);
    }

    public function test_it_uses_the_configured_base_url_form_and_always_asks_for_published_content(): void
    {
        Http::fake(['cms.test/*' => Http::response([], 200)]);

        $this->client()->posts(1, 10);

        Http::assertSent(function (Request $request) {
            $url = urldecode($request->url());

            return str_starts_with($request->url(), 'http://cms.test/index.php')
                && str_contains($url, 'rest_route=/wp/v2/posts')
                && str_contains($url, 'status=publish');
        });
    }

    public function test_it_sends_basic_auth_from_configuration(): void
    {
        Http::fake(['cms.test/*' => Http::response([], 200)]);

        $this->client()->posts(1, 10);

        $expected = 'Basic '.base64_encode('apiuser:app pass 1234');

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', $expected));
    }

    public function test_a_4xx_is_not_retried(): void
    {
        Http::fake(['cms.test/*' => Http::response(['code' => 'rest_forbidden'], 400)]);

        try {
            $this->client()->categories();
            $this->fail('Expected ContentUnavailableException.');
        } catch (ContentUnavailableException) {
            // expected
        }

        Http::assertSentCount(1);
    }

    public function test_a_5xx_is_retried_then_throws_unavailable(): void
    {
        Http::fake(['cms.test/*' => Http::response(['code' => 'service_unavailable'], 503)]);

        try {
            $this->client()->posts(1, 10);
            $this->fail('Expected ContentUnavailableException.');
        } catch (ContentUnavailableException) {
            // expected
        }

        // retries = 2 => two attempts, both to WordPress.
        Http::assertSentCount(2);
    }

    public function test_an_empty_result_for_a_slug_throws_not_found(): void
    {
        Http::fake(['cms.test/*' => Http::response([], 200)]);

        $this->expectException(ContentNotFoundException::class);

        $this->client()->post('missing-post');
    }

    public function test_a_404_for_a_slug_throws_not_found(): void
    {
        Http::fake(['cms.test/*' => Http::response(['code' => 'rest_post_invalid_id'], 404)]);

        $this->expectException(ContentNotFoundException::class);

        $this->client()->page('missing-page');
    }

    public function test_categories_returns_mapped_terms(): void
    {
        Http::fake(['cms.test/*' => Http::response([
            ['id' => 5, 'slug' => 'logistics', 'name' => 'Logistics &amp; Ops', 'description' => 'Ops', 'count' => 3],
        ], 200)]);

        $categories = $this->client()->categories();

        $this->assertCount(1, $categories);
        $this->assertSame('Logistics & Ops', $categories[0]->name);
        $this->assertSame('logistics', $categories[0]->slug);
    }
}
