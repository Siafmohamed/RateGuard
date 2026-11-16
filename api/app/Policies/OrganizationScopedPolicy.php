<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared rules for every tenant-owned model.
 *
 * viewAny / view  -> any member of the current organization
 * create / update -> admin or owner
 * delete          -> owner only
 *
 * On top of the tenancy global scope, every check also requires the record to
 * belong to the organization that is currently in context. That is defence in
 * depth: a record fetched through withoutTenancy() can still never be
 * authorized from another tenant's context.
 */
abstract class OrganizationScopedPolicy
{
    /**
     * The organization that is currently in context, if any.
     */
    protected function currentOrganization(): ?Organization
    {
        return app(CurrentOrganization::class)->get();
    }

    /**
     * Whether the record belongs to the organization that is in context.
     */
    protected function belongsToCurrentOrganization(Model $model): bool
    {
        $currentId = app(CurrentOrganization::class)->id();

        return $currentId !== null
            && (int) $model->getAttribute('organization_id') === $currentId;
    }
}
