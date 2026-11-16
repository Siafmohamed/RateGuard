<?php

namespace Tests\Unit\Audit;

use App\Domain\Audit\Data\AuditContext;
use App\Domain\Audit\Data\InvoiceLineData;
use App\Domain\Audit\Rules\DuplicateChargeRule;
use Tests\TestCase;

class DuplicateChargeRuleTest extends TestCase
{
    private DuplicateChargeRule $rule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rule = new DuplicateChargeRule;
    }

    /**
     * @param  list<InvoiceLineData>  $lines
     */
    private function context(array $lines): AuditContext
    {
        return new AuditContext('UPS', $lines, [], null);
    }

    private function line(int $id, string $trackingNumber, string $chargeType, int $amountCents): InvoiceLineData
    {
        return new InvoiceLineData(
            id: $id,
            trackingNumber: $trackingNumber,
            chargeType: $chargeType,
            description: null,
            billedWeightKg: null,
            amountCents: $amountCents,
            shipmentId: null,
        );
    }

    public function test_the_first_occurrence_of_a_pair_is_not_flagged(): void
    {
        $findings = $this->rule->evaluate($this->context([
            $this->line(1, '1ZTEST0001', 'fuel', 186),
        ]));

        $this->assertSame([], $findings);
    }

    public function test_a_repeated_pair_is_fully_recoverable(): void
    {
        $findings = $this->rule->evaluate($this->context([
            $this->line(1, '1ZTEST0001', 'fuel', 186),
            $this->line(2, '1ZTEST0001', 'fuel', 186),
        ]));

        $this->assertCount(1, $findings);

        $finding = $findings[0];

        $this->assertSame('duplicate_charge', $finding->rule);
        $this->assertSame(2, $finding->invoiceLineId);
        $this->assertSame(0, $finding->expectedCents);
        $this->assertSame(186, $finding->billedCents);
        $this->assertSame(186, $finding->recoverableCents);
    }

    public function test_a_pair_repeated_three_times_produces_two_findings(): void
    {
        $findings = $this->rule->evaluate($this->context([
            $this->line(1, '1ZTEST0001', 'fuel', 186),
            $this->line(2, '1ZTEST0001', 'fuel', 186),
            $this->line(3, '1ZTEST0001', 'fuel', 186),
        ]));

        $this->assertCount(2, $findings);
        $this->assertSame([2, 3], [$findings[0]->invoiceLineId, $findings[1]->invoiceLineId]);
    }

    public function test_different_charge_types_on_the_same_tracking_number_are_not_duplicates(): void
    {
        $findings = $this->rule->evaluate($this->context([
            $this->line(1, '1ZTEST0001', 'base', 1500),
            $this->line(2, '1ZTEST0001', 'fuel', 186),
            $this->line(3, '1ZTEST0001', 'residential', 495),
        ]));

        $this->assertSame([], $findings);
    }

    public function test_the_same_charge_on_different_tracking_numbers_is_not_a_duplicate(): void
    {
        $findings = $this->rule->evaluate($this->context([
            $this->line(1, '1ZTEST0001', 'fuel', 186),
            $this->line(2, '1ZTEST0002', 'fuel', 190),
        ]));

        $this->assertSame([], $findings);
    }

    public function test_a_duplicate_leaves_the_matching_first_line_alone(): void
    {
        $lines = [
            $this->line(1, '1ZTEST0001', 'residential', 495),
            $this->line(2, '1ZTEST0001', 'residential', 495),
        ];

        $findings = $this->rule->evaluate($this->context($lines));

        $this->assertCount(1, $findings);
        $this->assertNotSame(1, $findings[0]->invoiceLineId);
    }
}
