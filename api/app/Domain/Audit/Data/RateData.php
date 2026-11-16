<?php

namespace App\Domain\Audit\Data;

/**
 * One line of a contract's rate card: either a weight-band price (chargeType is
 * null) or an allowed accessorial/surcharge (fuel, residential, demand...).
 *
 * Weights are decimal strings and money is integer cents: no floats anywhere in
 * the money/weight math.
 */
final readonly class RateData
{
    public function __construct(
        public string $serviceLevel,
        public ?string $zone,
        public ?string $minWeightKg,
        public ?string $maxWeightKg,
        public int $rateCents,
        public ?string $chargeType,
    ) {}

    public static function fromModel(\App\Domain\Contracts\Models\ContractRate $rate): self
    {
        return new self(
            serviceLevel: (string) $rate->service_level,
            zone: $rate->zone !== null ? (string) $rate->zone : null,
            minWeightKg: $rate->min_weight_kg !== null ? (string) $rate->min_weight_kg : null,
            maxWeightKg: $rate->max_weight_kg !== null ? (string) $rate->max_weight_kg : null,
            rateCents: (int) $rate->rate_cents,
            chargeType: $rate->charge_type,
        );
    }
}
