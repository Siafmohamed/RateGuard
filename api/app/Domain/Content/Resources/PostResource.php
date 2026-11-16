<?php

namespace App\Domain\Content\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Stable wire shape for a post. React depends on this contract, not on the
 * WordPress payload, so raw WP fields can never leak through.
 *
 * @mixin \App\Domain\Content\Data\PostData
 */
class PostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'excerpt' => $this->excerpt,
            'content_html' => $this->contentHtml,
            'published_at' => $this->publishedAt,
            'modified_at' => $this->modifiedAt,
            'featured_image_url' => $this->featuredImageUrl,
            'author' => $this->author,
            'categories' => $this->categories,
            'seo' => $this->seo,
        ];
    }
}
