<?php

namespace Tests\Feature;

use App\Domain\Invoices\Models\Invoice;
use App\Enums\Role;
use App\Models\Carrier;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    use RefreshDatabase;

    private function carrier(string $code = 'UPS'): Carrier
    {
        return Carrier::create(['code' => $code, 'name' => $code.' Carrier']);
    }

    private function organization(string $name): Organization
    {
        return Organization::create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
        ]);
    }

    private function member(Organization $organization, Role $role = Role::Owner): User
    {
        $user = User::factory()->create();

        $user->organizations()->attach($organization->id, ['role' => $role->value]);

        return $user;
    }

    /**
     * organization_id is passed explicitly here, which is what an unscoped
     * context (a test or a job) has to do.
     */
    private function invoice(Organization $organization, Carrier $carrier, string $number, int $totalCents): Invoice
    {
        return Invoice::create([
            'organization_id' => $organization->id,
            'carrier_id' => $carrier->id,
            'invoice_number' => $number,
            'invoice_date' => '2026-02-28',
            'currency' => 'USD',
            'total_cents' => $totalCents,
            'status' => 'imported',
        ]);
    }

    public function test_member_sees_only_its_own_organizations_invoices(): void
    {
        $carrier = $this->carrier();
        $acme = $this->organization('Acme Shipping');
        $globex = $this->organization('Globex Freight');

        $this->invoice($acme, $carrier, 'ACME-0001', 10000);
        $this->invoice($acme, $carrier, 'ACME-0002', 20000);
        $this->invoice($globex, $carrier, 'GLOBEX-0001', 30000);

        $user = $this->member($acme, Role::Member);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/invoices');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');

        $numbers = collect($response->json('data'))->pluck('invoice_number')->sort()->values()->all();

        $this->assertSame(['ACME-0001', 'ACME-0002'], $numbers);
    }

    public function test_another_organizations_invoice_returns_not_found(): void
    {
        $carrier = $this->carrier();
        $acme = $this->organization('Acme Shipping');
        $globex = $this->organization('Globex Freight');

        $foreign = $this->invoice($globex, $carrier, 'GLOBEX-0001', 30000);

        $user = $this->member($acme, Role::Member);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/invoices/'.$foreign->id)
            ->assertNotFound();
    }

    public function test_request_with_a_foreign_organization_header_is_forbidden(): void
    {
        $carrier = $this->carrier();
        $acme = $this->organization('Acme Shipping');
        $globex = $this->organization('Globex Freight');

        $this->invoice($acme, $carrier, 'ACME-0001', 10000);
        $this->invoice($globex, $carrier, 'GLOBEX-0001', 30000);

        $user = $this->member($acme, Role::Owner);

        $this->actingAs($user, 'sanctum')
            ->withHeader('X-Organization-Id', (string) $globex->id)
            ->getJson('/api/v1/invoices')
            ->assertForbidden();
    }

    public function test_creating_an_invoice_inside_the_tenancy_context_fills_organization_id(): void
    {
        $carrier = $this->carrier();
        $acme = $this->organization('Acme Shipping');

        app(CurrentOrganization::class)->set($acme);

        $invoice = Invoice::create([
            'carrier_id' => $carrier->id,
            'invoice_number' => 'ACME-9999',
            'invoice_date' => '2026-02-28',
            'currency' => 'USD',
            'total_cents' => 4200,
            'status' => 'imported',
        ]);

        $this->assertSame($acme->id, $invoice->organization_id);
        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'organization_id' => $acme->id,
        ]);

        app(CurrentOrganization::class)->forget();
    }

    public function test_invoice_queries_are_scoped_to_the_current_organization(): void
    {
        $carrier = $this->carrier();
        $acme = $this->organization('Acme Shipping');
        $globex = $this->organization('Globex Freight');

        $own = $this->invoice($acme, $carrier, 'ACME-0001', 10000);
        $this->invoice($globex, $carrier, 'GLOBEX-0001', 30000);

        app(CurrentOrganization::class)->set($acme);

        $this->assertSame([$own->id], Invoice::query()->pluck('id')->all());
        $this->assertSame(1, Invoice::query()->count());

        app(CurrentOrganization::class)->forget();

        // Without a current organization the scope is deliberately inert.
        $this->assertSame(2, Invoice::query()->count());
    }

    public function test_creating_a_tenant_model_without_an_organization_throws(): void
    {
        $carrier = $this->carrier();

        $this->expectException(LogicException::class);

        Invoice::create([
            'carrier_id' => $carrier->id,
            'invoice_number' => 'ORPHAN-0001',
            'invoice_date' => '2026-02-28',
            'currency' => 'USD',
            'total_cents' => 100,
            'status' => 'imported',
        ]);
    }

    public function test_carriers_are_global_and_visible_to_every_organization(): void
    {
        $this->carrier('UPS');
        $this->carrier('FEDEX');

        $acme = $this->organization('Acme Shipping');
        $globex = $this->organization('Globex Freight');

        $this->assertFalse(Schema::hasColumn('carriers', 'organization_id'));

        app(CurrentOrganization::class)->set($acme);
        $this->assertSame(2, Carrier::query()->count());

        app(CurrentOrganization::class)->set($globex);
        $this->assertSame(2, Carrier::query()->count());

        app(CurrentOrganization::class)->forget();
    }

    public function test_money_round_trips_as_integer_cents(): void
    {
        $carrier = $this->carrier();
        $acme = $this->organization('Acme Shipping');

        $invoice = $this->invoice($acme, $carrier, 'ACME-0001', 123456);

        $fresh = Invoice::withoutTenancy()->findOrFail($invoice->id);

        $this->assertIsInt($fresh->total_cents);
        $this->assertSame(123456, $fresh->total_cents);
        $this->assertSame('USD', $fresh->currency);

        // The raw column holds cents too, with no fractional part.
        $this->assertEquals(123456, DB::table('invoices')->where('id', $invoice->id)->value('total_cents'));
    }
}
