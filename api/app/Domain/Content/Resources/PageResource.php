<?php

namespace App\Domain\Content\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Stable wire shape for a page.
 *
 * @mixin \App\Domain\Content\Data\PageData
 */
class PageResource extends JsonResource
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
            'seo' => $this->seo,
        ];
    }
}
