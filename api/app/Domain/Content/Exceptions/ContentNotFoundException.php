<?php

namespace App\Domain\Content\Exceptions;

use RuntimeException;

/**
 * Thrown when WordPress answers successfully but has no item for a slug.
 *
 * This is a "not found" - the caller maps it to a 404, never a 500.
 */
class ContentNotFoundException extends RuntimeException
{
    public static function forSlug(string $slug): self
    {
        return new self("No published content found for slug [{$slug}].");
    }
}
