<?php

namespace App\Domain\Audit\Rules;

use App\Domain\Audit\Data\AuditContext;
use App\Domain\Audit\Data\ContractData;
use App\Domain\Audit\Data\FindingData;
use App\Domain\Audit\Data\RateData;
use App\Domain\Audit\Data\ShipmentData;

/**
 * Flags lines billed for more weight than the shipment's dimensions justify.
 *
 * Expected billable weight = max(actual weight, dimensional weight), rounded up
 * per config('rateguard.weight_rounding'). When a line's billed weight exceeds
 * that, the line is priced at the contract rate for the expected weight; the
 * difference between what was billed and that rate is recoverable.
 *
 * A dimensional-weight adjustment line is judged differently: it bills the extra
 * weight the carrier believes the shipment owes, and the base transportation is
 * already billed on its own line. When even the adjustment's own billed weight
 * exceeds what the dimensions justify, the whole adjustment is improper and its
 * full amount is recoverable.
 *
 * Weight arithmetic uses bcmath on decimal strings, never floats.
 */
class DimensionalWeightRule implements AuditRule
{
    public function name(): string
    {
        return 'dimensional_weight';
    }

    public function appliesTo(string $carrierCode): bool
    {
        return true;
    }

    /**
     * @return list<FindingData>
     */
    public function evaluate(AuditContext $context): array
    {
        $contract = $context->contract;

        if ($contract === null) {
            return [];
        }

        $divisor = $contract->dimDivisor > 0
            ? $contract->dimDivisor
            : (int) config('rateguard.dim_divisor_default', 5000);

        $findings = [];

        foreach ($context->lines as $line) {
            if ($line->billedWeightKg === null || $line->shipmentId === null) {
                continue;
            }

            $shipment = $context->shipmentsByTracking[$line->trackingNumber] ?? null;

            if ($shipment === null) {
                continue;
            }

            $dimKg = $this->dimensionalWeightKg($shipment, $divisor);
            $expectedKg = $this->expectedBillableWeight($shipment, $dimKg);

            // Only over-billed weight is of interest. Equal or lighter is fine.
            if (bccomp($line->billedWeightKg, $expectedKg, 6) <= 0) {
                continue;
            }

            $details = [
                'tracking_number' => $line->trackingNumber,
                'dim_weight_kg' => $dimKg,
                'expected_billable_weight_kg' => $expectedKg,
                'billed_weight_kg' => $line->billedWeightKg,
                'service_level' => $shipment->serviceLevel,
            ];

            // An adjustment line has no base rate to net off — the base
            // transportation is billed separately — so an overstated one is
            // recoverable in full.
            if ($this->isWeightAdjustment($line->chargeType)) {
                $findings[] = FindingData::make(
                    rule: $this->name(),
                    invoiceLineId: $line->id,
                    expectedCents: 0,
                    billedCents: $line->amountCents,
                    recoverableCents: $line->amountCents,
                    details: $details,
                );

                continue;
            }

            $rate = $this->rateFor($contract, $shipment, $expectedKg);

            if ($rate === null) {
                continue;
            }

            $expected = $rate->rateCents;
            $billed = $line->amountCents;
            $recoverable = max(0, $billed - $expected);

            $findings[] = FindingData::make(
                rule: $this->name(),
                invoiceLineId: $line->id,
                expectedCents: $expected,
                billedCents: $billed,
                recoverableCents: $recoverable,
                details: $details,
            );
        }

        return $findings;
    }

    /**
     * Whether the line is a dimensional-weight adjustment rather than a
     * normally priced charge.
     */
    private function isWeightAdjustment(string $chargeType): bool
    {
        return $chargeType === 'dim_weight_adjustment';
    }

    private function dimensionalWeightKg(ShipmentData $shipment, int $divisor): string
    {
        $volume = bcmul(
            bcmul($shipment->lengthCm, $shipment->widthCm, 6),
            $shipment->heightCm,
            6,
        );

        return bcdiv($volume, (string) $divisor, 6);
    }

    private function expectedBillableWeight(ShipmentData $shipment, string $dimKg): string
    {
        $weight = bccomp($shipment->actualWeightKg, $dimKg, 6) >= 0
            ? $shipment->actualWeightKg
            : $dimKg;

        return match (config('rateguard.weight_rounding', 'ceil_kg')) {
            'ceil_kg' => $this->ceilToKg($weight),
            default => $weight,
        };
    }

    /**
     * Round a decimal string up to the next whole kilogram.
     */
    private function ceilToKg(string $weight): string
    {
        $whole = explode('.', $weight, 2)[0];

        return bccomp($weight, $whole, 6) > 0
            ? (string) ((int) $whole + 1)
            : $whole;
    }

    /**
     * The base (charge_type null) rate whose weight band contains the weight.
     */
    private function rateFor(ContractData $contract, ShipmentData $shipment, string $weight): ?RateData
    {
        foreach ($contract->rates as $rate) {
            if ($rate->chargeType !== null) {
                continue;
            }

            if ($rate->serviceLevel !== $shipment->serviceLevel) {
                continue;
            }

            if ($shipment->zone !== null && $rate->zone !== null && $rate->zone !== $shipment->zone) {
                continue;
            }

            if ($rate->minWeightKg !== null && bccomp($weight, $rate->minWeightKg, 6) < 0) {
                continue;
            }

            if ($rate->maxWeightKg !== null && bccomp($weight, $rate->maxWeightKg, 6) > 0) {
                continue;
            }

            return $rate;
        }

        return null;
    }
}
