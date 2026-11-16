<?php

namespace App\Domain\Audit\Data;

use App\Domain\Contracts\Models\Contract;

/**
 * The relevant slice of a contract for auditing: which carrier it belongs to,
 * its dimensional divisor, the full rate card, and the surcharge types it allows.
 */
final readonly class ContractData
{
    /**
     * @param  list<RateData>  $rates
     * @param  list<string>  $allowedChargeTypes
     */
    public function __construct(
        public int $id,
        public string $carrierCode,
        public int $dimDivisor,
        public array $rates,
        public array $allowedChargeTypes,
    ) {}

    public static function fromModel(?Contract $contract, string $carrierCode): ?self
    {
        if ($contract === null) {
            return null;
        }

        $rates = $contract->rates
            ->map(fn ($rate) => RateData::fromModel($rate))
            ->values()
            ->all();

        // A contract "allows" every surcharge for which it carries a rate line.
        $allowed = $contract->rates
            ->pluck('charge_type')
            ->filter()
            ->unique()
            ->values()
            ->all();

        return new self(
            id: (int) $contract->getKey(),
            carrierCode: $carrierCode,
            dimDivisor: (int) $contract->dim_divisor ?: (int) config('rateguard.dim_divisor_default', 5000),
            rates: $rates,
            allowedChargeTypes: $allowed,
        );
    }
}
