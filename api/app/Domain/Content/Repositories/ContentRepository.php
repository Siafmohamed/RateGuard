<?php

namespace App\Domain\Content\Repositories;

use App\Domain\Content\Contracts\ContentClientInterface;
use App\Domain\Content\Data\CategoryData;
use App\Domain\Content\Data\PaginatedContent;
use App\Domain\Content\Data\PaginatedPages;
use App\Domain\Content\Data\PageData;
use App\Domain\Content\Data\PostData;
use App\Domain\Content\Exceptions\ContentUnavailableException;
use Closure;
use Illuminate\Cache\Repository;
use Illuminate\Cache\TaggableStore;
use Illuminate\Support\Facades\Cache;

/**
 * Adds caching, a stale fallback and a circuit breaker on top of the CMS
 * client, so a slow or down WordPress never turns into a slow or failing API.
 *
 * Posts are the exception: they are imported into the local `posts` table
 * (SyncPostsCommand) and served from there by PostRepository, so only pages
 * and categories still reach WordPress.
 *
 *  - Reads go through Cache::flexible (stale-while-revalidate): a value is
 *    served fresh for `fresh_ttl` and revalidated in the background after that.
 *  - Every successful fetch also writes a long-lived copy (stale_ttl). When
 *    WordPress is unreachable that copy is served instead, flagged via
 *    isStale() so the response can carry X-Content-Stale: true.
 *  - After `circuit_threshold` consecutive failures the client is skipped for
 *    `circuit_cooldown` seconds.
 *
 * Only arrays are cached (never DTO objects): the app's cache forbids
 * unserializing arbitrary classes.
 */
class ContentRepository
{
    private const VERSION = 'v1';

    private const TAG = 'content';

    private const FAILURES_KEY = 'content:circuit:failures';

    private const OPEN_UNTIL_KEY = 'content:circuit:open_until';

    private bool $stale = false;

    public function __construct(
        private readonly ContentClientInterface $client,
        private readonly PostRepository $postRepository,
    ) {}

    public function posts(int $page, int $perPage, ?string $category = null): PaginatedContent
    {
        // Posts are imported into Laravel (see SyncPostsCommand), so they are
        // read from the local table: there is no upstream call to cache and
        // nothing that can go stale. Pages and categories still come from
        // WordPress through the client below.
        $this->stale = false;

        return $this->postRepository->paginate($page, $perPage, $category);
    }

    public function post(string $slug): PostData
    {
        $this->stale = false;

        return $this->postRepository->find($slug);
    }

    public function pages(int $page, int $perPage): PaginatedPages
    {
        return $this->remember(
            "pages:p{$page}:pp{$perPage}",
            fn () => $this->client->pages($page, $perPage),
            fn (PaginatedPages $value) => $value->toArray(),
            fn (array $value) => PaginatedPages::fromArray($value),
        );
    }

    public function page(string $slug): PageData
    {
        return $this->remember(
            "page:{$slug}",
            fn () => $this->client->page($slug),
            fn (PageData $value) => $value->toArray(),
            fn (array $value) => PageData::fromArray($value),
        );
    }

    /**
     * @return list<CategoryData>
     */
    public function categories(): array
    {
        return $this->remember(
            'categories',
            fn () => $this->client->categories(),
            fn (array $value) => array_map(fn (CategoryData $item) => $item->toArray(), $value),
            fn (array $value) => array_map(CategoryData::fromArray(...), $value),
        );
    }

    /**
     * Whether the last read was served from the stale fallback copy.
     */
    public function isStale(): bool
    {
        return $this->stale;
    }

    /**
     * @template T
     *
     * @param  Closure():T  $fetch
     * @param  Closure(T):array<string, mixed>  $encode
     * @param  Closure(array<string, mixed>):T  $decode
     * @return T
     */
    private function remember(string $suffix, Closure $fetch, Closure $encode, Closure $decode): mixed
    {
        $this->stale = false;

        $freshKey = self::VERSION.':'.$suffix;
        $staleKey = self::VERSION.':stale:'.$suffix;

        // Circuit open: do not touch WordPress at all.
        if ($this->circuitIsOpen()) {
            $stale = $this->readStale($staleKey);

            if ($stale !== null) {
                return $this->serveStale($decode, $stale);
            }

            throw ContentUnavailableException::forRequest($suffix);
        }

        try {
            $payload = $this->cache()->flexible(
                $freshKey,
                [$this->freshTtl(), $this->freshTtl() * 2],
                function () use ($fetch, $encode, $staleKey): array {
                    $data = $encode($fetch());

                    $this->recordSuccess();
                    $this->cache()->put($staleKey, $data, $this->staleTtl());

                    return $data;
                },
            );
        } catch (ContentUnavailableException $exception) {
            $this->recordFailure();

            $stale = $this->readStale($staleKey);

            if ($stale !== null) {
                return $this->serveStale($decode, $stale);
            }

            throw $exception;
        }

        return $decode($payload);
    }

    /**
     * @param  Closure(array<string, mixed>):mixed  $decode
     * @param  array<string, mixed>  $payload
     */
    private function serveStale(Closure $decode, array $payload): mixed
    {
        $this->stale = true;

        return $decode($payload);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readStale(string $staleKey): ?array
    {
        $value = $this->cache()->get($staleKey);

        return is_array($value) ? $value : null;
    }

    private function circuitIsOpen(): bool
    {
        return (int) $this->cache()->get(self::OPEN_UNTIL_KEY, 0) > now()->getTimestamp();
    }

    private function recordFailure(): void
    {
        $failures = (int) $this->cache()->get(self::FAILURES_KEY, 0) + 1;

        $this->cache()->put(self::FAILURES_KEY, $failures, $this->circuitCooldown());

        if ($failures >= $this->circuitThreshold()) {
            $this->cache()->put(
                self::OPEN_UNTIL_KEY,
                now()->addSeconds($this->circuitCooldown())->getTimestamp(),
                $this->circuitCooldown(),
            );

            $this->cache()->forget(self::FAILURES_KEY);
        }
    }

    private function recordSuccess(): void
    {
        $this->cache()->forget(self::FAILURES_KEY);
    }

    private function freshTtl(): int
    {
        return (int) config('rateguard.content.fresh_ttl');
    }

    private function staleTtl(): int
    {
        return (int) config('rateguard.content.stale_ttl');
    }

    private function circuitThreshold(): int
    {
        return (int) config('rateguard.content.circuit_threshold');
    }

    private function circuitCooldown(): int
    {
        return (int) config('rateguard.content.circuit_cooldown');
    }

    /**
     * The content cache, tagged under "content" where the store supports it
     * (Redis) so the whole group can be purged at once; otherwise the plain
     * store is used (e.g. the array driver in tests).
     */
    private function cache(): Repository
    {
        $store = Cache::store();

        return $store->getStore() instanceof TaggableStore
            ? Cache::tags([self::TAG])
            : $store;
    }
}
