<?php

namespace App\Domain\Audit\Data;

use App\Domain\Shipments\Models\Shipment;

/**
 * The shipment details an audit rule may need. Dimensions are centimetres,
 * weights are kilograms (decimal strings).
 */
final readonly class ShipmentData
{
    public function __construct(
        public int $id,
        public string $trackingNumber,
        public string $serviceLevel,
        public string $actualWeightKg,
        public string $lengthCm,
        public string $widthCm,
        public string $heightCm,
        public ?string $zone = null,
    ) {}

    public static function fromModel(Shipment $shipment): self
    {
        return new self(
            id: (int) $shipment->getKey(),
            trackingNumber: (string) $shipment->tracking_number,
            serviceLevel: (string) $shipment->service_level,
            actualWeightKg: (string) $shipment->actual_weight_kg,
            lengthCm: (string) $shipment->length_cm,
            widthCm: (string) $shipment->width_cm,
            heightCm: (string) $shipment->height_cm,
            // Shipments carry no zone column today; zone matching is therefore
            // skipped unless a caller supplies one.
            zone: null,
        );
    }
}
