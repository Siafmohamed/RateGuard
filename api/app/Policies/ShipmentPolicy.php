<?php

namespace App\Policies;

use App\Domain\Shipments\Models\Shipment;
use App\Enums\Role;
use App\Models\User;

class ShipmentPolicy extends OrganizationScopedPolicy
{
    /**
     * Any member of the organization may list shipments.
     */
    public function viewAny(User $user): bool
    {
        return $this->currentOrganization() !== null;
    }

    /**
     * Any member of the organization may view a shipment.
     */
    public function view(User $user, Shipment $shipment): bool
    {
        return $this->belongsToCurrentOrganization($shipment)
            && $user->roleIn($shipment->organization) !== null;
    }

    /**
     * Admins and owners may create shipments.
     */
    public function create(User $user): bool
    {
        $organization = $this->currentOrganization();

        return $organization !== null
            && ($user->roleIn($organization)?->atLeast(Role::Admin) ?? false);
    }

    /**
     * Admins and owners may update a shipment.
     */
    public function update(User $user, Shipment $shipment): bool
    {
        return $this->belongsToCurrentOrganization($shipment)
            && ($user->roleIn($shipment->organization)?->atLeast(Role::Admin) ?? false);
    }

    /**
     * Only owners may delete a shipment.
     */
    public function delete(User $user, Shipment $shipment): bool
    {
        return $this->belongsToCurrentOrganization($shipment)
            && ($user->roleIn($shipment->organization)?->atLeast(Role::Owner) ?? false);
    }
}
