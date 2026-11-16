<?php

namespace App\Support\Tenancy;

use App\Models\Organization;

/**
 * Holds the organization that is in context for the current request or job.
 *
 * Registered as a singleton, so anything resolved during the same lifecycle
 * shares the same instance.
 */
class CurrentOrganization
{
    protected ?Organization $organization = null;

    public function set(Organization $organization): void
    {
        $this->organization = $organization;
    }

    public function get(): ?Organization
    {
        return $this->organization;
    }

    public function id(): ?int
    {
        return $this->organization?->getKey();
    }

    public function forget(): void
    {
        $this->organization = null;
    }
}
