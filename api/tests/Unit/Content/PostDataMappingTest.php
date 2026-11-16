<?php

namespace Tests\Unit\Content;

use App\Domain\Content\Data\PostData;
use Tests\TestCase;

/**
 * The DTO is the boundary between the raw WordPress payload and the rest of the
 * app: it must decode entities, strip markup from the title, drop the featured
 * image when missing and sanitize the body - and round-trip through the array
 * form the cache stores.
 */
class PostDataMappingTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        $path = base_path('tests/Fixtures/wp-post.json');

        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_it_maps_a_wordpress_post_to_the_stable_shape(): void
    {
        $post = PostData::fromWp($this->fixture());

        $this->assertSame(1234, $post->id);
        $this->assertSame('hello-rateguard', $post->slug);

        // HTML is stripped from the title and entities are decoded.
        $this->assertSame('Hello & "RateGuard" — Guide', $post->title);
        $this->assertSame('Short & sweet summary.', $post->excerpt);

        $this->assertSame('Jane Doe', $post->author);
        $this->assertSame('2026-10-01T12:00:00+00:00', $post->publishedAt);
        $this->assertSame('2026-10-02T08:30:00+00:00', $post->modifiedAt);

        // Only category terms are kept (the post_tag group is ignored).
        $this->assertSame(
            [['id' => 5, 'slug' => 'logistics', 'name' => 'Logistics & Ops']],
            $post->categories,
        );

        $this->assertSame(
            ['title' => 'Hello & RateGuard', 'description' => 'A short guide to shipping audits.'],
            $post->seo,
        );
    }

    public function test_a_missing_featured_image_maps_to_null(): void
    {
        $this->assertNull(PostData::fromWp($this->fixture())->featuredImageUrl);
    }

    public function test_the_body_is_sanitized_before_it_leaves_the_dto(): void
    {
        $post = PostData::fromWp($this->fixture());

        $this->assertStringContainsString('<strong>bold</strong>', $post->contentHtml);
        $this->assertStringContainsString('href="https://example.com/docs"', $post->contentHtml);

        $this->assertStringNotContainsString('<script', $post->contentHtml);
        $this->assertStringNotContainsString('onclick', $post->contentHtml);
        $this->assertStringNotContainsString('onerror', $post->contentHtml);
        $this->assertStringNotContainsString('<iframe', $post->contentHtml);
        $this->assertStringNotContainsString('javascript:', $post->contentHtml);
    }

    public function test_it_round_trips_through_the_cache_array_form(): void
    {
        $post = PostData::fromWp($this->fixture());

        $this->assertEquals($post, PostData::fromArray($post->toArray()));
    }
}
