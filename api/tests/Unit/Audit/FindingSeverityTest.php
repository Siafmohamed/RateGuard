<?php

namespace Tests\Unit\Audit;

use App\Domain\Audit\Data\FindingData;
use Tests\TestCase;

/**
 * Severity is derived purely from the recoverable amount: >= 5000 cents is
 * high, >= 1000 is medium, anything else is low.
 */
class FindingSeverityTest extends TestCase
{
    public function test_severity_bands_are_derived_from_the_recoverable_amount(): void
    {
        $this->assertSame('low', FindingData::severityFor(0));
        $this->assertSame('low', FindingData::severityFor(999));
        $this->assertSame('medium', FindingData::severityFor(1000));
        $this->assertSame('medium', FindingData::severityFor(4999));
        $this->assertSame('high', FindingData::severityFor(5000));
        $this->assertSame('high', FindingData::severityFor(1_000_000));
    }

    public function test_the_make_factory_derives_the_severity_from_the_recoverable_amount(): void
    {
        $high = FindingData::make('any_rule', 1, 0, 7500, 7500);
        $medium = FindingData::make('any_rule', 1, 0, 2500, 2500);
        $low = FindingData::make('any_rule', 1, 0, 500, 500);

        $this->assertSame('high', $high->severity);
        $this->assertSame('medium', $medium->severity);
        $this->assertSame('low', $low->severity);
    }
}
