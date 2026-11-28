<?php

namespace Database\Seeders;

use App\Domain\Contracts\Models\Contract;
use App\Domain\Contracts\Models\ContractRate;
use App\Models\Carrier;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Seeder;

/**
 * Idempotently creates a default contract + rate card for every organization
 * and every carrier, so the audit engine always has something to compare
 * against.
 *
 * In production the only way a contract exists was for a seeder to create it
 * (there is no contracts UI/API yet). Without a contract, `AuditInvoiceJob`
 * skips the overcharge rules and stores an error_message, which is exactly the
 * "0 findings" state seen on Railway. This seeder guarantees a contract exists
 * for each org/carrier so audits actually run.
 *
 * Safe to run on every deploy: `updateOrCreate` keys on a stable
 * (carrier_id, name) pair, and CurrentOrganization scopes each insert to the
 * org being processed.
 */
class ContractsSeeder extends Seeder
{
    public function run(): void
    {
        $carriers = Carrier::all();
        if ($carriers->isEmpty()) {
            return;
        }

        // Weight bands (kilograms -> integer cents). Mirrors the demo Acme/UPS
        // contract so the same rule logic applies to all organizations.
        $bands = [
            ['min' => 0.00, 'max' => 1.00, 'cents' => 950],
            ['min' => 1.01, 'max' => 5.00, 'cents' => 1450],
            ['min' => 5.01, 'max' => 10.00, 'cents' => 2150],
            ['min' => 10.01, 'max' => 25.00, 'cents' => 3400],
        ];

        // Allowed accessorials with their contracted cap.
        $surcharges = ['fuel' => 1200, 'residential' => 495];

        $current = app(CurrentOrganization::class);

        Organization::chunk(50, function ($orgs) use ($carriers, $bands, $surcharges, $current) {
            foreach ($orgs as $org) {
                $current->set($org);

                foreach ($carriers as $carrier) {
                    $contract = Contract::updateOrCreate(
                        [
                            'carrier_id' => $carrier->id,
                            'name' => "{$org->name} / {$carrier->name} Default",
                        ],
                        [
                            'effective_from' => '2026-01-01',
                            'effective_to' => null,
                            'dim_divisor' => 5000,
                        ],
                    );

                    foreach ($bands as $band) {
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

                    foreach ($surcharges as $chargeType => $cents) {
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

                $current->forget();
            }
        });
    }
}
