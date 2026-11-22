<?php

namespace Database\Seeders;

use App\Domain\Contracts\Models\Contract;
use App\Domain\Shipments\Models\Shipment;
use App\Models\Carrier;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Seeder;

/**
 * Seeds the shipments for organization 40 (Opsera) that its uploaded invoices
 * reference by tracking number.
 *
 * Run manually only — it is deliberately NOT listed in DatabaseSeeder:
 *
 *     php artisan db:seed --class=ShipmentSeeder
 *
 * Why this exists: the dimensional-weight rule can only judge a billed line
 * when the shipment behind its tracking number is known — NormalizeInvoiceJob
 * links `shipment_id` from the shipments table, and the rule skips any line
 * without one. With no shipments, an invoice can only ever report
 * "0 findings".
 *
 * Idempotent: shipments are unique per (organization_id, carrier_id,
 * tracking_number), so re-running updates the same rows.
 */
class ShipmentSeeder extends Seeder
{
    /**
     * The organization these shipments belong to.
     */
    private const ORGANIZATION_ID = 40;

    /**
     * All of the invoices in question were billed by UPS.
     */
    private const CARRIER_CODE = 'UPS';

    private const SERVICE_LEVEL = 'GROUND';

    /**
     * The shipments all predate the invoices that bill them, which is the
     * normal order of events.
     */
    private const SHIP_DATE = '2026-11-18';

    private const ORIGIN_ZIP = '90001';

    private const DESTINATION_ZIP = '10001';

    /**
     * The physical parcels behind the invoices' tracking numbers, keyed by
     * tracking number.
     *
     * Weight is kilograms, dimensions are centimetres. They are deliberately
     * smaller than the weights the invoices billed, which is exactly the
     * overcharge the dimensional-weight rule is meant to surface: the expected
     * billable weight is the greater of actual and dimensional weight, rounded
     * up to the next kilogram (config('rateguard.weight_rounding')).
     *
     * @var array<string, array{actual: string, length: int, width: int, height: int}>
     */
    private const SHIPMENTS = [
        'TRK10001' => ['actual' => '0.70', 'length' => 20, 'width' => 15, 'height' => 15],
        'TRK10002' => ['actual' => '0.85', 'length' => 22, 'width' => 15, 'height' => 15],
        'TRK10003' => ['actual' => '0.80', 'length' => 20, 'width' => 15, 'height' => 15],
        'TRK10004' => ['actual' => '2.50', 'length' => 30, 'width' => 25, 'height' => 15],
        'TRK10005' => ['actual' => '6.50', 'length' => 40, 'width' => 30, 'height' => 25],
        'TRK10006' => ['actual' => '0.90', 'length' => 20, 'width' => 15, 'height' => 15],
        'TRK10007' => ['actual' => '0.70', 'length' => 20, 'width' => 15, 'height' => 15],
        'TRK10008' => ['actual' => '3.20', 'length' => 35, 'width' => 25, 'height' => 20],
        'TRK10009' => ['actual' => '1.90', 'length' => 25, 'width' => 20, 'height' => 15],
        'TRK10010' => ['actual' => '4.50', 'length' => 40, 'width' => 30, 'height' => 20],
    ];

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
            $carrier = Carrier::query()->where('code', self::CARRIER_CODE)->first();

            if ($carrier === null) {
                $this->command?->warn(sprintf(
                    'Carrier [%s] was not found; nothing to seed.',
                    self::CARRIER_CODE,
                ));

                return;
            }

            // The contract that governs these shipments, resolved from the same
            // lookup the audit uses so the two can never drift apart.
            $contract = Contract::matchingFor((int) $carrier->id, self::SHIP_DATE);

            foreach (self::SHIPMENTS as $trackingNumber => $parcel) {
                Shipment::updateOrCreate(
                    [
                        'carrier_id' => $carrier->id,
                        'tracking_number' => $trackingNumber,
                    ],
                    [
                        'contract_id' => $contract?->getKey(),
                        'service_level' => self::SERVICE_LEVEL,
                        'ship_date' => self::SHIP_DATE,
                        'origin_zip' => self::ORIGIN_ZIP,
                        'destination_zip' => self::DESTINATION_ZIP,
                        'actual_weight_kg' => $parcel['actual'],
                        'length_cm' => $parcel['length'],
                        'width_cm' => $parcel['width'],
                        'height_cm' => $parcel['height'],
                    ],
                );
            }

            $this->command?->info(sprintf(
                'Seeded %d shipments for organization [%d] / carrier [%s].',
                count(self::SHIPMENTS),
                self::ORGANIZATION_ID,
                self::CARRIER_CODE,
            ));
        } finally {
            $current->forget();
        }
    }
}
