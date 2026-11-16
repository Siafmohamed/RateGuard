<?php

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Data\FindingData;
use App\Domain\Audit\Models\AuditFinding;
use App\Domain\Invoices\Models\Invoice;

/**
 * Persists audit findings. This is the "Findings" stage of the pipeline.
 *
 * Idempotency:
 *  - findings tied to a billed line are keyed by [invoice_line_id, rule] and
 *    upserted, so re-running the audit updates them in place;
 *  - findings without a line (invoice-level) have no natural key, so the
 *    invoice's previous OPEN findings for that rule are removed before the new
 *    one is inserted.
 *
 * Findings whose status is anything other than "open" (e.g. "disputed") are
 * never touched: a re-run must not clobber a human decision.
 */
class FindingWriter
{
    /**
     * @param  list<FindingData>  $findings
     * @return int the number of findings written
     */
    public function write(Invoice $invoice, array $findings): int
    {
        $written = 0;

        [$lineFindings, $invoiceFindings] = $this->partition($findings);

        foreach ($lineFindings as $finding) {
            if ($this->writeLineFinding($invoice, $finding)) {
                $written++;
            }
        }

        foreach ($invoiceFindings as $finding) {
            $this->writeInvoiceFinding($invoice, $finding);
            $written++;
        }

        return $written;
    }

    /**
     * @param  list<FindingData>  $findings
     * @return array{0: list<FindingData>, 1: list<FindingData>}
     */
    private function partition(array $findings): array
    {
        $line = [];
        $invoice = [];

        foreach ($findings as $finding) {
            if ($finding->invoiceLineId !== null) {
                $line[] = $finding;
            } else {
                $invoice[] = $finding;
            }
        }

        return [$line, $invoice];
    }

    private function writeLineFinding(Invoice $invoice, FindingData $finding): bool
    {
        $existing = AuditFinding::query()
            ->where('invoice_line_id', $finding->invoiceLineId)
            ->where('rule', $finding->rule)
            ->first();

        if ($existing !== null && $existing->status !== 'open') {
            // A human has already acted on this finding (e.g. disputed it).
            return false;
        }

        $attributes = [
            'organization_id' => $invoice->organization_id,
            'invoice_id' => $invoice->getKey(),
            'invoice_line_id' => $finding->invoiceLineId,
            'rule' => $finding->rule,
            'severity' => $finding->severity,
            'expected_cents' => $finding->expectedCents,
            'billed_cents' => $finding->billedCents,
            'recoverable_cents' => $finding->recoverableCents,
            'details' => $finding->details,
        ];

        if ($existing !== null) {
            $existing->update($attributes);

            return true;
        }

        AuditFinding::query()->create($attributes + ['status' => 'open']);

        return true;
    }

    private function writeInvoiceFinding(Invoice $invoice, FindingData $finding): void
    {
        AuditFinding::query()
            ->where('invoice_id', $invoice->getKey())
            ->whereNull('invoice_line_id')
            ->where('rule', $finding->rule)
            ->where('status', 'open')
            ->delete();

        AuditFinding::query()->create([
            'organization_id' => $invoice->organization_id,
            'invoice_id' => $invoice->getKey(),
            'invoice_line_id' => null,
            'rule' => $finding->rule,
            'severity' => $finding->severity,
            'expected_cents' => $finding->expectedCents,
            'billed_cents' => $finding->billedCents,
            'recoverable_cents' => $finding->recoverableCents,
            'details' => $finding->details,
            'status' => 'open',
        ]);
    }
}
