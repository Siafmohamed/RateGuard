<?php

namespace App\Domain\Audit\Rules;

use App\Domain\Audit\Data\AuditContext;
use App\Domain\Audit\Data\FindingData;

/**
 * Flags a surcharge the contract does not allow.
 *
 * A surcharge line is any line whose charge_type is one of the known
 * accessorials. If the contract carries no rate line for that charge type, the
 * whole amount is recoverable. When there is no contract at all the rule stays
 * silent: there is nothing to compare against.
 */
class UnauthorizedSurchargeRule implements AuditRule
{
    /**
     * The accessorial charge types this rule knows about.
     *
     * @var list<string>
     */
    private const SURCHARGES = ['fuel', 'residential', 'demand'];

    public function name(): string
    {
        return 'unauthorized_surcharge';
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

        $findings = [];

        foreach ($context->lines as $line) {
            if (! in_array($line->chargeType, self::SURCHARGES, true)) {
                continue;
            }

            if (in_array($line->chargeType, $contract->allowedChargeTypes, true)) {
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
                    'reason' => 'surcharge not allowed by the contract',
                    'allowed_charge_types' => $contract->allowedChargeTypes,
                ],
            );
        }

        return $findings;
    }
}
