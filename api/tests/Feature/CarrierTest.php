<?php

namespace Tests\Feature;

use App\Models\Carrier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/v1/carriers - carrier reference data for the SPA upload form.
 *
 * The carriers table is GLOBAL: it has no organization_id and no tenancy
 * global scope, so the route is registered OUTSIDE the "org" middleware. It
 * needs auth, but no X-Organization-Id header, and every organization sees the
 * same rows.
 */
class CarrierTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authenticated_user_receives_the_carriers_ordered_by_code(): void
    {
        Carrier::create(['code' => 'UPS', 'name' => 'United Parcel Service']);
        Carrier::create(['code' => 'FEDEX', 'name' => 'FedEx']);
        Carrier::create(['code' => 'DHL', 'name' => 'DHL Express']);

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/carriers')
            ->assertOk();

        // Ordered by code: DHL, FEDEX, UPS.
        $response->assertJsonPath('data.0.code', 'DHL');
        $response->assertJsonPath('data.1.code', 'FEDEX');
        $response->assertJsonPath('data.2.code', 'UPS');

        // Exactly the three fields the picker needs.
        $response->assertJsonStructure([
            'data' => [['id', 'code', 'name']],
        ]);

        $this->assertCount(3, $response->json('data'));
    }

    public function test_it_works_without_an_organization_header_because_the_table_is_global(): void
    {
        Carrier::create(['code' => 'UPS', 'name' => 'United Parcel Service']);

        $user = User::factory()->create();

        // No X-Organization-Id and no organization membership on purpose.
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/carriers')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_an_empty_table_returns_an_empty_data_array(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/carriers')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/carriers')->assertUnauthorized();
    }
}
