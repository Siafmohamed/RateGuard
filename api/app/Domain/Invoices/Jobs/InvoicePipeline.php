<?php

namespace App\Domain\Invoices\Jobs;

use App\Domain\Invoices\Models\Invoice;
use Illuminate\Support\Facades\Bus;

/**
 * Builds and dispatches the invoice pipeline.
 *
 * The full pipeline is parse -> normalize -> audit -> draft claims. Each stage
 * carries only ids, so the chain survives serialisation to Redis. A re-run of an
 * already parsed invoice skips the parse stage (dispatchAudit / dispatchSync with
 * audit-only), which is what the re-audit endpoint and the console command use.
 */
class InvoicePipeline
{
    /**
     * Queue the full pipeline for a freshly uploaded invoice.
     */
    public static function dispatch(Invoice $invoice): void
    {
        Bus::chain(self::fullChain($invoice))->dispatch();
    }

    /**
     * Queue the normalize -> audit -> draft-claims stages of an invoice that has
     * already been parsed.
     */
    public static function dispatchAudit(Invoice $invoice): void
    {
        Bus::chain(self::auditChain($invoice))->dispatch();
    }

    /**
     * Run the same jobs inline (for debugging / the console command). The job
     * classes — and therefore the behaviour — are identical to the queued path.
     */
    public static function dispatchSync(Invoice $invoice, bool $auditOnly = false): void
    {
        foreach ($auditOnly ? self::auditChain($invoice) : self::fullChain($invoice) as $job) {
            Bus::dispatchSync($job);
        }
    }

    /**
     * @return list<InvoiceJob>
     */
    private static function fullChain(Invoice $invoice): array
    {
        return array_merge(
            [new ParseInvoiceJob($invoice->organization_id, $invoice->getKey())],
            self::auditChain($invoice),
        );
    }

    /**
     * @return list<InvoiceJob>
     */
    private static function auditChain(Invoice $invoice): array
    {
        $organizationId = $invoice->organization_id;
        $invoiceId = $invoice->getKey();

        return [
            new NormalizeInvoiceJob($organizationId, $invoiceId),
            new AuditInvoiceJob($organizationId, $invoiceId),
            new DraftClaimsJob($organizationId, $invoiceId),
        ];
    }
}
