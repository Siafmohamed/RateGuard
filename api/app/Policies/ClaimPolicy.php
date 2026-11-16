<?php

namespace App\Policies;

use App\Domain\Claims\Models\Claim;
use App\Enums\Role;
use App\Models\User;

class ClaimPolicy extends OrganizationScopedPolicy
{
    /**
     * Any member of the organization may list claims.
     */
    public function viewAny(User $user): bool
    {
        return $this->currentOrganization() !== null;
    }

    /**
     * Any member of the organization may view a claim.
     */
    public function view(User $user, Claim $claim): bool
    {
        return $this->belongsToCurrentOrganization($claim)
            && $user->roleIn($claim->organization) !== null;
    }

    /**
     * Admins and owners may create claims.
     */
    public function create(User $user): bool
    {
        $organization = $this->currentOrganization();

        return $organization !== null
            && ($user->roleIn($organization)?->atLeast(Role::Admin) ?? false);
    }

    /**
     * Admins and owners may update a claim.
     */
    public function update(User $user, Claim $claim): bool
    {
        return $this->belongsToCurrentOrganization($claim)
            && ($user->roleIn($claim->organization)?->atLeast(Role::Admin) ?? false);
    }

    /**
     * Only owners may delete a claim.
     */
    public function delete(User $user, Claim $claim): bool
    {
        return $this->belongsToCurrentOrganization($claim)
            && ($user->roleIn($claim->organization)?->atLeast(Role::Owner) ?? false);
    }
}
