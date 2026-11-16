<?php

namespace Tests\Unit\Audit;

use App\Domain\Audit\Data\AuditContext;
use App\Domain\Audit\Data\ContractData;
use App\Domain\Audit\Data\InvoiceLineData;
use App\Domain\Audit\Data\RateData;
use App\Domain\Audit\Data\ShipmentData;
use App\Domain\Audit\Rules\AuditEngine;
use App\Domain\Audit\Rules\AuditRule;
use App\Domain\Audit\Rules\DimensionalWeightRule;
use App\Domain\Audit\Rules\DuplicateChargeRule;
use App\Domain\Audit\Rules\RuleRegistry;
use App\Domain\Audit\Rules\UnauthorizedSurchargeRule;
use Tests\TestCase;

class AuditEngineTest extends TestCase
{
    private function shipment(): ShipmentData
    {
        return new ShipmentData(1, '1ZTEST0001', 'GROUND', '3.00', '40.00', '30.00', '20.00');
    }

    private function context(array $lines, array $shipments, ?ContractData $contract): AuditContext
    {
        $keyed = [];

        foreach ($shipments as $shipment) {
            $keyed[$shipment->trackingNumber] = $shipment;
        }

        return new AuditContext('UPS', $lines, $keyed, $contract);
    }

    public function test_findings_that_are_not_worth_recovering_are_dropped(): void
    {
        $registry = new RuleRegistry;
        $registry->registerGlobal(new DimensionalWeightRule);

        $contract = new ContractData(1, 'UPS', 5000, [
            new RateData('GROUND', null, '1.01', '5.00', 1450, null),
        ], ['fuel']);

        $line = new InvoiceLineData(10, '1ZTEST0001', 'base', null, '8.00', 900, 1);

        // The weight is over-stated but the expected rate (1450) exceeds what was
        // billed (900), so the recoverable amount is zero and the finding is dropped.
        $this->assertSame([], (new AuditEngine($registry))->run($this->context([$line], [$this->shipment()], $contract)));
    }

    public function test_findings_are_sorted_by_line_and_then_by_rule(): void
    {
        $registry = new RuleRegistry;
        $registry->registerGlobal(new DuplicateChargeRule, new UnauthorizedSurchargeRule);

        $contract = new ContractData(1, 'UPS', 5000, [], ['fuel']);

        $lines = [
            new InvoiceLineData(9, '1ZTEST0009', 'demand', null, null, 1000, null),
            new InvoiceLineData(3, '1ZTEST0003', 'demand', null, null, 1000, null),
            new InvoiceLineData(3, '1ZTEST0003', 'demand', null, null, 1000, null),
        ];

        $pairs = array_map(
            fn ($finding) => $finding->invoiceLineId.':'.$finding->rule,
            (new AuditEngine($registry))->run($this->context($lines, [], $contract)),
        );

        $this->assertSame([
            '3:duplicate_charge',
            '3:unauthorized_surcharge',
            '3:unauthorized_surcharge',
            '9:unauthorized_surcharge',
        ], $pairs);
    }

    public function test_the_registry_holds_the_three_rules_for_each_supported_carrier(): void
    {
        $registry = app(RuleRegistry::class);

        foreach (['UPS', 'FEDEX', 'DHL'] as $carrier) {
            $names = array_map(
                fn (AuditRule $rule) => $rule->name(),
                $registry->rulesFor($carrier),
            );

            sort($names);

            $this->assertSame([
                'dimensional_weight',
                'duplicate_charge',
                'unauthorized_surcharge',
            ], $names, "carrier {$carrier}");
        }
    }
}
