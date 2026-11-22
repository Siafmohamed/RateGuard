<?php

namespace App\Console\Commands;

use App\Domain\Content\Models\Post;
use App\Domain\Content\Support\ContentSanitizer;
use App\Domain\Content\Support\WpFields;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Imports published WordPress posts into the local `posts` table.
 *
 * WordPress stays the place where posts are authored, but it is no longer read
 * at request time: the blog is served from Laravel's own database. This command
 * is the only thing that touches the WordPress database directly.
 *
 * The field mapping deliberately mirrors WordPressContentClient + WpFields, so
 * an imported post is byte-for-byte the same wire payload the REST gateway used
 * to produce (same ids, same sanitized HTML, same DTO shape).
 */
class SyncPostsCommand extends Command
{
    /**
     * The key of the read-only connection to the WordPress database
     * (see config/database.php).
     */
    private const CONNECTION = 'wordpress';

    private const CHUNK = 200;

    protected $signature = 'rateguard:sync-posts
                            {--prune : Also delete imported posts that are no longer published in WordPress}';

    protected $description = 'Import published WordPress posts into the local posts table.';

    public function handle(): int
    {
        $wp = DB::connection(self::CONNECTION);

        try {
            $wp->getPdo();
        } catch (\Throwable $exception) {
            $this->error('Cannot reach the WordPress database: '.$exception->getMessage());

            return self::FAILURE;
        }

        $synced = $this->import($wp);

        $this->info(sprintf('Imported %d post(s) from WordPress.', $synced));

        return self::SUCCESS;
    }

    /**
     * Upsert every published post, then optionally drop the ones that
     * disappeared from WordPress.
     */
    private function import(Connection $wp): int
    {
        $synced = 0;
        $wpIds = [];

        $wp->table('posts as p')
            ->leftJoin('users as u', 'u.ID', '=', 'p.post_author')
            ->where('p.post_type', 'post')
            ->where('p.post_status', 'publish')
            ->orderBy('p.ID')
            ->select([
                'p.ID',
                'p.post_name',
                'p.post_title',
                'p.post_excerpt',
                'p.post_content',
                'p.post_date',
                'p.post_date_gmt',
                'p.post_modified',
                'p.post_modified_gmt',
                'p.post_status',
                'u.display_name',
            ])
            ->chunk(self::CHUNK, function (Collection $rows) use ($wp, &$synced, &$wpIds): void {
                /** @var list<int> $ids */
                $ids = $rows->map(fn ($row): int => (int) $row->ID)->all();

                $categories = $this->categories($wp, $ids);
                $featured = $this->featuredImages($wp, $ids);
                $seo = $this->seo($wp, $ids);

                foreach ($rows as $row) {
                    $id = (int) $row->ID;

                    Post::query()->updateOrCreate(
                        ['wp_id' => $id],
                        [
                            'slug' => (string) $row->post_name,
                            'title' => WpFields::text($row->post_title),
                            'excerpt' => WpFields::text($row->post_excerpt),
                            'content_html' => ContentSanitizer::sanitize((string) $row->post_content),
                            'author' => $this->nullable($row->display_name),
                            'featured_image_url' => $featured[$id] ?? null,
                            'categories' => $categories[$id] ?? [],
                            'seo' => $seo[$id] ?? null,
                            'status' => (string) $row->post_status,
                            'published_at' => $this->iso($row->post_date_gmt, $row->post_date),
                            'modified_at' => $this->iso($row->post_modified_gmt, $row->post_modified),
                        ],
                    );

                    $wpIds[] = $id;
                    $synced++;
                }
            });

        if ($this->option('prune')) {
            $this->prune($wpIds);
        }

        return $synced;
    }

    /**
     * Delete imported posts that WordPress no longer publishes. Guarded on a
     * non-empty id list so an empty WordPress never wipes the table.
     *
     * @param  list<int>  $wpIds
     */
    private function prune(array $wpIds): void
    {
        if ($wpIds === []) {
            $this->warn('WordPress returned no published posts; skipping --prune.');

            return;
        }

        $deleted = Post::query()->whereNotIn('wp_id', $wpIds)->delete();

        if ($deleted > 0) {
            $this->line(sprintf('Pruned %d post(s) that are no longer published.', $deleted));
        }
    }

