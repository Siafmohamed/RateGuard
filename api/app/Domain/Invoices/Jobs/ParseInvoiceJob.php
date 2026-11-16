<?php

namespace App\Domain\Invoices\Jobs;

use App\Domain\Invoices\Models\InvoiceLine;
use App\Domain\Invoices\Support\InvoiceStorage;
use App\Enums\InvoiceStatus;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Stage 1 — parse the raw CSV into invoice_lines.
 *
 * The file is streamed row by row through InvoiceStorage (the only place that
 * knows which disk is configured). The header is validated first and every row
 * is validated as it is read, all inside one transaction: a malformed row fails
 * the whole job and names the offending row number, so there is never a partial
 * import. Money arrives as a decimal string of major units and is converted to
 * integer cents with bcmath — never through a float.
 */
class ParseInvoiceJob extends InvoiceJob
{
    private const HEADER = [
        'tracking_number',
        'charge_type',
        'description',
        'billed_weight_kg',
        'amount',
    ];

    /**
     * Insert in chunks to keep the statement size sane on large invoices.
     */
    private const CHUNK = 500;

    protected function process(): void
    {
        $invoice = $this->invoice();
        $invoice->update([
            'status' => InvoiceStatus::Parsing->value,
            'error_message' => null,
        ]);

        // Streamed through InvoiceStorage: rows are consumed one at a time, so
        // memory stays flat regardless of how large the invoice is.
        $stream = app(InvoiceStorage::class)->stream($invoice->source_file);

        try {
            DB::transaction(function () use ($invoice, $stream) {
                // A re-parse replaces the previous lines wholesale.
                $invoice->lines()->delete();

                $now = now();
                $buffer = [];
                $header = null;
                $line = 0;

                while (($cells = fgetcsv($stream, null, ',', '"', '')) !== false) {
                    $line++;

                    // Skip blank lines (fgetcsv yields [null] for an empty one).
                    if ($cells === [null] || $cells === []) {
                        continue;
                    }

                    if ($header === null) {
                        $header = $this->assertHeader($cells);

                        continue;
                    }

                    $buffer[] = $this->mapRow($cells, $line) + [
                        'organization_id' => $invoice->organization_id,
                        'invoice_id' => $invoice->getKey(),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    // Insert in chunks to keep the statement size sane. Any bad
                    // row throws, rolling the whole transaction back, so there is
                    // never a partial import.
                    if (count($buffer) >= self::CHUNK) {
                        InvoiceLine::insert($buffer);
                        $buffer = [];
                    }
                }

                if ($header === null) {
                    throw new RuntimeException('Malformed CSV: the file is empty.');
                }

                if ($buffer !== []) {
                    InvoiceLine::insert($buffer);
                }

                $invoice->update([
                    'total_cents' => (int) $invoice->lines()->sum('amount_cents'),
                ]);
            });
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Validate the header line and return it, normalised.
     *
     * @param  list<string|null>  $cells
     * @return list<string>
     */
    private function assertHeader(array $cells): array
    {
        if (isset($cells[0])) {
            $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $cells[0]);
        }

        $normalised = array_map(fn ($cell) => strtolower(trim((string) $cell)), $cells);

        if ($normalised !== self::HEADER) {
            throw new RuntimeException(sprintf(
                'Malformed CSV row 1: expected header "%s".',
                implode(',', self::HEADER),
            ));
        }

        return $normalised;
    }

    /**
     * @param  list<string|null>  $cells
     * @return array<string, mixed>
     */
    private function mapRow(array $cells, int $line): array
    {
        if (count($cells) !== count(self::HEADER)) {
            throw new RuntimeException(sprintf(
                'Malformed CSV row %d: expected %d columns, got %d.',
                $line,
                count(self::HEADER),
                count($cells),
            ));
        }

        [$tracking, $chargeType, $description, $billedWeight, $amount] = $cells;

        $tracking = trim((string) $tracking);
        $chargeType = trim((string) $chargeType);

        if ($tracking === '') {
            throw new RuntimeException(sprintf('Malformed CSV row %d: tracking_number is empty.', $line));
        }

        if ($chargeType === '') {
            throw new RuntimeException(sprintf('Malformed CSV row %d: charge_type is empty.', $line));
        }

        return [
            'tracking_number' => $tracking,
            'charge_type' => $chargeType,
            'description' => trim((string) $description) !== '' ? trim((string) $description) : null,
            'billed_weight_kg' => $this->weight($billedWeight, $line),
            'amount_cents' => $this->cents($amount, $line),
        ];
    }

    /**
     * Weights arrive as decimal strings; an empty cell means "not billed by
     * weight" (surcharges), which is stored as NULL.
     */
    private function weight(?string $raw, int $line): ?string
    {
        $value = trim((string) $raw);

        if ($value === '') {
            return null;
        }

        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
            throw new RuntimeException(sprintf(
                'Malformed CSV row %d: billed_weight_kg "%s" is not a valid decimal.',
                $line,
                $raw,
            ));
        }

        return $value;
    }

    /**
     * Convert a major-unit decimal string ("12.34") to integer cents (1234).
     */
    private function cents(?string $raw, int $line): int
    {
        $value = trim((string) $raw);

        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
            throw new RuntimeException(sprintf(
                'Malformed CSV row %d: amount "%s" is not a valid decimal.',
                $line,
                $raw,
            ));
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '0');

        return (int) bcadd(bcmul($whole, '100', 0), str_pad($fraction, 2, '0'), 0);
    }
}
