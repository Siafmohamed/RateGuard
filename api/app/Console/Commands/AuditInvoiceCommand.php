<?php

namespace App\Console\Commands;

use App\Domain\Invoices\Jobs\InvoicePipeline;
use App\Domain\Invoices\Models\Invoice;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Console\Command;

/**
 * Runs the audit pipeline for one invoice, inline, and prints what it found.
 *
 * The command is a debugging/maintenance tool: it is useful to be able to audit
 * a seeded or already-imported invoice without going through HTTP. It reuses the
 * exact same job classes as the queue and the HTTP endpoints (InvoicePipeline),
 * so a fix in a job applies everywhere.
 */
class AuditInvoiceCommand extends Command
{
    protected $signature = 'rateguard:audit
                            {invoice_id : The id of the invoice to audit}
                            {--org= : The organization id that owns the invoice}';

    protected $description = 'Audit an invoice synchronously and print its findings and recoverable total.';

    public function handle(): int
    {
        $invoiceId = (int) $this->argument('invoice_id');

        // Console commands run outside a request, so there is no organization in
        // context yet. The invoice is therefore looked up with tenancy switched
        // off, purely to discover which tenant it belongs to.
        $invoice = Invoice::withoutTenancy()->find($invoiceId);

        if ($invoice === null) {
            $this->error("Invoice [{$invoiceId}] was not found.");

            return self::FAILURE;
        }

        $organization = $this->resolveOrganization($invoice);

        if ($organization === null) {
            return self::FAILURE;
        }

        if ((int) $invoice->organization_id !== (int) $organization->getKey()) {
            $this->error(sprintf(
                'Invoice [%d] belongs to organization [%d], not [%d].',
                $invoice->getKey(),
                $invoice->organization_id,
                $organization->getKey(),
            ));

            return self::FAILURE;
        }

        $current = app(CurrentOrganization::class);
        $current->set($organization);

        try {
            $this->info(sprintf(
                'Auditing invoice %s (#%d) for %s...',
                $invoice->invoice_number,
                $invoice->getKey(),
                $organization->name,
            ));

            // An invoice that has already been parsed only needs the audit
            // stages re-run; a fresh one is parsed from its stored file first.
            InvoicePipeline::dispatchSync($invoice, auditOnly: $invoice->lines()->exists());

            $this->report($invoice);
        } finally {
            $current->forget();
        }

        return self::SUCCESS;
    }

    /**
     * Resolve the organization to run under: the --org option if given, else the
     * organization the invoice belongs to.
     */
    private function resolveOrganization(Invoice $invoice): ?Organization
    {
        $option = $this->option('org');

        $organization = Organization::query()->find(
            $option !== null ? (int) $option : (int) $invoice->organization_id,
        );

        if ($organization === null) {
            $this->error($option !== null
                ? "Organization [{$option}] was not found."
                : 'The invoice has no organization.');
        }

        return $organization;
    }

    /**
     * Print the findings table and the recoverable total.
     */
    private function report(Invoice $invoice): void
    {
        $invoice->refresh();

        $findings = $invoice->findings()
            ->with('invoiceLine')
            ->orderByRaw('invoice_line_id is null')
            ->orderBy('invoice_line_id')
            ->orderBy('rule')
            ->get();

        if ($findings->isEmpty()) {
            $this->warn('No findings.');

            return;
        }

        $this->newLine();
        $this->table(
            ['id', 'rule', 'severity', 'tracking_number', 'expected_cents', 'billed_cents', 'recoverable_cents', 'status'],
            $findings->map(fn ($finding) => [
                $finding->getKey(),
                $finding->rule,
                $finding->severity,
                $finding->invoiceLine?->tracking_number ?? '-',
                $finding->expected_cents,
                $finding->billed_cents,
                $finding->recoverable_cents,
                $finding->status,
            ])->all(),
        );

        $this->newLine();
        $this->line(sprintf('Status:            %s', $invoice->status));
        $this->line(sprintf('Findings:          %d', $findings->count()));
        $this->info(sprintf('Recoverable total: %d cents', (int) $findings->sum('recoverable_cents')));
    }
}
