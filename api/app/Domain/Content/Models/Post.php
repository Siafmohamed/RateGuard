<?php

namespace App\Domain\Content\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A WordPress post imported into the Laravel database.
 *
 * The row is written by App\Console\Commands\SyncPostsCommand and read by
 * App\Domain\Content\Repositories\PostRepository. Like all content it carries
 * no organization_id: the blog is public marketing material, not tenant data.
 *
 * @property int $wp_id
 * @property string $slug
 * @property string $title
 * @property string $excerpt
 * @property string $content_html
 * @property string|null $author
 * @property string|null $featured_image_url
 * @property list<array{id: int, slug: string, name: string}>|null $categories
 * @property array{title: string, description: string}|null $seo
 * @property string $status
 * @property \Carbon\CarbonImmutable|null $published_at
 * @property \Carbon\CarbonImmutable|null $modified_at
 */
class Post extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'wp_id',
        'slug',
        'title',
        'excerpt',
        'content_html',
        'author',
        'featured_image_url',
        'categories',
        'seo',
        'status',
        'published_at',
        'modified_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'wp_id' => 'integer',
            'categories' => 'array',
            'seo' => 'array',
            'published_at' => 'immutable_datetime',
            'modified_at' => 'immutable_datetime',
        ];
    }
}
