<?php

namespace Tests\Unit\Audit;

use App\Domain\Audit\Data\AuditContext;
use App\Domain\Audit\Rules\AuditRule;
use App\Domain\Audit\Rules\RuleRegistry;
use Tests\TestCase;

/**
 * The registry is what decides which rules run for a carrier: globals run for
 * everyone, carrier-specific rules only for their own carrier, and a rule can
 * still opt out per carrier via appliesTo().
 */
class RuleRegistryTest extends TestCase
{
    /**
     * A do-nothing rule with a controllable name and carrier applicability.
     */
    private function rule(string $name, bool $applies = true): AuditRule
    {
        return new class($name, $applies) implements AuditRule
        {
            public function __construct(
                private readonly string $name,
                private readonly bool $applies,
            ) {}

            public function name(): string
            {
                return $this->name;
            }

            public function appliesTo(string $carrierCode): bool
            {
                return $this->applies;
            }

            public function evaluate(AuditContext $context): array
            {
                return [];
            }
        };
    }

    /**
     * @param  list<AuditRule>  $rules
     * @return list<string>
     */
    private function names(array $rules): array
    {
        $names = array_map(fn (AuditRule $rule) => $rule->name(), $rules);
        sort($names);

        return $names;
    }

    public function test_a_global_rule_runs_for_every_carrier(): void
    {
        $registry = new RuleRegistry;
        $registry->registerGlobal($this->rule('global_rule'));

        foreach (['UPS', 'FEDEX', 'DHL', 'UNKNOWN'] as $carrier) {
            $this->assertSame(['global_rule'], $this->names($registry->rulesFor($carrier)), $carrier);
        }
    }

    public function test_a_carrier_specific_rule_only_runs_for_its_carrier(): void
    {
        $registry = new RuleRegistry;
        $registry->forCarrier('UPS', $this->rule('ups_only'));

        // Carrier codes are matched case-insensitively.
        $this->assertSame(['ups_only'], $this->names($registry->rulesFor('UPS')));
        $this->assertSame(['ups_only'], $this->names($registry->rulesFor('ups')));
        $this->assertSame([], $this->names($registry->rulesFor('FEDEX')));
    }

    public function test_a_rule_can_opt_out_of_a_carrier_it_was_registered_for(): void
    {
        $registry = new RuleRegistry;
        $registry->registerGlobal($this->rule('everywhere'));
        $registry->forCarrier('UPS', $this->rule('ups_only', applies: false));

        $this->assertSame(['everywhere'], $this->names($registry->rulesFor('UPS')));
    }
}
