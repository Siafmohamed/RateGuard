<?php

namespace App\Domain\Audit\Rules;

use App\Domain\Audit\Data\AuditContext;
use App\Domain\Audit\Data\FindingData;

/**
 * Flags the same (tracking_number, charge_type) pair billed more than once.
 *
 * The first occurrence is treated as legitimate; every later one is fully
 * recoverable.
 */
class DuplicateChargeRule implements AuditRule
{
    public function name(): string
    {
        return 'duplicate_charge';
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
        $seen = [];
        $findings = [];

        foreach ($context->lines as $line) {
            $key = $line->trackingNumber.'|'.$line->chargeType;

            if (! isset($seen[$key])) {
                $seen[$key] = true;

                continue;
            }

            $findings[] = FindingData::make(
                rule: $this->name(),
                invoiceLineId: $line->id,
                expectedCents: 0,
                billedCents: $line->amountCents,
                recoverableCents: $line->amountCents,
                details: [
                    'tracking_number' => $line->trackingNumber,
                    'charge_type' => $line->chargeType,
                    'reason' => 'duplicate charge on the same tracking number and charge type',
                ],
            );
        }

        return $findings;
    }
}
