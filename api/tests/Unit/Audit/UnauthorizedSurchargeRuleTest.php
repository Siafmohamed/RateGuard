<?php

namespace Tests\Unit\Audit;

use App\Domain\Audit\Data\AuditContext;
use App\Domain\Audit\Data\ContractData;
use App\Domain\Audit\Data\InvoiceLineData;
use App\Domain\Audit\Rules\UnauthorizedSurchargeRule;
use Tests\TestCase;

class UnauthorizedSurchargeRuleTest extends TestCase
{
    private UnauthorizedSurchargeRule $rule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rule = new UnauthorizedSurchargeRule;
    }

    /**
     * A contract that only carries a rate line for the given charge types.
     */
    private function contract(array $allowedChargeTypes): ContractData
    {
        return new ContractData(
            id: 1,
            carrierCode: 'UPS',
            dimDivisor: 5000,
            rates: [],
            allowedChargeTypes: $allowedChargeTypes,
        );
    }

    /**
     * @param  list<InvoiceLineData>  $lines
     */
    private function context(array $lines, ?ContractData $contract): AuditContext
    {
        return new AuditContext('UPS', $lines, [], $contract);
    }

    private function line(int $id, string $chargeType, int $amountCents): InvoiceLineData
    {
        return new InvoiceLineData(
            id: $id,
            trackingNumber: '1ZTEST0001',
            chargeType: $chargeType,
            description: null,
            billedWeightKg: null,
            amountCents: $amountCents,
            shipmentId: null,
        );
    }

    public function test_a_surcharge_the_contract_allows_is_not_flagged(): void
    {
        $findings = $this->rule->evaluate($this->context(
            [$this->line(1, 'fuel', 186)],
            $this->contract(['fuel', 'residential']),
        ));

        $this->assertSame([], $findings);
    }

    public function test_a_surcharge_the_contract_does_not_allow_is_fully_recoverable(): void
    {
        $findings = $this->rule->evaluate($this->context(
            [$this->line(7, 'demand', 1250)],
            $this->contract(['fuel', 'residential']),
        ));

        $this->assertCount(1, $findings);

        $finding = $findings[0];

        $this->assertSame('unauthorized_surcharge', $finding->rule);
        $this->assertSame(7, $finding->invoiceLineId);
        $this->assertSame(0, $finding->expectedCents);
        $this->assertSame(1250, $finding->billedCents);
        $this->assertSame(1250, $finding->recoverableCents);
        $this->assertSame(['fuel', 'residential'], $finding->details['allowed_charge_types']);
    }

    public function test_without_a_contract_the_rule_stays_silent(): void
    {
        $findings = $this->rule->evaluate($this->context(
            [$this->line(7, 'demand', 1250)],
            null,
        ));

        $this->assertSame([], $findings);
    }

    public function test_charge_types_that_are_not_known_surcharges_are_ignored(): void
    {
        $findings = $this->rule->evaluate($this->context(
            [
                $this->line(1, 'base', 1500),
                $this->line(2, 'dim_weight_adjustment', 900),
                $this->line(3, 'other', 100),
            ],
            $this->contract(['fuel', 'residential']),
        ));

        $this->assertSame([], $findings);
    }

    public function test_every_disallowed_surcharge_line_is_flagged(): void
    {
        $findings = $this->rule->evaluate($this->context(
            [
                $this->line(1, 'demand', 1250),
                $this->line(2, 'demand', 1250),
                $this->line(3, 'fuel', 186),
            ],
            $this->contract(['fuel']),
        ));

        $this->assertCount(2, $findings);
        $this->assertSame(2500, array_sum(array_map(
            fn ($finding) => $finding->recoverableCents,
            $findings,
        )));
    }
}
