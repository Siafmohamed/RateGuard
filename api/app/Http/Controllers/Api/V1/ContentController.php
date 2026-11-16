<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Content\Exceptions\ContentNotFoundException;
use App\Domain\Content\Exceptions\ContentUnavailableException;
use App\Domain\Content\Repositories\ContentRepository;
use App\Domain\Content\Resources\CategoryResource;
use App\Domain\Content\Resources\PageResource;
use App\Domain\Content\Resources\PostResource;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Public content gateway. The browser only ever talks to Laravel; Laravel
 * fetches from WordPress, caches it and returns this stable contract.
 *
 * These routes are intentionally public (no auth, no tenant scoping): content
 * is not tenant data.
 */
class ContentController extends Controller
{
    private const UNAVAILABLE = 'Content is temporarily unavailable.';

    public function posts(Request $request, ContentRepository $content): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'category' => ['sometimes', 'string', 'regex:/^[a-z0-9-]+$/'],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 10);

        $result = $this->attempt(
            fn () => $content->posts($page, $perPage, $validated['category'] ?? null),
        );

        $response = PostResource::collection($result->items)
            ->additional(['meta' => [
                'total' => $result->total,
                'total_pages' => $result->totalPages,
                'page' => $result->page,
                'per_page' => $result->perPage,
            ]])
            ->response();

        return $this->respond($response, $content);
    }

    public function post(string $slug, ContentRepository $content): JsonResponse
    {
        $slug = $this->validSlug($slug);

        $post = $this->attempt(fn () => $content->post($slug));

        return $this->respond((new PostResource($post))->response(), $content);
    }

    public function page(string $slug, ContentRepository $content): JsonResponse
    {
        $slug = $this->validSlug($slug);

        $page = $this->attempt(fn () => $content->page($slug));

        return $this->respond((new PageResource($page))->response(), $content);
    }

    public function categories(ContentRepository $content): JsonResponse
    {
        $categories = $this->attempt(fn () => $content->categories());

        return $this->respond(CategoryResource::collection($categories)->response(), $content);
    }

    /**
     * Run a repository read, mapping its two failure modes onto HTTP: a missing
     * slug is a 404 and an outage (with no stale copy) is a 503 - never a 500.
     *
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    private function attempt(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (ContentNotFoundException $exception) {
            abort(404, $exception->getMessage());
        } catch (ContentUnavailableException) {
            abort(503, self::UNAVAILABLE);
        }
    }

    private function validSlug(string $slug): string
    {
        $validator = Validator::make(
            ['slug' => $slug],
            ['slug' => ['required', 'string', 'regex:/^[a-z0-9-]+$/']],
        );

        return $validator->validate()['slug'];
    }

    private function respond(JsonResponse $response, ContentRepository $content): JsonResponse
    {
        $response->header('Cache-Control', 'public, max-age=60');

        if ($content->isStale()) {
            $response->header('X-Content-Stale', 'true');
        }

        return $response;
    }
}