    /**
     * Category terms per post, shaped exactly like WpFields::categories().
     *
     * @param  list<int>  $postIds
     * @return array<int, list<array{id: int, slug: string, name: string}>>
     */
    private function categories(Connection $wp, array $postIds): array
    {
        $rows = $wp->table('term_relationships as tr')
            ->join('term_taxonomy as tt', 'tt.term_taxonomy_id', '=', 'tr.term_taxonomy_id')
            ->join('terms as t', 't.term_id', '=', 'tt.term_id')
            ->where('tt.taxonomy', 'category')
            ->whereIn('tr.object_id', $postIds)
            ->select(['tr.object_id', 't.term_id', 't.slug', 't.name'])
            ->get();

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row->object_id][] = [
                'id' => (int) $row->term_id,
                'slug' => (string) $row->slug,
                'name' => WpFields::text($row->name),
            ];
        }

        return $grouped;
    }

    /**
     * Featured image URL per post, resolved through the attachment's guid -
     * which is how WordPress stores the file location for an attachment.
     *
     * @param  list<int>  $postIds
     * @return array<int, string>
     */
    private function featuredImages(Connection $wp, array $postIds): array
    {
        $thumbnails = $wp->table('postmeta')
            ->whereIn('post_id', $postIds)
            ->where('meta_key', '_thumbnail_id')
            ->pluck('meta_value', 'post_id');

        if ($thumbnails->isEmpty()) {
            return [];
        }

        $urls = $wp->table('posts')
            ->whereIn('ID', $thumbnails->map(fn ($value): int => (int) $value)->unique()->all())
            ->pluck('guid', 'ID');

        $featured = [];

        foreach ($thumbnails as $postId => $attachmentId) {
            $url = $urls[(int) $attachmentId] ?? null;

            if (is_string($url) && $url !== '') {
                $featured[(int) $postId] = $url;
            }
        }

        return $featured;
    }

    /**
     * Yoast SEO title/description per post. Templated values (e.g.
     * "%%title%% %%sep%% %%sitename%%") are skipped: the REST gateway returned
     * them already rendered, so importing the raw template would be worse than
     * leaving the field empty and letting the SEO meta fall back to the title.
     *
     * @param  list<int>  $postIds
     * @return array<int, array{title: string, description: string}>
     */
    private function seo(Connection $wp, array $postIds): array
    {
        $rows = $wp->table('postmeta')
            ->whereIn('post_id', $postIds)
            ->whereIn('meta_key', ['_yoast_wpseo_title', '_yoast_wpseo_metadesc'])
            ->select(['post_id', 'meta_key', 'meta_value'])
            ->get();

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(int) $row->post_id][(string) $row->meta_key] = (string) $row->meta_value;
        }

        $seo = [];

        foreach ($grouped as $postId => $meta) {
            $title = $this->literal($meta['_yoast_wpseo_title'] ?? '');
            $description = $this->literal($meta['_yoast_wpseo_metadesc'] ?? '');

            if ($title === '' && $description === '') {
                continue;
            }

            $seo[$postId] = ['title' => $title, 'description' => $description];
        }

        return $seo;
    }

    /**
     * Drop a value that is still a Yoast template placeholder.
     */
    private function literal(string $value): string
    {
        return str_contains($value, '%%') ? '' : WpFields::text($value);
    }

    /**
     * WordPress keeps the UTC and the site-local timestamp side by side; the
     * API contract exposes UTC. Zero dates mean "not published yet".
     */
    private function iso(?string $gmt, ?string $local): ?string
    {
        foreach ([$gmt, $local] as $value) {
            if ($value !== null && $value !== '' && ! str_starts_with($value, '0000')) {
                return WpFields::iso($value);
            }
        }

        return null;
    }

    private function nullable(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
