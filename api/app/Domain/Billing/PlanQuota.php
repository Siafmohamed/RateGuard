<?php

namespace App\Domain\Billing;

use App\Domain\Billing\Exceptions\PlanLimitReachedException;
use App\Domain\Invoices\Models\Invoice;
use App\Enums\InvoiceStatus;
use App\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * The invoice quota for an organization's current billing period.
 *
 * Period: the CALENDAR MONTH, measured by when the invoice was UPLOADED
 * (`created_at`), not the invoice date printed on the document. This keeps
 * "invoices this month" independent of what a carrier wrote on the file.
 *
 * Counting rules:
 *  - Failed uploads never consume quota (they produced no usable invoice).
 *  - Re-running an audit is not an upload and never consumes quota: only the
 *    upload endpoint calls into this class.
 *  - A plan with a null invoice_limit (enterprise) is never blocked.
 */
class PlanQuota
{
    public function periodStart(): CarbonImmutable
    {
        return CarbonImmutable::now()->startOfMonth();
    }

    /**
     * The number of invoices that count toward the current period's quota.
     */
    public function used(Organization $organization): int
    {
        return Invoice::query()
            ->where('organization_id', $organization->getKey())
            ->where('status', '!=', InvoiceStatus::Failed->value)
            ->where('created_at', '>=', $this->periodStart())
            ->count();
    }

    /**
     * The plan's invoice limit for the period, or null when it is unlimited.
     */
    public function limit(Organization $organization): ?int
    {
        $limit = $organization->currentPlan()['invoice_limit'] ?? null;

        return $limit === null ? null : (int) $limit;
    }

    /**
     * @throws PlanLimitReachedException when the organization has used its whole allowance.
     */
    public function assertCanUpload(Organization $organization): void
    {
        $limit = $this->limit($organization);

        if ($limit === null) {
            return;
        }

        $used = $this->used($organization);

        if ($used >= $limit) {
            throw new PlanLimitReachedException(
                $organization->currentPlan()['code'],
                $limit,
                $used,
            );
        }
    }
}
