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
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        $path = base_path('tests/Fixtures/wp-post.json');

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    private function repository(): ContentRepository
    {
        return app(ContentRepository::class);
    }

    private function fakePosts(): void
    {
        Http::fake([
            'cms.test/*' => Http::response([$this->fixture()], 200, [
                'X-WP-Total' => '1',
                'X-WP-TotalPages' => '1',
            ]),
        ]);
    }

    public function test_a_second_request_is_served_from_cache_without_hitting_wordpress(): void
    {
        $this->fakePosts();

        $repository = $this->repository();

        $first = $repository->posts(1, 10);
        $second = $repository->posts(1, 10);

        $this->assertSame('hello-rateguard', $first->items[0]->slug);
        $this->assertSame('hello-rateguard', $second->items[0]->slug);

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

            return Http::response([$this->fixture()], 200, [
                'X-WP-Total' => '1',
                'X-WP-TotalPages' => '1',
            ]);
        });

        // Prime the cache with a successful read.
        $repository->posts(1, 10);
        $this->assertFalse($repository->isStale());

        // WordPress goes down and the fresh window lapses.
        $down = true;
        $this->travel(3)->seconds();

        $result = $repository->posts(1, 10);

        $this->assertSame('hello-rateguard', $result->items[0]->slug);
        $this->assertTrue($repository->isStale());

        // 1 successful prime + 2 retry attempts against the failing service.
        Http::assertSentCount(3);
    }

    public function test_it_throws_unavailable_when_wordpress_fails_and_there_is_no_copy(): void
    {
        config()->set('rateguard.content.fresh_ttl', 1);

        Http::fake(['cms.test/*' => Http::response(['code' => 'service_unavailable'], 503)]);

        $this->expectException(ContentUnavailableException::class);

        $this->repository()->posts(1, 10);
    }

    public function test_the_circuit_breaker_opens_after_five_consecutive_failures(): void
    {
        Http::fake(['cms.test/*' => Http::response(['code' => 'service_unavailable'], 503)]);

        $repository = $this->repository();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $repository->posts(1, 10);
                $this->fail('Expected ContentUnavailableException.');
            } catch (ContentUnavailableException) {
                // expected
            }
        }

        // Five failures have now opened the circuit.
        try {
            $repository->posts(1, 10);
            $this->fail('Expected ContentUnavailableException.');
        } catch (ContentUnavailableException) {
            // expected
        }

        // 5 failing reads * 2 retry attempts each = 10; the 6th read skipped WP.
        Http::assertSentCount(10);
    }
}
