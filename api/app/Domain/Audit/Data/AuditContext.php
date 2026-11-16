<?php

namespace App\Domain\Audit\Data;

/**
 * Everything a rule needs to judge an invoice: who the carrier is, the billed
 * lines, the shipments those lines reference (keyed by tracking number), and the
 * governing contract. Rules are pure functions over this value object — no DB,
 * no HTTP, no clock.
 */
final readonly class AuditContext
{
    /**
     * @param  list<InvoiceLineData>  $lines
     * @param  array<string, ShipmentData>  $shipmentsByTracking
     */
    public function __construct(
        public string $carrierCode,
        public array $lines,
        public array $shipmentsByTracking,
        public ?ContractData $contract,
    ) {}
}
