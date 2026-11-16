<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    /**
     * Any member of the organization may view it.
     */
    public function view(User $user, Organization $organization): bool
    {
        return $user->roleIn($organization) !== null;
    }

    /**
     * Admins and owners may update the organization.
     */
    public function update(User $user, Organization $organization): bool
    {
        return $user->roleIn($organization)?->atLeast(Role::Admin) ?? false;
    }

    /**
     * Admins and owners may manage the organization's members.
     */
    public function manageMembers(User $user, Organization $organization): bool
    {
        return $user->roleIn($organization)?->atLeast(Role::Admin) ?? false;
    }

    /**
     * Only owners may delete the organization.
     */
    public function delete(User $user, Organization $organization): bool
    {
        return $user->roleIn($organization)?->atLeast(Role::Owner) ?? false;
    }
}
