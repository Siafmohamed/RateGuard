<?php

namespace App\Domain\Invoices\Jobs;

use App\Domain\Audit\Models\AuditFinding;
use App\Domain\Claims\Models\Claim;

/**
 * Stage 4 — open a draft claim for every recoverable finding.
 *
 * Only findings that are still "open" and carry a positive recoverable amount
 * get a claim. Idempotent: firstOrCreate on audit_finding_id means a re-run
 * never duplicates a claim, and a finding a human has already acted on (which
 * is no longer "open") is left alone.
 */
class DraftClaimsJob extends InvoiceJob
{
    protected function process(): void
    {
        $invoice = $this->invoice();

        $invoice->findings()
            ->where('status', 'open')
            ->where('recoverable_cents', '>', 0)
            ->whereDoesntHave('claim')
            ->get()
            ->each(function (AuditFinding $finding) {
                Claim::firstOrCreate(
                    ['audit_finding_id' => $finding->getKey()],
                    [
                        'status' => 'draft',
                        'amount_cents' => $finding->recoverable_cents,
                    ],
                );
            });
    }
}
