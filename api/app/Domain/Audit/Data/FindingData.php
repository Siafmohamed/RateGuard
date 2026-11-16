<?php

namespace App\Domain\Audit\Data;

/**
 * A candidate discrepancy produced by a rule. The engine drops any finding whose
 * recoverable amount is not positive before it is persisted.
 */
final readonly class FindingData
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public string $rule,
        public string $severity,
        public ?int $invoiceLineId,
        public int $expectedCents,
        public int $billedCents,
        public int $recoverableCents,
        public array $details = [],
    ) {}

    /**
     * Severity band driven purely by the recoverable amount (integer cents).
     */
    public static function severityFor(int $recoverableCents): string
    {
        return match (true) {
            $recoverableCents >= 5000 => 'high',
            $recoverableCents >= 1000 => 'medium',
            default => 'low',
        };
    }

    /**
     * Convenience factory that derives the severity from the recoverable amount.
     *
     * @param  array<string, mixed>  $details
     */
    public static function make(
        string $rule,
        ?int $invoiceLineId,
        int $expectedCents,
        int $billedCents,
        int $recoverableCents,
        array $details = [],
    ): self {
        return new self(
            rule: $rule,
            severity: self::severityFor($recoverableCents),
            invoiceLineId: $invoiceLineId,
            expectedCents: $expectedCents,
            billedCents: $billedCents,
            recoverableCents: $recoverableCents,
            details: $details,
        );
    }
}
