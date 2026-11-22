<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Exceptions\ContentUnavailableException;
use App\Domain\Content\Repositories\ContentRepository;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The repository is the resilience layer: it caches successful reads, serves a
 * long-lived "stale" copy when WordPress is down, and trips a circuit breaker
 * so a sustained outage never turns into a stampede of failing requests.
 *
 * Posts bypass this layer entirely - they are imported into the local `posts`
 * table and served by PostRepository - so these tests exercise it through
 * pages, which still go out to WordPress.
 */
class ContentRepositoryCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('rateguard.content.base_url', 'http://cms.test');
        config()->set('rateguard.content.user', 'apiuser');
        config()->set('rateguard.content.app_password', 'app pass 1234');
        config()->set('rateguard.content.retries', 2);
        config()->set('rateguard.content.retry_delay_ms', 0);
        config()->set('rateguard.content.stale_ttl', 86400);
        config()->set('rateguard.content.circuit_threshold', 5);
        config()->set('rateguard.content.circuit_cooldown', 30);

        Http::preventStrayRequests();
    }

    /**
     * A WordPress page payload (posts are no longer fetched over HTTP).
     *
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

    private function repository(): ContentRepository
    {
        return app(ContentRepository::class);
    }

    private function fakePages(): void
    {
        Http::fake([
            'cms.test/*' => Http::response([$this->pageFixture()], 200, [
                'X-WP-Total' => '1',
                'X-WP-TotalPages' => '1',
            ]),
        ]);
    }

    public function test_a_second_request_is_served_from_cache_without_hitting_wordpress(): void
    {
        $this->fakePages();

        $repository = $this->repository();

        $first = $repository->pages(1, 10);
        $second = $repository->pages(1, 10);

        $this->assertSame('about-us', $first->items[0]->slug);
        $this->assertSame('about-us', $second->items[0]->slug);

        // The second read never reached WordPress.
        Http::assertSentCount(1);
    }

    public function test_the_explicit_stale_copy_is_served_when_wordpress_fails(): void
    {
        config()->set('rateguard.content.fresh_ttl', 1);

        $repository = $this->repository();

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

        // Prime the cache with a successful read.
        $repository->pages(1, 10);
        $this->assertFalse($repository->isStale());

        // WordPress goes down and the fresh window lapses.
        $down = true;
        $this->travel(3)->seconds();

        $result = $repository->pages(1, 10);

        $this->assertSame('about-us', $result->items[0]->slug);
        $this->assertTrue($repository->isStale());

        // 1 successful prime + 2 retry attempts against the failing service.
        Http::assertSentCount(3);
    }

    public function test_it_throws_unavailable_when_wordpress_fails_and_there_is_no_copy(): void
    {
        config()->set('rateguard.content.fresh_ttl', 1);

        Http::fake(['cms.test/*' => Http::response(['code' => 'service_unavailable'], 503)]);

        $this->expectException(ContentUnavailableException::class);

        $this->repository()->pages(1, 10);
    }

    public function test_the_circuit_breaker_opens_after_five_consecutive_failures(): void
    {
        Http::fake(['cms.test/*' => Http::response(['code' => 'service_unavailable'], 503)]);

        $repository = $this->repository();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $repository->pages(1, 10);
                $this->fail('Expected ContentUnavailableException.');
            } catch (ContentUnavailableException) {
                // expected
            }
        }

        // Five failures have now opened the circuit.
        try {
            $repository->pages(1, 10);
            $this->fail('Expected ContentUnavailableException.');
        } catch (ContentUnavailableException) {
            // expected
        }

        // 5 failing reads * 2 retry attempts each = 10; the 6th read skipped WP.
        Http::assertSentCount(10);
    }
}
