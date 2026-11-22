<?php

namespace App\Domain\Invoices\Support;

/**
 * The canonical CSV columns accepted by the invoice parser.
 */
final class InvoiceCsvHeader
{
    public const COLUMNS = [
        'tracking_number',
        'charge_type',
        'description',
        'billed_weight_kg',
        'amount',
    ];

    /**
     * @param  list<string|null>  $cells
     * @return list<string>
     */
    public static function normalize(array $cells): array
    {
        if (isset($cells[0])) {
            $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $cells[0]);
        }

        return array_map(fn ($cell) => strtolower(trim((string) $cell)), $cells);
    }

    /**
     * @param  list<string|null>  $cells
     */
    public static function matches(array $cells): bool
    {
        return self::normalize($cells) === self::COLUMNS;
    }

    public static function expected(): string
    {
        return implode(',', self::COLUMNS);
    }
}
