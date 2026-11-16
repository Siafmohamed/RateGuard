<?php

namespace App\Domain\Content\Exceptions;

use RuntimeException;

/**
 * Thrown when WordPress cannot be reached, or answers with a 5xx after the
 * configured retries have been exhausted.
 *
 * The repository turns this into either a stale copy (200) or a 503 - it must
 * never surface as a 500.
 */
class ContentUnavailableException extends RuntimeException
{
    public static function forRequest(string $path): self
    {
        return new self("The content service could not serve [{$path}].");
    }
}
