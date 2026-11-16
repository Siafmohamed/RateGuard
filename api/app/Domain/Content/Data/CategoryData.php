<?php

namespace App\Domain\Content\Data;

use App\Domain\Content\Support\WpFields;

/**
 * A WordPress category term.
 */
final readonly class CategoryData
{
    public function __construct(
        public int $id,
        public string $slug,
        public string $name,
        public string $description,
        public int $count,
    ) {}

    /**
     * @param  array<string, mixed>  $wp
     */
    public static function fromWp(array $wp): self
    {
        return new self(
            id: (int) ($wp['id'] ?? 0),
            slug: (string) ($wp['slug'] ?? ''),
            name: WpFields::text($wp['name'] ?? ''),
            description: WpFields::text($wp['description'] ?? ''),
            count: (int) ($wp['count'] ?? 0),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'count' => $this->count,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) ($data['id'] ?? 0),
            slug: (string) ($data['slug'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            description: (string) ($data['description'] ?? ''),
            count: (int) ($data['count'] ?? 0),
        );
    }
}
