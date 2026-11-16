<?php

namespace App\Policies;

use App\Domain\Contracts\Models\Contract;
use App\Enums\Role;
use App\Models\User;

class ContractPolicy extends OrganizationScopedPolicy
{
    /**
     * Any member of the organization may list contracts.
     */
    public function viewAny(User $user): bool
    {
        return $this->currentOrganization() !== null;
    }

    /**
     * Any member of the organization may view a contract.
     */
    public function view(User $user, Contract $contract): bool
    {
        return $this->belongsToCurrentOrganization($contract)
            && $user->roleIn($contract->organization) !== null;
    }

    /**
     * Admins and owners may create contracts.
     */
    public function create(User $user): bool
    {
        $organization = $this->currentOrganization();

        return $organization !== null
            && ($user->roleIn($organization)?->atLeast(Role::Admin) ?? false);
    }

    /**
     * Admins and owners may update a contract.
     */
    public function update(User $user, Contract $contract): bool
    {
        return $this->belongsToCurrentOrganization($contract)
            && ($user->roleIn($contract->organization)?->atLeast(Role::Admin) ?? false);
    }

    /**
     * Only owners may delete a contract.
     */
    public function delete(User $user, Contract $contract): bool
    {
        return $this->belongsToCurrentOrganization($contract)
            && ($user->roleIn($contract->organization)?->atLeast(Role::Owner) ?? false);
    }
}
