<?php

namespace App\Domain\Content\Clients;

use App\Domain\Content\Contracts\ContentClientInterface;
use App\Domain\Content\Data\CategoryData;
use App\Domain\Content\Data\PaginatedContent;
use App\Domain\Content\Data\PaginatedPages;
use App\Domain\Content\Data\PageData;
use App\Domain\Content\Data\PostData;
use App\Domain\Content\Exceptions\ContentNotFoundException;
use App\Domain\Content\Exceptions\ContentUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Talks to the WordPress REST API.
 *
 * Design notes:
 *  - The base URL is taken from configuration only, never from request input,
 *    so a caller can never point the fetch at another host (no SSRF).
 *  - Requests use /index.php?rest_route=... so they work without pretty
 *    permalinks, always ask for status=publish, and embed related data (author,
 *    featured media, terms) in a single round trip.
 *  - Retries cover connection errors and 5xx only; a 4xx is returned to the
 *    caller immediately (retrying a bad request never helps).
 *  - No raw WordPress JSON leaves this class: every method returns a DTO.
 */
class WordPressContentClient implements ContentClientInterface
{
    public function __construct(
        private readonly Factory $http,
    ) {}

    public function posts(int $page, int $perPage, ?string $category = null): PaginatedContent
    {
        $categoryId = null;

        if ($category !== null && $category !== '') {
            $categoryId = $this->categoryId($category);

            // An unknown category simply has no posts.
            if ($categoryId === null) {
                return new PaginatedContent([], 0, 0, $page, $perPage);
            }
        }

        $response = $this->get('/wp/v2/posts', array_filter([
            'status' => 'publish',
            'page' => $page,
            'per_page' => $perPage,
            'categories' => $categoryId,
            '_embed' => 1,
        ], fn ($value) => $value !== null));

        $body = $this->body($response, '/wp/v2/posts');
        $items = is_array($body) ? $body : [];

        return PaginatedContent::fromWp(
            wpItems: $items,
            total: (int) ($response->header('X-WP-Total') ?: count($items)),
            totalPages: (int) ($response->header('X-WP-TotalPages') ?: ($items === [] ? 0 : 1)),
            page: $page,
            perPage: $perPage,
        );
    }

    public function post(string $slug): PostData
    {
        $item = $this->single('/wp/v2/posts', $slug, 'posts');

        return PostData::fromWp($item);
    }

    public function pages(int $page, int $perPage): PaginatedPages
    {
        $response = $this->get('/wp/v2/pages', [
            'status' => 'publish',
            'page' => $page,
            'per_page' => $perPage,
            '_embed' => 1,
        ]);

        $body = $this->body($response, '/wp/v2/pages');
        $items = is_array($body) ? $body : [];

        return PaginatedPages::fromWp(
            wpItems: $items,
            total: (int) ($response->header('X-WP-Total') ?: count($items)),
            totalPages: (int) ($response->header('X-WP-TotalPages') ?: ($items === [] ? 0 : 1)),
            page: $page,
            perPage: $perPage,
        );
    }

    public function page(string $slug): PageData
    {
        $item = $this->single('/wp/v2/pages', $slug, 'pages');

        return PageData::fromWp($item);
    }

    public function categories(): array
    {
        $response = $this->get('/wp/v2/categories', [
            'per_page' => 100,
            'hide_empty' => false,
        ]);

        $body = $this->body($response, '/wp/v2/categories');
        $items = is_array($body) ? $body : [];

        return array_map(CategoryData::fromWp(...), $items);
    }

    /**
     * Resolve a category slug to its term id, or null when it does not exist.
     */
    private function categoryId(string $slug): ?int
    {
        $response = $this->get('/wp/v2/categories', [
            'slug' => $slug,
            'per_page' => 1,
        ]);

        $body = $this->body($response, '/wp/v2/categories');

        if (! is_array($body) || ! isset($body[0]['id'])) {
            return null;
        }

        return (int) $body[0]['id'];
    }

    /**
     * Fetch the first item for a slug, or fail as "not found".
     *
     * @return array<string, mixed>
     */
    private function single(string $route, string $slug, string $label): array
    {
        $response = $this->get($route, [
            'slug' => $slug,
            'status' => 'publish',
            '_embed' => 1,
        ]);

        $body = $this->body($response, $route, $slug);

        if (! is_array($body) || $body === [] || ! isset($body[0])) {
            throw ContentNotFoundException::forSlug($slug);
        }

        return $body[0];
    }

    /**
     * Execute a GET and translate transport/5xx failures into
     * ContentUnavailableException. A 404 is returned to the caller as-is so a
     * missing slug and an outage stay distinguishable.
     */
    private function get(string $route, array $query): Response
    {
        $query = array_merge(['rest_route' => $route], $query);

        try {
            $response = $this->request()->get($this->endpoint(), $query);
        } catch (ConnectionException) {
            throw $this->unavailable($route);
        }

        // 5xx after retries exhausted => the service is down.
        if ($response->serverError()) {
            throw $this->unavailable($route, $response->status());
        }

        return $response;
    }

    /**
     * Decode the body, translating a non-2xx response into "unavailable"
     * (a 404 is only meaningful for a slug lookup, which calls single()).
     *
     * @return array<int|string, mixed>|null
     */
    private function body(Response $response, string $route, ?string $slug = null): ?array
    {
        if ($response->status() === 404 && $slug !== null) {
            throw ContentNotFoundException::forSlug($slug);
        }

        if (! $response->successful()) {
            throw $this->unavailable($route, $response->status(), $slug);
        }

        $body = $response->json();

        return is_array($body) ? $body : null;
    }

    private function request(): PendingRequest
    {
        $config = $this->config();

        return $this->http
            ->createPendingRequest()
            ->acceptJson()
            ->withBasicAuth((string) ($config['user'] ?? ''), (string) ($config['app_password'] ?? ''))
            ->timeout((int) $config['timeout'])
            ->connectTimeout((int) $config['connect_timeout'])
            ->retry(
                (int) $config['retries'],
                (int) $config['retry_delay_ms'],
                $this->shouldRetry(...),
                throw: false,
            );
    }

    /**
     * Retry connection errors and 5xx responses only - never a 4xx.
     */
    private function shouldRetry(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof RequestException) {
            return $exception->response->serverError();
        }

        return false;
    }

    private function endpoint(): string
    {
        return rtrim((string) $this->config()['base_url'], '/').'/index.php';
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return (array) config('rateguard.content');
    }

    private function unavailable(string $route, ?int $status = null, ?string $slug = null): ContentUnavailableException
    {
        // Never log the request (it carries Basic auth); only the route, the
        // slug and the status.
        Log::warning('content.fetch_failed', array_filter([
            'route' => $route,
            'slug' => $slug,
            'status' => $status,
        ], fn ($value) => $value !== null));

        return ContentUnavailableException::forRequest($route);
    }
}
