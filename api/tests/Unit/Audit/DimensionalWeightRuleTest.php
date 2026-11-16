<?php

namespace Tests\Unit\Audit;

use App\Domain\Audit\Data\AuditContext;
use App\Domain\Audit\Data\ContractData;
use App\Domain\Audit\Data\InvoiceLineData;
use App\Domain\Audit\Data\RateData;
use App\Domain\Audit\Data\ShipmentData;
use App\Domain\Audit\Rules\DimensionalWeightRule;
use Tests\TestCase;

class DimensionalWeightRuleTest extends TestCase
{
    private DimensionalWeightRule $rule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rule = new DimensionalWeightRule;
    }

    /**
     * A UPS GROUND rate card: weight bands only, integers in cents.
     *
     * @return list<RateData>
     */
    private function bands(): array
    {
        return [
            new RateData('GROUND', null, '0.00', '1.00', 950, null),
            new RateData('GROUND', null, '1.01', '5.00', 1450, null),
            new RateData('GROUND', null, '5.01', '10.00', 2150, null),
            new RateData('GROUND', null, '10.01', '25.00', 3400, null),
        ];
    }

    private function contract(?array $rates = null, int $divisor = 5000): ContractData
    {
        return new ContractData(
            id: 1,
            carrierCode: 'UPS',
            dimDivisor: $divisor,
            rates: $rates ?? $this->bands(),
            allowedChargeTypes: ['fuel', 'residential'],
        );
    }

    /**
     * 40 x 30 x 20 cm at a divisor of 5000 is 4.80 kg dimensional weight; with an
     * actual weight of 3.00 kg the expected billable weight is ceil(4.80) = 5.00.
     */
    private function shipment(
        string $actualWeightKg = '3.00',
        string $lengthCm = '40.00',
        string $widthCm = '30.00',
        string $heightCm = '20.00',
        string $serviceLevel = 'GROUND',
        string $trackingNumber = '1ZTEST0001',
    ): ShipmentData {
        return new ShipmentData(
            id: 1,
            trackingNumber: $trackingNumber,
            serviceLevel: $serviceLevel,
            actualWeightKg: $actualWeightKg,
            lengthCm: $lengthCm,
            widthCm: $widthCm,
            heightCm: $heightCm,
        );
    }

    private function line(
        int $id,
        ?string $billedWeightKg,
        int $amountCents,
        ?int $shipmentId = 1,
        string $trackingNumber = '1ZTEST0001',
        string $chargeType = 'base',
    ): InvoiceLineData {
        return new InvoiceLineData(
            id: $id,
            trackingNumber: $trackingNumber,
            chargeType: $chargeType,
            description: null,
            billedWeightKg: $billedWeightKg,
            amountCents: $amountCents,
            shipmentId: $shipmentId,
        );
    }

    /**
     * @param  list<InvoiceLineData>  $lines
     * @param  list<ShipmentData>  $shipments
     */
    private function context(array $lines, array $shipments, ?ContractData $contract): AuditContext
    {
        $keyed = [];

        foreach ($shipments as $shipment) {
            $keyed[$shipment->trackingNumber] = $shipment;
        }

        return new AuditContext('UPS', $lines, $keyed, $contract);
    }

    public function test_a_line_billed_at_the_expected_weight_is_not_flagged(): void
    {
        $context = $this->context(
            [$this->line(10, '5.00', 1500)],
            [$this->shipment()],
            $this->contract(),
        );

        $this->assertSame([], $this->rule->evaluate($context));
    }

    public function test_a_line_billed_below_the_expected_weight_is_not_flagged(): void
    {
        $context = $this->context(
            [$this->line(10, '2.00', 1200)],
            [$this->shipment()],
            $this->contract(),
        );

        $this->assertSame([], $this->rule->evaluate($context));
    }

    public function test_a_line_billed_above_the_expected_weight_is_flagged(): void
    {
        $context = $this->context(
            [$this->line(10, '8.00', 2000)],
            [$this->shipment()],
            $this->contract(),
        );

        $findings = $this->rule->evaluate($context);

        $this->assertCount(1, $findings);

        $finding = $findings[0];

        $this->assertSame('dimensional_weight', $finding->rule);
        $this->assertSame(10, $finding->invoiceLineId);
        // Expected weight rounds to 5.00 kg, which prices in the 1.01-5.00 band.
        $this->assertSame(1450, $finding->expectedCents);
        $this->assertSame(2000, $finding->billedCents);
        $this->assertSame(550, $finding->recoverableCents);
        $this->assertSame('5', $finding->details['expected_billable_weight_kg']);
        $this->assertSame('8.00', $finding->details['billed_weight_kg']);
    }

    public function test_the_expected_weight_is_rounded_up_to_the_next_kilogram(): void
    {
        // 35 x 30 x 20 cm => 4.20 kg dimensional, rounded up to 5.00 kg. A line
        // billed 4.50 kg therefore sits inside the expected weight and is fine:
        // without the round-up it would have been flagged.
        $context = $this->context(
            [$this->line(10, '4.50', 1500)],
            [$this->shipment(lengthCm: '35.00')],
            $this->contract(),
        );

        $this->assertSame([], $this->rule->evaluate($context));
    }

    public function test_an_actual_weight_heavier_than_the_dimensional_weight_is_used(): void
    {
        // A small box but a heavy parcel: expected = ceil(max(9.00, 0.20)) = 9.00.
        $context = $this->context(
            [$this->line(10, '9.00', 3000)],
            [$this->shipment(actualWeightKg: '9.00', lengthCm: '10.00', widthCm: '10.00', heightCm: '10.00')],
            $this->contract(),
        );

        $this->assertSame([], $this->rule->evaluate($context));
    }

    public function test_a_line_without_a_billed_weight_is_ignored(): void
    {
        $context = $this->context(
            [$this->line(10, null, 900)],
            [$this->shipment()],
            $this->contract(),
        );

        $this->assertSame([], $this->rule->evaluate($context));
    }

    public function test_a_line_without_a_linked_shipment_is_ignored(): void
    {
        $context = $this->context(
            [$this->line(10, '8.00', 900, shipmentId: null)],
            [$this->shipment()],
            $this->contract(),
        );

        $this->assertSame([], $this->rule->evaluate($context));
    }

    public function test_a_line_whose_shipment_cannot_be_found_is_ignored(): void
    {
        $context = $this->context(
            [$this->line(10, '8.00', 900)],
            [],
            $this->contract(),
        );

        $this->assertSame([], $this->rule->evaluate($context));
    }

    public function test_a_line_with_no_matching_rate_band_is_ignored(): void
    {
        // The contract only carries a surcharge rate, so there is no base band to
        // price the expected weight against.
        $context = $this->context(
            [$this->line(10, '8.00', 900)],
            [$this->shipment()],
            $this->contract(rates: [new RateData('GROUND', null, null, null, 1200, 'fuel')]),
        );

        $this->assertSame([], $this->rule->evaluate($context));
    }

    public function test_without_a_contract_the_rule_stays_silent(): void
    {
        $context = $this->context(
            [$this->line(10, '8.00', 900)],
            [$this->shipment()],
            null,
        );

        $this->assertSame([], $this->rule->evaluate($context));
    }

    public function test_the_rule_reports_zero_recoverable_when_the_rate_costs_more_than_was_billed(): void
    {
        // The expected 5.00 kg band costs 1450 but only 900 was billed, so the
        // weight is over-stated yet nothing is recoverable. The engine drops it.
        $findings = $this->rule->evaluate($this->context(
            [$this->line(10, '8.00', 900)],
            [$this->shipment()],
            $this->contract(),
        ));

        $this->assertCount(1, $findings);
        $this->assertSame(1450, $findings[0]->expectedCents);
        $this->assertSame(900, $findings[0]->billedCents);
        $this->assertSame(0, $findings[0]->recoverableCents);
    }

    public function test_an_overstated_weight_adjustment_is_recoverable_in_full(): void
    {
        // The adjustment bills 8.00 kg although the dimensions justify only
        // 5.00 kg. The base transportation is billed on its own line, so there
        // is no rate to net off: the whole 900 cents is recoverable.
        $findings = $this->rule->evaluate($this->context(
            [$this->line(10, '8.00', 900, chargeType: 'dim_weight_adjustment')],
            [$this->shipment()],
            $this->contract(),
        ));

        $this->assertCount(1, $findings);
        $this->assertSame(0, $findings[0]->expectedCents);
        $this->assertSame(900, $findings[0]->billedCents);
        $this->assertSame(900, $findings[0]->recoverableCents);
        $this->assertSame('8.00', $findings[0]->details['billed_weight_kg']);
    }

    public function test_a_weight_adjustment_within_the_justified_weight_is_not_flagged(): void
    {
        $findings = $this->rule->evaluate($this->context(
            [$this->line(10, '5.00', 900, chargeType: 'dim_weight_adjustment')],
            [$this->shipment()],
            $this->contract(),
        ));

        $this->assertSame([], $findings);
    }
}
