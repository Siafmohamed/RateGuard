<?php

namespace App\Domain\Audit\Rules;

use App\Domain\Audit\Data\AuditContext;
use App\Domain\Audit\Data\FindingData;

/**
 * Runs every rule that applies to an invoice's carrier and returns a clean,
 * deterministic list of findings.
 *
 * Findings that are not worth recovering (<= 0 cents) are dropped, and the rest
 * are sorted by (billed line id, rule name) so repeated runs produce a stable
 * order.
 */
class AuditEngine
{
    public function __construct(private readonly RuleRegistry $registry) {}

    /**
     * @return list<FindingData>
     */
    public function run(AuditContext $context): array
    {
        $findings = [];

        foreach ($this->registry->rulesFor($context->carrierCode) as $rule) {
            foreach ($rule->evaluate($context) as $finding) {
                if ($finding->recoverableCents <= 0) {
                    continue;
                }

                $findings[] = $finding;
            }
        }

        usort($findings, static function (FindingData $a, FindingData $b): int {
            return [$a->invoiceLineId ?? 0, $a->rule] <=> [$b->invoiceLineId ?? 0, $b->rule];
        });

        return $findings;
    }
}
