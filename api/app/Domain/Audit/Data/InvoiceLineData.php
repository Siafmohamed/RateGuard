<?php

namespace App\Domain\Audit\Data;

use App\Domain\Invoices\Models\InvoiceLine;

/**
 * A single billed line, as the rules see it. Weight is a decimal string, money
 * is integer cents.
 */
final readonly class InvoiceLineData
{
    public function __construct(
        public int $id,
        public string $trackingNumber,
        public string $chargeType,
        public ?string $description,
        public ?string $billedWeightKg,
        public int $amountCents,
        public ?int $shipmentId,
    ) {}

    public static function fromModel(InvoiceLine $line): self
    {
        return new self(
            id: (int) $line->getKey(),
            trackingNumber: (string) $line->tracking_number,
            chargeType: (string) $line->charge_type,
            description: $line->description,
            billedWeightKg: $line->billed_weight_kg !== null ? (string) $line->billed_weight_kg : null,
            amountCents: (int) $line->amount_cents,
            shipmentId: $line->shipment_id !== null ? (int) $line->shipment_id : null,
        );
    }
}
