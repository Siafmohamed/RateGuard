<?php

namespace Database\Seeders;

use App\Domain\Contracts\Models\Contract;
use App\Domain\Contracts\Models\ContractRate;
use App\Domain\Invoices\Models\Invoice;
use App\Domain\Shipments\Models\Shipment;
use App\Enums\Role;
use App\Models\Carrier;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    /**
     * Seed the demo data. Running this seeder more than once is safe.
     */
    public function run(): void
    {
        $ups = Carrier::updateOrCreate(['code' => 'UPS'], ['name' => 'United Parcel Service']);
        $fedex = Carrier::updateOrCreate(['code' => 'FEDEX'], ['name' => 'FedEx']);
        Carrier::updateOrCreate(['code' => 'DHL'], ['name' => 'DHL Express']);

        $acme = $this->organization('Acme Shipping', 'acme-shipping', 'saif@example.com', 'Saif (Acme Owner)');
        $globex = $this->organization('Globex Freight', 'globex-freight', 'other@example.com', 'Other (Globex Owner)');

        $current = app(CurrentOrganization::class);

        // Seeders run outside a request, so the organization in context has to
        // be set explicitly. The tenancy trait then scopes every query and
        // fills organization_id on every insert.
        $current->set($acme);
        $this->seedAcme($ups);
        $current->forget();

        $current->set($globex);
        $this->seedGlobex($fedex);
        $current->forget();
    }

    /**
     * Create the organization and its owner, idempotently.
     */
    private function organization(string $name, string $slug, string $ownerEmail, string $ownerName): Organization
    {
        $organization = Organization::firstOrCreate(['slug' => $slug], ['name' => $name]);

        $owner = User::firstOrCreate(
            ['email' => $ownerEmail],
            ['name' => $ownerName, 'password' => 'password'],
        );

        $owner->organizations()->syncWithoutDetaching([
            $organization->id => ['role' => Role::Owner->value],
        ]);

        return $organization;
    }

    /**
     * Acme Shipping: one UPS contract, 20 shipments and one invoice with 50 lines.
     *
     * ---------------------------------------------------------------------
     * PLANTED ERRORS — Phase 3 audit rules are expected to catch exactly these:
     *
     * (a) Dimensional-weight overcharge: 3 lines billed at 8.00 kg although the
     *     shipments' dimensions (40 x 30 x 20 cm) only justify 4.80 kg.
     *     Recoverable: 3 x 900 = 2700 cents.
     * (b) Duplicate charge: the same tracking_number billed twice with the same
     *     charge_type (fuel on 1ZACME0001, residential on 1ZACME0002).
     *     Recoverable: 186 + 495 = 681 cents.
     * (c) Disallowed surcharge: 2 "demand" lines, but the contract only allows
     *     fuel and residential.
     *     Recoverable: 2 x 1250 = 2500 cents.
     *
     * Expected recoverable total = 2700 + 681 + 2500 = 5881 cents ($58.81).
     * ---------------------------------------------------------------------
     */
    private function seedAcme(Carrier $ups): void
    {
        $contract = Contract::updateOrCreate(
            ['carrier_id' => $ups->id, 'name' => 'Acme / UPS Ground 2026'],
            ['effective_from' => '2026-01-01', 'effective_to' => null, 'dim_divisor' => 5000],
        );

        // Weight bands (kilograms -> integer cents).
        $bands = [
            ['min' => 0.00, 'max' => 1.00, 'cents' => 950],
            ['min' => 1.01, 'max' => 5.00, 'cents' => 1450],
            ['min' => 5.01, 'max' => 10.00, 'cents' => 2150],
            ['min' => 10.01, 'max' => 25.00, 'cents' => 3400],
        ];

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

        // The contract allows fuel and residential only. "demand" is missing
        // on purpose: that is what planted error (c) bills for.
        $allowedSurcharges = ['fuel' => 1200, 'residential' => 495];

        foreach ($allowedSurcharges as $chargeType => $cents) {
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

        $shipments = [];

        for ($i = 1; $i <= 20; $i++) {
            $shipments[$i] = Shipment::updateOrCreate(
                ['tracking_number' => sprintf('1ZACME%04d', $i)],
                [
                    'carrier_id' => $ups->id,
                    'contract_id' => $contract->id,
                    'service_level' => 'GROUND',
                    'ship_date' => sprintf('2026-02-%02d', $i),
                    'origin_zip' => '94107',
                    'destination_zip' => '10001',
                    'actual_weight_kg' => 3.00,
                    // 40 x 30 x 20 cm => 24000 / 5000 = 4.80 kg dimensional weight.
                    'length_cm' => 40.00,
                    'width_cm' => 30.00,
                    'height_cm' => 20.00,
                ],
            );
        }

        $invoice = Invoice::updateOrCreate(
            ['carrier_id' => $ups->id, 'invoice_number' => 'ACME-UPS-2026-0001'],
            [
                'contract_id' => $contract->id,
                'invoice_date' => '2026-02-28',
                'currency' => 'USD',
                'status' => 'imported',
                'source_file' => 'acme_ups_2026-0001.csv',
            ],
        );

        if ($invoice->lines()->count() === 0) {
            $lines = [];

            foreach ($shipments as $i => $shipment) {
                $base = 1500 + ($i * 50);

                $lines[] = [
                    'shipment_id' => $shipment->id,
                    'tracking_number' => $shipment->tracking_number,
                    'charge_type' => 'base',
                    'description' => 'Ground transportation',
                    'billed_weight_kg' => 3.00,
                    'amount_cents' => $base,
                ];

                $lines[] = [
                    'shipment_id' => $shipment->id,
                    'tracking_number' => $shipment->tracking_number,
                    'charge_type' => 'fuel',
                    'description' => 'Fuel surcharge',
                    'billed_weight_kg' => null,
                    'amount_cents' => (int) round($base * 0.12),
                ];
            }

            // Residential delivery is allowed by the contract.
            foreach ([1, 2, 3] as $i) {
                $lines[] = [
                    'shipment_id' => $shipments[$i]->id,
                    'tracking_number' => $shipments[$i]->tracking_number,
                    'charge_type' => 'residential',
                    'description' => 'Residential delivery',
                    'billed_weight_kg' => null,
                    'amount_cents' => 495,
                ];
            }

            // (a) Billed above the dimensional weight the dimensions justify.
            foreach ([1, 2, 3] as $i) {
                $lines[] = [
                    'shipment_id' => $shipments[$i]->id,
                    'tracking_number' => $shipments[$i]->tracking_number,
                    'charge_type' => 'dim_weight_adjustment',
                    'description' => 'Dimensional weight adjustment',
                    'billed_weight_kg' => 8.00,
                    'amount_cents' => 900,
                ];
            }

            // (b) Duplicates of an existing tracking_number + charge_type.
            $lines[] = [
                'shipment_id' => $shipments[1]->id,
                'tracking_number' => $shipments[1]->tracking_number,
                'charge_type' => 'fuel',
                'description' => 'Fuel surcharge (duplicate)',
                'billed_weight_kg' => null,
                'amount_cents' => (int) round((1500 + 50) * 0.12),
            ];
            $lines[] = [
                'shipment_id' => $shipments[2]->id,
                'tracking_number' => $shipments[2]->tracking_number,
                'charge_type' => 'residential',
                'description' => 'Residential delivery (duplicate)',
                'billed_weight_kg' => null,
                'amount_cents' => 495,
            ];

            // (c) A surcharge the contract does not allow.
            foreach ([4, 5] as $i) {
                $lines[] = [
                    'shipment_id' => $shipments[$i]->id,
                    'tracking_number' => $shipments[$i]->tracking_number,
                    'charge_type' => 'demand',
                    'description' => 'Demand surcharge',
                    'billed_weight_kg' => null,
                    'amount_cents' => 1250,
                ];
            }

            foreach ($lines as $line) {
                $invoice->lines()->create($line);
            }
        }

        $invoice->update(['total_cents' => (int) $invoice->lines()->sum('amount_cents')]);
    }

    /**
     * Globex Freight: a much smaller invoice, used to prove tenant isolation.
     */
    private function seedGlobex(Carrier $fedex): void
    {
        $shipments = [];

        for ($i = 1; $i <= 3; $i++) {
            $shipments[$i] = Shipment::updateOrCreate(
                ['tracking_number' => sprintf('GFX%08d', $i)],
                [
                    'carrier_id' => $fedex->id,
                    'contract_id' => null,
                    'service_level' => 'PRIORITY',
                    'ship_date' => sprintf('2026-03-%02d', $i),
                    'origin_zip' => '75001',
                    'destination_zip' => '30301',
                    'actual_weight_kg' => 5.50,
                    'length_cm' => 50.00,
                    'width_cm' => 40.00,
                    'height_cm' => 30.00,
                ],
            );
        }

        $invoice = Invoice::updateOrCreate(
            ['carrier_id' => $fedex->id, 'invoice_number' => 'GLOBEX-FDX-2026-0001'],
            [
                'contract_id' => null,
                'invoice_date' => '2026-03-31',
                'currency' => 'USD',
                'status' => 'imported',
                'source_file' => 'globex_fedex_2026-0001.csv',
            ],
        );

        if ($invoice->lines()->count() === 0) {
            foreach ($shipments as $shipment) {
                $invoice->lines()->create([
                    'shipment_id' => $shipment->id,
                    'tracking_number' => $shipment->tracking_number,
                    'charge_type' => 'base',
                    'description' => 'Priority overnight',
                    'billed_weight_kg' => 5.50,
                    'amount_cents' => 4200,
                ]);

                $invoice->lines()->create([
                    'shipment_id' => $shipment->id,
                    'tracking_number' => $shipment->tracking_number,
                    'charge_type' => 'fuel',
                    'description' => 'Fuel surcharge',
                    'billed_weight_kg' => null,
                    'amount_cents' => 504,
                ]);
            }
        }

        $invoice->update(['total_cents' => (int) $invoice->lines()->sum('amount_cents')]);
    }
}
