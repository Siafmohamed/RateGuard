<?php

namespace Tests\Feature;

use App\Domain\Billing\Models\Subscription;
use App\Domain\Invoices\Jobs\NormalizeInvoiceJob;
use App\Domain\Invoices\Jobs\ParseInvoiceJob;
use App\Domain\Invoices\Models\Invoice;
use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Carrier;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the plan catalogue, the default free subscription and the invoice
 * quota enforcement added on top of the billing slice:
 *
 *  - the read-only plan catalogue endpoint,
 *  - the free/active subscription every new organization starts on,
 *  - `billing:backfill-free-plans` (additive, idempotent, --dry-run),
 *  - the upload quota: enforced per period before any file is stored or job is
 *    queued, never blocks enterprise, never counts failed uploads and never
 *    charges a re-audit.
 *
 * The catalogue is configuration, so the tests publish a small one (free = 2,
 * pro = 5, enterprise = unlimited) instead of relying on the shipped numbers.
 */
class PlanLimitTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<array{code: string, name: string, price_cents: int, invoice_limit: int|null, features: array<string, bool>}>
     */
    private const PLANS = [
        ['code' => 'free', 'name' => 'Free', 'price_cents' => 0, 'invoice_limit' => 2, 'features' => []],
        ['code' => 'pro', 'name' => 'Pro', 'price_cents' => 9900, 'invoice_limit' => 5, 'features' => []],
        ['code' => 'enterprise', 'name' => 'Enterprise', 'price_cents' => 49900, 'invoice_limit' => null, 'features' => []],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'rateguard.billing.plans' => self::PLANS,
            'rateguard.billing.default_plan' => 'free',
        ]);
    }

    /**
     * @return array{0: User, 1: Organization}
     */
    private function organizationWithUser(Role $role = Role::Owner): array
    {
        $user = User::factory()->create();

        $organization = Organization::create([
            'name' => 'Acme Shipping',
            'slug' => 'acme-shipping-'.Str::lower(Str::random(8)),
        ]);

        $user->organizations()->attach($organization->id, ['role' => $role->value]);

        return [$user, $organization];
    }

    private function carrier(string $code = 'UPS'): Carrier
    {
        return Carrier::create(['code' => $code, 'name' => $code.' Carrier']);
    }

    /**
     * A subscription for the given organization, created outside a request so
     * the organization_id is passed explicitly.
     */
    private function subscription(Organization $organization, string $planCode = 'free'): Subscription
    {
        return Subscription::create([
            'organization_id' => $organization->id,
            'plan_code' => $planCode,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
    }

    /**
     * An invoice belonging to the given organization, in the current period.
     */
    private function invoice(
        Organization $organization,
        string $number,
        string $status = InvoiceStatus::Audited->value,
    ): Invoice {
        $carrier = Carrier::query()->firstOrCreate(['code' => 'UPS'], ['name' => 'UPS Carrier']);

        return Invoice::create([
            'organization_id' => $organization->id,
            'carrier_id' => $carrier->id,
            'invoice_number' => $number,
            'invoice_date' => '2026-02-01',
            'currency' => 'USD',
            'total_cents' => 100,
            'status' => $status,
        ]);
    }

    private function csv(): string
    {
        return implode("\n", [
            'tracking_number,charge_type,description,billed_weight_kg,amount',
            '1ZTEST0001,shipping,Standard delivery,1.5,85.00',
        ]);
    }

    /**
     * Upload a carrier invoice through the real endpoint.
     */
    private function upload(User $user, string $number = 'ACME-UPS-0001')
    {
        // A real SPA replays the CSRF token first; this test targets the
        // endpoint's behaviour, not CSRF protection.
        $this->withoutMiddleware(ValidateCsrfToken::class);

        return $this->actingAs($user, 'sanctum')->post('/api/v1/invoices/upload', [
            'file' => UploadedFile::fake()->createWithContent('invoice.csv', $this->csv()),
            'carrier' => 'UPS',
            'invoice_number' => $number,
            'invoice_date' => '2026-02-28',
        ], ['Accept' => 'application/json']);
    }

    public function test_the_plan_catalogue_is_served_read_only_from_configuration(): void
    {
        [$user] = $this->organizationWithUser(Role::Member);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/billing/plans')
            ->assertOk()
            ->assertJsonCount(3, 'plans')
            ->assertJsonPath('plans.0.code', 'free')
            ->assertJsonPath('plans.0.invoice_limit', 2)
            ->assertJsonPath('plans.2.code', 'enterprise')
            ->assertJsonPath('plans.2.invoice_limit', null);
    }

    public function test_an_organization_without_a_subscription_falls_back_to_the_free_plan(): void
    {
        [, $organization] = $this->organizationWithUser();

        // No subscription row exists, yet the plan is never null.
        $this->assertSame('free', $organization->currentPlan()['code']);
        $this->assertSame(2, $organization->currentPlan()['invoice_limit']);
    }

    public function test_a_newly_registered_organization_starts_on_the_free_plan(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->withHeaders(['Referer' => 'http://localhost:8000'])
            ->postJson('/api/v1/auth/register', [
                'name' => 'Jane Shipper',
                'email' => 'jane@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'organization_name' => 'Jane Logistics',
            ])
            ->assertCreated();

        $organization = Organization::query()->firstOrFail();

        $this->assertDatabaseHas('subscriptions', [
            'organization_id' => $organization->id,
            'plan_code' => 'free',
            'status' => Subscription::STATUS_ACTIVE,
        ]);
    }

    public function test_backfill_creates_a_free_subscription_only_for_organizations_that_have_none(): void
    {
        [, $withPlan] = $this->organizationWithUser();
        $this->subscription($withPlan, 'pro');

        [, $withoutPlan] = $this->organizationWithUser();

        $this->artisan('billing:backfill-free-plans')->assertSuccessful();

        // The organization that already had a subscription keeps it untouched.
        $this->assertDatabaseHas('subscriptions', [
            'organization_id' => $withPlan->id,
            'plan_code' => 'pro',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        // The one missing a subscription gets the configured default plan.
        $this->assertDatabaseHas('subscriptions', [
            'organization_id' => $withoutPlan->id,
            'plan_code' => 'free',
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        $this->assertSame(2, Subscription::withoutTenancy()->count());
    }

    public function test_backfill_is_idempotent(): void
    {
        $this->organizationWithUser();

        $this->artisan('billing:backfill-free-plans')->assertSuccessful();
        $this->artisan('billing:backfill-free-plans')->assertSuccessful();

        $this->assertSame(1, Subscription::withoutTenancy()->count());
    }

    public function test_backfill_dry_run_lists_the_changes_without_writing_them(): void
    {
        $this->organizationWithUser();

        $this->artisan('billing:backfill-free-plans', ['--dry-run' => true])
            ->expectsOutputToContain('would create')
            ->assertSuccessful();

        $this->assertSame(0, Subscription::withoutTenancy()->count());
    }

    public function test_an_upload_below_the_plan_limit_creates_the_invoice_and_dispatches_the_pipeline(): void
    {
        Storage::fake('local');
        Bus::fake();
        $this->carrier();

        [$user, $organization] = $this->organizationWithUser();
        $this->subscription($organization, 'free');

        // 1 of the 2 allowed invoices for the period.
        $this->invoice($organization, 'ACME-1');

        $this->upload($user, 'ACME-UPS-0001')->assertStatus(202);

        $this->assertDatabaseHas('invoices', [
            'organization_id' => $organization->id,
            'invoice_number' => 'ACME-UPS-0001',
        ]);

        // The pipeline starts (parse is the head of the chain).
        Bus::assertDispatched(ParseInvoiceJob::class);
    }

    public function test_reaching_the_plan_limit_blocks_the_upload_with_no_invoice_and_no_job(): void
    {
        Storage::fake('local');
        Bus::fake();
        $this->carrier();

        [$user, $organization] = $this->organizationWithUser();
        $this->subscription($organization, 'free');

        $this->invoice($organization, 'ACME-1');
        $this->invoice($organization, 'ACME-2'); // now at the limit of 2

        $before = Invoice::withoutTenancy()->count();

        $this->upload($user, 'ACME-UPS-0001')
            ->assertStatus(402)
            ->assertJsonPath('error.code', 'plan_limit_reached')
            ->assertJsonPath('error.limit', 2)
            ->assertJsonPath('error.used', 2)
            ->assertJsonPath('error.plan', 'free');

        // The blocked upload left no invoice row, no stored file and no queued work.
        $this->assertSame($before, Invoice::withoutTenancy()->count());
        $this->assertDatabaseMissing('invoices', ['invoice_number' => 'ACME-UPS-0001']);
        $this->assertSame([], Storage::disk('local')->allFiles());
        Bus::assertNothingDispatched();
    }

    public function test_failed_invoices_do_not_consume_the_plan_quota(): void
    {
        Storage::fake('local');
        Bus::fake();
        $this->carrier();

        [$user, $organization] = $this->organizationWithUser();
        $this->subscription($organization, 'free');

        // Two failed uploads in the period produced nothing usable.
        $this->invoice($organization, 'FAILED-1', InvoiceStatus::Failed->value);
        $this->invoice($organization, 'FAILED-2', InvoiceStatus::Failed->value);

        $this->upload($user, 'ACME-UPS-0001')->assertStatus(202);

        $this->assertDatabaseHas('invoices', ['invoice_number' => 'ACME-UPS-0001']);
    }

    public function test_re_running_an_audit_at_the_limit_does_not_consume_quota(): void
    {
        Bus::fake();
        $this->carrier();

        [$user, $organization] = $this->organizationWithUser();
        $this->subscription($organization, 'free');

        $first = $this->invoice($organization, 'ACME-1');
        $this->invoice($organization, 'ACME-2'); // at the limit

        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/invoices/'.$first->id.'/audit')
            ->assertStatus(202);

        // A re-audit is not an upload: the quota is unchanged.
        $this->assertSame(2, Invoice::withoutTenancy()->count());
        Bus::assertDispatched(NormalizeInvoiceJob::class);
    }

    public function test_the_enterprise_plan_is_unlimited(): void
    {
        Storage::fake('local');
        Bus::fake();
        $this->carrier();

        [$user, $organization] = $this->organizationWithUser();
        $this->subscription($organization, 'enterprise'); // null invoice_limit

        foreach (range(1, 10) as $i) {
            $this->invoice($organization, 'ACME-'.$i);
        }

        $this->upload($user, 'ACME-UPS-0001')->assertStatus(202);

        $this->assertDatabaseHas('invoices', ['invoice_number' => 'ACME-UPS-0001']);
    }

    public function test_one_organizations_limit_does_not_affect_another(): void
    {
        Storage::fake('local');
        Bus::fake();
        $this->carrier();

        [$ownerA, $orgA] = $this->organizationWithUser();
        $this->subscription($orgA, 'free');

        [$ownerB, $orgB] = $this->organizationWithUser();
        $this->subscription($orgB, 'free');

        // Organization A is at its limit.
        $this->invoice($orgA, 'A-1');
        $this->invoice($orgA, 'A-2');

        $this->upload($ownerA, 'A-NEW')->assertStatus(402);

        // Organization B is unaffected.
        $this->upload($ownerB, 'B-NEW')->assertStatus(202);

        $this->assertDatabaseHas('invoices', [
            'organization_id' => $orgB->id,
            'invoice_number' => 'B-NEW',
        ]);
        $this->assertDatabaseMissing('invoices', [
            'organization_id' => $orgA->id,
            'invoice_number' => 'A-NEW',
        ]);
    }
}
