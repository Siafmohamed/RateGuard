<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrganizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a user attached to a fresh organization at the given role.
     *
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

    public static function privilegedRoles(): array
    {
        return [
            'admin' => [Role::Admin->value],
            'owner' => [Role::Owner->value],
        ];
    }

    public function test_register_creates_user_organization_and_owner_membership_and_logs_in(): void
    {
        // A real SPA calls /sanctum/csrf-cookie and replays the token first.
        // This test targets the endpoint's behaviour, not CSRF protection.
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $response = $this->withHeaders(['Referer' => 'http://localhost:8000'])
            ->postJson('/api/v1/auth/register', [
                'name' => 'Jane Shipper',
                'email' => 'jane@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'organization_name' => 'Acme Logistics',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('user.email', 'jane@example.com');

        $user = User::where('email', 'jane@example.com')->firstOrFail();
        $organization = Organization::firstOrFail();

        $this->assertSame('Acme Logistics', $organization->name);
        $this->assertStringStartsWith('acme-logistics-', $organization->slug);

        $this->assertDatabaseHas('organization_user', [
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'role' => Role::Owner->value,
        ]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_member_can_view_the_organization_but_cannot_update_it(): void
    {
        [$user, $organization] = $this->organizationWithUser(Role::Member);

        $this->actingAs($user, 'sanctum');

        $this->getJson('/api/v1/org')->assertOk();

        $this->patchJson('/api/v1/org', ['name' => 'Renamed'])->assertForbidden();

        $this->assertDatabaseHas('organizations', [
            'id' => $organization->id,
            'name' => 'Acme Logistics',
        ]);
    }

    #[DataProvider('privilegedRoles')]
    public function test_admins_and_owners_can_update_the_organization(string $role): void
    {
        [$user] = $this->organizationWithUser(Role::from($role));

        $this->actingAs($user, 'sanctum');

        $this->patchJson('/api/v1/org', ['name' => 'Updated Name'])
            ->assertOk()
            ->assertJsonPath('organization.name', 'Updated Name');

        $this->assertDatabaseHas('organizations', ['name' => 'Updated Name']);
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
            ->getJson('/api/v1/org')
            ->assertForbidden();

        $this->withHeader('X-Organization-Id', (string) $foreign->id)
            ->patchJson('/api/v1/org', ['name' => 'Hijacked'])
            ->assertForbidden();

        $this->assertDatabaseHas('organizations', [
            'id' => $foreign->id,
            'name' => 'Other Company',
        ]);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/org')->assertUnauthorized();

        $this->patchJson('/api/v1/org', ['name' => 'Nope'])->assertUnauthorized();
    }
}
