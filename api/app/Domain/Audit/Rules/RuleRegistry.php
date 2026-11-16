<?php

namespace App\Domain\Audit\Rules;

/**
 * Maps a carrier code to the rules that should run for it.
 *
 * Rules can be registered globally (for every carrier) or per carrier. Adding a
 * carrier-specific rule later is a single line in the service provider:
 *
 *     $registry->forCarrier('UPS', new SomeNewRule());
 */
class RuleRegistry
{
    /**
     * @var list<AuditRule>
     */
    private array $global = [];

    /**
     * @var array<string, list<AuditRule>>
     */
    private array $byCarrier = [];

    public function registerGlobal(AuditRule ...$rules): void
    {
        foreach ($rules as $rule) {
            $this->global[] = $rule;
        }
    }

    public function forCarrier(string $carrierCode, AuditRule $rule): void
    {
        $this->byCarrier[strtoupper($carrierCode)][] = $rule;
    }

    /**
     * All rules that apply to the carrier: the globals plus the carrier's own.
     *
     * @return list<AuditRule>
     */
    public function rulesFor(string $carrierCode): array
    {
        $rules = $this->global;

        foreach ($this->byCarrier[strtoupper($carrierCode)] ?? [] as $rule) {
            $rules[] = $rule;
        }

        return array_values(array_filter(
            $rules,
            fn (AuditRule $rule) => $rule->appliesTo($carrierCode),
        ));
    }
}
