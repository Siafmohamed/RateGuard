<?php

namespace App\Domain\Audit\Rules;

use App\Domain\Audit\Data\AuditContext;
use App\Domain\Audit\Data\FindingData;

/**
 * A single, pure audit rule.
 *
 * Rules never touch the database, HTTP, storage or the clock: everything they
 * need is inside the AuditContext they are handed. That keeps them trivially
 * unit-testable and deterministic.
 */
interface AuditRule
{
    /**
     * Stable identifier persisted on every finding (also part of the
     * [invoice_line_id, rule] idempotency key).
     */
    public function name(): string;

    /**
     * Whether this rule applies to the given carrier code.
     */
    public function appliesTo(string $carrierCode): bool;

    /**
     * Evaluate the context and return zero or more candidate findings.
     *
     * @return list<FindingData>
     */
    public function evaluate(AuditContext $context): array;
}
