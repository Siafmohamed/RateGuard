<?php

namespace App\Domain\Invoices\Jobs;

use App\Domain\Shipments\Models\Shipment;
use App\Enums\InvoiceStatus;

/**
 * Stage 2 — normalise the billed lines.
 *
 * Carrier exports spell the same accessorial in many ways ("Fuel Surcharge",
 * "F/S", "Res"...). Each charge_type is lower-cased, folded to a single space
 * and mapped onto the canonical set the audit rules understand; anything
 * unknown becomes "other". Every line is then linked to the shipment with the
 * same tracking number, which is what lets the weight rules compare billed
 * weight against the physical shipment.
 */
class NormalizeInvoiceJob extends InvoiceJob
{
    /**
     * Canonical charge types (what the rules match on).
     */
    private const CANONICAL = [
        'base',
        'fuel',
        'residential',
        'demand',
        'dim_weight_adjustment',
        'other',
    ];

    /**
     * Known aliases, keyed by their normalised form.
     */
    private const ALIASES = [
        'base' => 'base',
        'base charge' => 'base',
        'transportation' => 'base',
        'ground transportation' => 'base',
        'fuel' => 'fuel',
        'fuel surcharge' => 'fuel',
        'fs' => 'fuel',
        'res' => 'residential',
        'resi' => 'residential',
        'residential' => 'residential',
        'residential delivery' => 'residential',
        'demand' => 'demand',
        'demand surcharge' => 'demand',
        'dim' => 'dim_weight_adjustment',
        'dim weight' => 'dim_weight_adjustment',
        'dim weight adjustment' => 'dim_weight_adjustment',
        'dim_weight' => 'dim_weight_adjustment',
        'dim_weight_adjustment' => 'dim_weight_adjustment',
        'dimensional weight' => 'dim_weight_adjustment',
        'dimensional weight adjustment' => 'dim_weight_adjustment',
    ];

    protected function process(): void
    {
        $invoice = $this->invoice();

        $shipments = Shipment::query()
            ->whereIn('tracking_number', $invoice->lines()->distinct()->pluck('tracking_number'))
            ->get()
            ->keyBy('tracking_number');

        $invoice->lines()->chunkById(200, function ($lines) use ($shipments) {
            foreach ($lines as $line) {
                $line->update([
                    'charge_type' => $this->canonical($line->charge_type),
                    'shipment_id' => $shipments->get($line->tracking_number)?->getKey(),
                ]);
            }
        });

        $invoice->update(['status' => InvoiceStatus::Normalized->value]);
    }

    /**
     * Map a raw carrier charge label onto a canonical charge type.
     */
    private function canonical(?string $chargeType): string
    {
        $normalised = strtolower(trim((string) $chargeType));
        $normalised = (string) preg_replace('/\s+/', ' ', $normalised);

        if (in_array($normalised, self::CANONICAL, true)) {
            return $normalised;
        }

        return self::ALIASES[$normalised] ?? 'other';
    }
}
