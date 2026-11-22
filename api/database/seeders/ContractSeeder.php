<?php

namespace Database\Seeders;

use App\Domain\Contracts\Models\Contract;
use App\Domain\Contracts\Models\ContractRate;
use App\Models\Carrier;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Seeder;

/**
 * Seeds the rate cards for organization 40 (Opsera) so its uploaded invoices
 * have a contract to be audited against.
 *
 * Run manually only — it is deliberately NOT listed in DatabaseSeeder:
 *
 *     php artisan db:seed --class=ContractSeeder
 *
 * Idempotent: re-running updates the same rows instead of duplicating them.
 */
class ContractSeeder extends Seeder
{
    /**
     * The organization these contracts belong to.
     */
    private const ORGANIZATION_ID = 40;

    /**
     * Contracts are effective for this window, which covers the invoices dated
     * 2026-11-17 through 2026-11-21.
     */
    private const EFFECTIVE_FROM = '2026-01-01';

    private const EFFECTIVE_TO = '2026-12-31';

    /**
     * Base weight bands (kilograms -> integer cents), sized to the weights seen
     * on the uploaded invoices (0.80 - 7.50 kg). Some bands equal the billed
     * amount so those lines stay clean; others are deliberately lower so the
     * overcharge is recoverable.
     */
    private const WEIGHT_BANDS = [
        ['min' => 0.00, 'max' => 1.00, 'cents' => 7000],
        ['min' => 1.01, 'max' => 5.00, 'cents' => 12000],
        ['min' => 5.01, 'max' => 10.00, 'cents' => 19000],
    ];

    /**
     * Surcharges the contract allows. "demand" is intentionally absent, so a
     * demand line is flagged as an unauthorized surcharge.
     */
    private const ALLOWED_SURCHARGES = ['fuel' => 1200, 'residential' => 495];

    public function run(): void
    {
        $organization = Organization::query()->find(self::ORGANIZATION_ID);

        if ($organization === null) {
            $this->command?->warn(sprintf(
                'Organization [%d] was not found; nothing to seed.',
                self::ORGANIZATION_ID,
            ));

            return;
        }

        // Seeders run outside a request, so the organization has to be put in
        // context explicitly. The tenancy trait then scopes every lookup and
        // fills organization_id on every insert.
        $current = app(CurrentOrganization::class);
        $current->set($organization);

        try {
            // Carriers are looked up by their unique code rather than by a
            // hard-coded id, so the seeder is safe to re-run in any database.
            foreach (['UPS', 'FEDEX', 'DHL'] as $code) {
                $carrier = Carrier::query()->where('code', $code)->first();

                if ($carrier === null) {
                    $this->command?->warn("Carrier [{$code}] was not found; skipped.");

                    continue;
                }

                $contract = Contract::updateOrCreate(
                    ['carrier_id' => $carrier->id, 'name' => "Opsera / {$code} 2026"],
                    [
                        'effective_from' => self::EFFECTIVE_FROM,
                        'effective_to' => self::EFFECTIVE_TO,
                        'dim_divisor' => 5000,
                    ],
                );

                $this->seedBands($contract);
                $this->seedSurcharges($contract);
            }
        } finally {
            $current->forget();
        }
    }

    /**
     * The base (charge_type null) weight-band prices of a contract.
     */
    private function seedBands(Contract $contract): void
    {
        foreach (self::WEIGHT_BANDS as $band) {
            ContractRate::updateOrCreate(
                [
                    'contract_id' => $contract->id,
                    'service_level' => 'GROUND',
                    'zone' => 'US',
                    'min_weight_kg' => $band['min'],
                ],
                [
                    'charge_type' => null,
                    'max_weight_kg' => $band['max'],
                    'rate_cents' => $band['cents'],
                ],
            );
        }
    }

    /**
     * The accessorials a contract allows, each as a rate row the audit reads as
     * "this surcharge type is permitted".
     */
    private function seedSurcharges(Contract $contract): void
    {
        foreach (self::ALLOWED_SURCHARGES as $chargeType => $cents) {
            ContractRate::updateOrCreate(
                [
                    'contract_id' => $contract->id,
                    'service_level' => 'GROUND',
                    'charge_type' => $chargeType,
                ],
                [
                    'zone' => null,
                    'min_weight_kg' => 0,
                    'max_weight_kg' => 0,
                    'rate_cents' => $cents,
                ],
            );
        }
    }
}
