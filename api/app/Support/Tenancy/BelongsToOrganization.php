<?php

namespace App\Support\Tenancy;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Scopes a model to the organization that is currently in context.
 *
 * The global scope only filters while a current organization is set. Outside an
 * HTTP request — queue jobs, seeders, console commands, scheduled tasks — no
 * organization is in context, so the scope is inert and reads are NOT isolated.
 *
 * Those background contexts MUST therefore set the organization explicitly,
 * either with CurrentOrganization::set($organization) or by passing
 * organization_id directly. Creating a model without either throws a
 * LogicException rather than silently writing an unscoped row.
 *
 * Use static::withoutTenancy() when a deliberate cross-tenant query is needed.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $builder) {
            $organizationId = app(CurrentOrganization::class)->id();

            if ($organizationId !== null) {
                $builder->where(
                    $builder->getModel()->getTable().'.organization_id',
                    $organizationId
                );
            }
        });

        static::creating(function (Model $model) {
            if ($model->getAttribute('organization_id') !== null) {
                return;
            }

            $organizationId = app(CurrentOrganization::class)->id();

            if ($organizationId === null) {
                throw new LogicException(sprintf(
                    'Cannot create [%s] without an organization: set organization_id explicitly or call CurrentOrganization::set() first.',
                    $model::class
                ));
            }

            $model->setAttribute('organization_id', $organizationId);
        });
    }

    /**
     * The organization this record belongs to.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Query builder without the tenancy global scope, for deliberate
     * cross-tenant access (seeders, admin tooling, audit jobs).
     */
    public static function withoutTenancy(): Builder
    {
        return static::withoutGlobalScope('organization');
    }
}
