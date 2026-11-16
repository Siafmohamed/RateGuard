<?php

namespace App\Policies;

use App\Domain\Audit\Models\AuditFinding;
use App\Enums\Role;
use App\Models\User;

class AuditFindingPolicy extends OrganizationScopedPolicy
{
    /**
     * Any member of the organization may list audit findings.
     */
    public function viewAny(User $user): bool
    {
        return $this->currentOrganization() !== null;
    }

    /**
     * Any member of the organization may view an audit finding.
     */
    public function view(User $user, AuditFinding $finding): bool
    {
        return $this->belongsToCurrentOrganization($finding)
            && $user->roleIn($finding->organization) !== null;
    }

    /**
     * Admins and owners may create audit findings.
     */
    public function create(User $user): bool
    {
        $organization = $this->currentOrganization();

        return $organization !== null
            && ($user->roleIn($organization)?->atLeast(Role::Admin) ?? false);
    }

    /**
     * Admins and owners may update an audit finding.
     */
    public function update(User $user, AuditFinding $finding): bool
    {
        return $this->belongsToCurrentOrganization($finding)
            && ($user->roleIn($finding->organization)?->atLeast(Role::Admin) ?? false);
    }

    /**
     * Only owners may delete an audit finding.
     */
    public function delete(User $user, AuditFinding $finding): bool
    {
        return $this->belongsToCurrentOrganization($finding)
            && ($user->roleIn($finding->organization)?->atLeast(Role::Owner) ?? false);
    }
}
