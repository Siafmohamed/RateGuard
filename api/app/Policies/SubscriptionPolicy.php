<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

/**
 * Billing is tenant data: every ability is evaluated against the organization
 * that is currently in context, exactly like the other organization-scoped
 * policies.
 */
class SubscriptionPolicy extends OrganizationScopedPolicy
{
    /**
     * Any member of the organization may view the billing overview (the plan
     * cards are shown to everyone so the upgrade path is discoverable).
     */
    public function viewAny(User $user): bool
    {
        return $this->currentOrganization() !== null;
    }

    /**
     * Only owners may change the subscription: checkout, the customer portal,
     * cancel and resume. This mirrors the frontend's owner-only "billing"
     * permission.
     */
    public function manage(User $user): bool
    {
        $organization = $this->currentOrganization();

        return $organization !== null
            && ($user->roleIn($organization)?->atLeast(Role::Owner) ?? false);
    }
}
