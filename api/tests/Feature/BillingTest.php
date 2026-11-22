<?php

namespace Tests\Feature;

use App\Domain\Billing\Models\Subscription;
use App\Domain\Invoices\Models\Invoice;
use App\Enums\Role;
use App\Models\Carrier;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Covers the Billing API: the overview, the owner-only mutations, the
 * unauthenticated / authorization / organization-isolation boundaries and the
 * validation of the checkout payload.
 *
 * The plan catalogue is supplied through configuration, so the tests set
 * config('rateguard.billing.plans') explicitly instead of relying on a default.
 */
class BillingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The plan catalogue the tests publish.
     *
     * @var list<array{code: string, name: string, price_cents: int, invoice_limit: int|null}>
     */
    private const PLANS = [
        ['code' => 'basic', 'name' => 'Basic', 'price_cents' => 4900, 'invoice_limit' => 50],
        ['code' => 'pro', 'name' => 'Pro', 'price_cents' => 9900, 'invoice_limit' => null],
    ];

    /**
     * @return array{0: User, 1: Organization}
     */
    private function organizationWithUser(Role $role): array
    {
        $user = User::factory()->create();

        $organization = Organization::create([
            'name' => 'Acme Logistics',
            'slug' => 'acme-logistics-'.Str::lower(Str::random(8)),
        ]);

        $user->organizations()->attach($organization->id, ['role' => $role->value]);

        return [$user, $organization];
    }

    /**
     * A subscription for the given organization, created without an HTTP
     * request so the organization_id is passed explicitly.
     */
    private function subscription(Organization $organization, array $attributes = []): Subscription
    {
        return Subscription::create(array_merge([
            'organization_id' => $organization->id,
            'plan_code' => 'basic',
            'status' => Subscription::STATUS_ACTIVE,
            'renews_at' => '2026-12-01',
            'ends_at' => null,
        ], $attributes));
    }

    /**
     * An invoice belonging to the given organization (created outside tenancy).
     */
    private function invoice(Organization $organization, string $number): Invoice
    {
        $carrier = Carrier::query()->firstOrCreate(['code' => 'UPS'], ['name' => 'UPS Carrier']);

        return Invoice::create([
            'organization_id' => $organization->id,
            'carrier_id' => $carrier->id,
            'invoice_number' => $number,
            'invoice_date' => '2026-02-01',
            'currency' => 'USD',
            'total_cents' => 100,
            'status' => 'audited',
        ]);
    }

    public function test_owner_can_view_the_billing_overview(): void
    {
        config(['rateguard.billing.plans' => self::PLANS]);

        [$user] = $this->organizationWithUser(Role::Owner);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/billing')
            ->assertOk()
            ->assertJsonStructure(['plan', 'status', 'renews_at', 'ends_at', 'invoices_used', 'plans'])
            // The frontend billingOverviewSchema expects a flat object, not a
            // "data" envelope.
            ->assertJsonMissingPath('data')
            ->assertJsonPath('plan', null)
            ->assertJsonPath('status', null)
            ->assertJsonPath('renews_at', null)
            ->assertJsonPath('ends_at', null)
            ->assertJsonPath('invoices_used', 0)
            ->assertJsonCount(2, 'plans');
    }

    public function test_member_can_view_the_billing_overview(): void
    {
        config(['rateguard.billing.plans' => self::PLANS]);

        [$user] = $this->organizationWithUser(Role::Member);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/billing')
            ->assertOk();
    }

    public function test_overview_reports_the_current_plan_and_catalogue_from_config(): void
    {
        config(['rateguard.billing.plans' => self::PLANS]);

        [$user, $organization] = $this->organizationWithUser(Role::Owner);
        $this->subscription($organization);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/billing')
            ->assertOk()
            ->assertJsonPath('plan.code', 'basic')
            ->assertJsonPath('plan.name', 'Basic')
            ->assertJsonPath('plan.price_cents', 4900)
            ->assertJsonPath('plan.invoice_limit', 50)
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('renews_at', '2026-12-01')
            ->assertJsonPath('plans.1.code', 'pro')
            ->assertJsonPath('plans.1.invoice_limit', null);
    }

    public function test_overview_counts_only_the_current_organizations_invoices(): void
    {
        config(['rateguard.billing.plans' => self::PLANS]);

        [$user, $organization] = $this->organizationWithUser(Role::Owner);
        [, $other] = $this->organizationWithUser(Role::Owner);

        $this->invoice($organization, 'ACME-1');
        $this->invoice($organization, 'ACME-2');
        $this->invoice($other, 'OTHER-1');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/billing')
            ->assertOk()
            ->assertJsonPath('invoices_used', 2);
    }

    public function test_overview_reports_the_current_organizations_subscription_only(): void
    {
        config(['rateguard.billing.plans' => self::PLANS]);

        [$user, $organization] = $this->organizationWithUser(Role::Owner);
        [, $other] = $this->organizationWithUser(Role::Owner);

        $this->subscription($organization, ['status' => Subscription::STATUS_ACTIVE]);
        $this->subscription($other, [
            'plan_code' => 'pro',
            'status' => Subscription::STATUS_CANCELED,
            'ends_at' => '2026-12-31',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/billing')
            ->assertOk()
            ->assertJsonPath('status', Subscription::STATUS_ACTIVE)
            ->assertJsonPath('plan.code', 'basic');
    }

    public static function nonOwnerRoles(): array
    {
        return [
            'admin' => [Role::Admin->value],
            'member' => [Role::Member->value],
        ];
    }

    #[DataProvider('nonOwnerRoles')]
    public function test_non_owners_cannot_change_the_subscription(string $role): void
    {
        [$user, $organization] = $this->organizationWithUser(Role::from($role));
        $this->subscription($organization);

        $this->actingAs($user, 'sanctum');

        // Reading stays allowed.
        $this->getJson('/api/v1/billing')->assertOk();

        $this->postJson('/api/v1/billing/checkout', ['plan' => 'basic'])->assertForbidden();
        $this->postJson('/api/v1/billing/portal')->assertForbidden();
        $this->postJson('/api/v1/billing/cancel')->assertForbidden();
        $this->postJson('/api/v1/billing/resume')->assertForbidden();

        // The forbidden requests changed nothing.
        $this->assertDatabaseHas('subscriptions', [
            'organization_id' => $organization->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
    }

    public function test_owner_can_cancel_and_then_resume_the_subscription(): void
    {
        config(['rateguard.billing.plans' => self::PLANS]);

        [$user, $organization] = $this->organizationWithUser(Role::Owner);
        $this->subscription($organization);

        $this->actingAs($user, 'sanctum');

        $this->postJson('/api/v1/billing/cancel')->assertNoContent();

        // Cancelling ends the subscription at the close of the current period.
        $subscription = Subscription::withoutTenancy()
            ->where('organization_id', $organization->id)
            ->firstOrFail();

        $this->assertSame(Subscription::STATUS_CANCELED, $subscription->status);
        $this->assertSame('2026-12-01', $subscription->ends_at?->toDateString());

        $this->postJson('/api/v1/billing/resume')->assertNoContent();

        $subscription->refresh();

        $this->assertSame(Subscription::STATUS_ACTIVE, $subscription->status);
        $this->assertNull($subscription->ends_at);
    }

    public function test_cancel_without_a_subscription_is_a_conflict(): void
    {
        [$user] = $this->organizationWithUser(Role::Owner);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/billing/cancel')
            ->assertStatus(409);
    }

    public function test_resume_without_a_subscription_is_a_conflict(): void
    {
        [$user] = $this->organizationWithUser(Role::Owner);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/billing/resume')
            ->assertStatus(409);
    }

    public function test_checkout_requires_a_plan(): void
    {
        config(['rateguard.billing.plans' => self::PLANS]);

        [$user] = $this->organizationWithUser(Role::Owner);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/billing/checkout', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('plan');
    }

    public function test_checkout_rejects_a_plan_outside_the_catalogue(): void
    {
        config(['rateguard.billing.plans' => self::PLANS]);

        [$user] = $this->organizationWithUser(Role::Owner);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/billing/checkout', ['plan' => 'enterprise'])
            ->assertStatus(422);
    }

    public function test_checkout_with_a_valid_plan_reports_not_implemented(): void
    {
        config(['rateguard.billing.plans' => self::PLANS]);

        [$user] = $this->organizationWithUser(Role::Owner);

        // No payment provider is integrated, so the endpoint must not fabricate
        // a checkout URL.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/billing/checkout', ['plan' => 'pro'])
            ->assertStatus(501);
    }

    public function test_checkout_returns_a_stable_payments_not_available_body(): void
    {
        config(['rateguard.billing.plans' => self::PLANS]);

        // Auth is unchanged: an anonymous caller is still rejected.
        $this->postJson('/api/v1/billing/checkout', ['plan' => 'pro'])->assertUnauthorized();

        [$user] = $this->organizationWithUser(Role::Owner);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/billing/checkout', ['plan' => 'pro'])
            ->assertStatus(501)
            ->assertJsonPath('error.code', 'payments_not_available')
            ->assertJsonPath('error.message', 'Online payments are not available yet.')
            ->assertJsonStructure(['error' => ['code', 'message']]);
    }

    public function test_portal_reports_not_implemented(): void
    {
        [$user] = $this->organizationWithUser(Role::Owner);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/billing/portal')
            ->assertStatus(501);
    }

    public function test_request_with_foreign_organization_header_is_forbidden(): void
    {
        [$user] = $this->organizationWithUser(Role::Owner);

        $foreign = Organization::create([
            'name' => 'Other Company',
            'slug' => 'other-company-'.Str::lower(Str::random(8)),
        ]);

        $this->actingAs($user, 'sanctum');

        $this->withHeader('X-Organization-Id', (string) $foreign->id)
            ->getJson('/api/v1/billing')
            ->assertForbidden();

        $this->withHeader('X-Organization-Id', (string) $foreign->id)
            ->postJson('/api/v1/billing/cancel')
            ->assertForbidden();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/billing')->assertUnauthorized();
        $this->postJson('/api/v1/billing/checkout', ['plan' => 'basic'])->assertUnauthorized();
        $this->postJson('/api/v1/billing/portal')->assertUnauthorized();
        $this->postJson('/api/v1/billing/cancel')->assertUnauthorized();
        $this->postJson('/api/v1/billing/resume')->assertUnauthorized();
    }
}
