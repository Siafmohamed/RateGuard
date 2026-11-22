<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The public content contract React depends on. These routes are intentionally
 * unauthenticated and unscoped, and every failure mode is an explicit status:
 * 404 for a missing slug, 422 for bad input, 503 when WordPress is unreachable.
 *
 * Posts are imported into the local `posts` table (SyncPostsCommand) and served
 * from there, so they no longer touch WordPress at request time - only pages and
 * categories still fan out to the CMS.
 */
class ContentEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('rateguard.content.base_url', 'http://cms.test');
        config()->set('rateguard.content.user', 'apiuser');
        config()->set('rateguard.content.app_password', 'super-secret-app-password');
        config()->set('rateguard.content.retries', 2);
        config()->set('rateguard.content.retry_delay_ms', 0);
        config()->set('rateguard.content.stale_ttl', 86400);

        Http::preventStrayRequests();
    }

    /**
     * @return array<string, mixed>
     */
    private function pageFixture(): array
    {
        return [
            'id' => 7,
            'slug' => 'about-us',
            'title' => ['rendered' => 'About Us'],
            'excerpt' => ['rendered' => 'About the team.'],
            'content' => ['rendered' => '<p>Hello</p>'],
            'date_gmt' => '2026-01-01T00:00:00',
            'modified_gmt' => '2026-01-01T00:00:00',
        ];
    }

    /**
     * A published post as the importer would have stored it.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function createPost(array $overrides = []): Post
    {
        return Post::create(array_merge([
            'wp_id' => 1,
            'slug' => 'hello-rateguard',
            'title' => 'Hello & "RateGuard" — Guide',
            'excerpt' => 'Short & sweet summary.',
            'content_html' => '<p>Body</p>',
            'author' => 'Jane Doe',
            'featured_image_url' => null,
            'categories' => [['id' => 5, 'slug' => 'logistics', 'name' => 'Logistics & Ops']],
            'seo' => null,
            'status' => 'publish',
            'published_at' => now()->subDay(),
            'modified_at' => now()->subDay(),
        ], $overrides));
    }

    public function test_posts_are_public_and_return_the_contract_with_pagination_meta(): void
    {
        foreach (range(1, 3) as $n) {
            $this->createPost([
                'wp_id' => $n,
                'slug' => "post-{$n}",
                'published_at' => now()->subDays($n),
            ]);
        }

        $response = $this->getJson('/api/v1/content/posts?page=2&per_page=2');

        $response->assertOk()
            ->assertJsonPath('data.0.slug', 'post-3')
            ->assertJsonPath('data.0.title', 'Hello & "RateGuard" — Guide')
            ->assertJsonPath('data.0.featured_image_url', null)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.total_pages', 2)
            ->assertJsonPath('meta.page', 2)
            ->assertJsonPath('meta.per_page', 2);

        // Cache-Control is normalised and reordered by Symfony's ResponseHeaderBag
        // (it emits "max-age=60, public"), so assert on the directives, not order.
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=60', $cacheControl);
    }

    public function test_a_single_post_is_returned_by_slug(): void
    {
        $this->createPost();

        $this->getJson('/api/v1/content/posts/hello-rateguard')
            ->assertOk()
            ->assertJsonPath('data.slug', 'hello-rateguard')
            ->assertJsonPath('data.title', 'Hello & "RateGuard" — Guide');
    }

    public function test_a_non_latin_slug_is_resolved(): void
    {
        // WordPress stores a non-Latin slug percent-encoded (lower-case hex);
        // the browser asks for it percent-encoded and the router decodes it
        // before handing the controller the value.
        $this->createPost([
            'wp_id' => 2,
            'slug' => '%d9%85%d8%b1%d8%ad%d8%a8%d8%a7',
        ]);

        $this->getJson('/api/v1/content/posts/'.rawurlencode('مرحبا'))
            ->assertOk()
            ->assertJsonPath('data.slug', '%d9%85%d8%b1%d8%ad%d8%a8%d8%a7')
            ->assertJsonPath('data.title', 'Hello & "RateGuard" — Guide');
    }

    public function test_an_unknown_slug_returns_404_not_500(): void
    {
        $this->createPost();

        $this->getJson('/api/v1/content/posts/does-not-exist')
            ->assertNotFound();
    }

    public function test_a_bad_slug_returns_422(): void
    {
        $this->getJson('/api/v1/content/posts/Not-A-Slug')
            ->assertStatus(422);
    }

    public function test_per_page_greater_than_twenty_returns_422(): void
    {
        $this->getJson('/api/v1/content/posts?per_page=50')
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    }

    public function test_it_returns_503_when_wordpress_is_unavailable_and_there_is_no_copy(): void
    {
        Http::fake(['cms.test/*' => Http::response(['code' => 'service_unavailable'], 503)]);

        $this->getJson('/api/v1/content/pages/about')
            ->assertStatus(503);
    }

    public function test_a_stale_copy_is_served_with_the_x_content_stale_header(): void
    {
        config()->set('rateguard.content.fresh_ttl', 1);

        // One stub for the whole test, flipping to a 503 once WordPress "goes
        // down": a second Http::fake() would NOT replace the first, because
        // Laravel merges the stub callbacks and the earliest match wins.
        $down = false;
        Http::fake(function () use (&$down) {
            if ($down) {
                return Http::response(['code' => 'service_unavailable'], 503);
            }

            return Http::response([$this->pageFixture()], 200, [
                'X-WP-Total' => '1',
                'X-WP-TotalPages' => '1',
            ]);
        });

        // Prime a successful read, then take WordPress down.
        $this->getJson('/api/v1/content/pages/about-us')->assertOk()->assertHeaderMissing('X-Content-Stale');

        $down = true;
        $this->travel(3)->seconds();

        $this->getJson('/api/v1/content/pages/about-us')
            ->assertOk()
            ->assertHeader('X-Content-Stale', 'true')
            ->assertJsonPath('data.slug', 'about-us');
    }

    public function test_the_response_never_leaks_credentials_or_raw_wordpress_fields(): void
    {
        $this->createPost();

        $body = $this->getJson('/api/v1/content/posts')->assertOk()->getContent();

        $this->assertStringNotContainsString('super-secret-app-password', $body);
        $this->assertStringNotContainsString('_embedded', $body);
        $this->assertStringNotContainsString('yoast_head_json', $body);
        $this->assertStringNotContainsString('"rendered"', $body);

        $this->assertStringContainsString('content_html', $body);
        $this->assertStringContainsString('featured_image_url', $body);
    }

    public function test_categories_are_public(): void
    {
        Http::fake(['cms.test/*' => Http::response([
            ['id' => 5, 'slug' => 'logistics', 'name' => 'Logistics &amp; Ops', 'description' => 'Ops', 'count' => 3],
        ], 200)]);

        $this->getJson('/api/v1/content/categories')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'logistics')
            ->assertJsonPath('data.0.name', 'Logistics & Ops');
    }
}
