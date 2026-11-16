<?php

namespace App\Policies;

use App\Domain\Invoices\Models\Invoice;
use App\Enums\Role;
use App\Models\User;

class InvoicePolicy extends OrganizationScopedPolicy
{
    /**
     * Any member of the organization may list invoices.
     */
    public function viewAny(User $user): bool
    {
        return $this->currentOrganization() !== null;
    }

    /**
     * Any member of the organization may view an invoice.
     */
    public function view(User $user, Invoice $invoice): bool
    {
        return $this->belongsToCurrentOrganization($invoice)
            && $user->roleIn($invoice->organization) !== null;
    }

    /**
     * Admins and owners may create invoices.
     */
    public function create(User $user): bool
    {
        $organization = $this->currentOrganization();

        return $organization !== null
            && ($user->roleIn($organization)?->atLeast(Role::Admin) ?? false);
    }

    /**
     * Admins and owners may update an invoice.
     */
    public function update(User $user, Invoice $invoice): bool
    {
        return $this->belongsToCurrentOrganization($invoice)
            && ($user->roleIn($invoice->organization)?->atLeast(Role::Admin) ?? false);
    }

    /**
     * Only owners may delete an invoice.
     */
    public function delete(User $user, Invoice $invoice): bool
    {
        return $this->belongsToCurrentOrganization($invoice)
            && ($user->roleIn($invoice->organization)?->atLeast(Role::Owner) ?? false);
    }
}
