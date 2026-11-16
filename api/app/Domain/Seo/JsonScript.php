<?php

namespace App\Domain\Seo;

use Illuminate\Support\HtmlString;

/**
 * Encodes a value as JSON that is safe to drop inside an inline <script>.
 *
 * The HEX flags mean the encoded string can contain no raw <, >, &, ' or " in
 * its *content*, so a value like "</script><script>" can never break out of the
 * element. Note the structural quotes (and the surrounding braces) of the JSON
 * itself are still literal: re-escaping the whole string with htmlspecialchars
 * would corrupt it, which is why the result is returned as an HtmlString so
 * Blade's {{ }} writes it verbatim instead of entity-encoding it.
 */
final class JsonScript
{
    private const FLAGS = JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
        | JSON_UNESCAPED_UNICODE;

    public static function from(mixed $value): HtmlString
    {
        return new HtmlString((string) json_encode($value, self::FLAGS));
    }
}
