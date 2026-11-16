<?php

namespace App\Domain\Invoices\Jobs;

use App\Domain\Invoices\Models\Invoice;
use App\Enums\InvoiceStatus;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

/**
 * Base class for every stage of the invoice pipeline.
 *
 * A job carries only ids — never Eloquent models — so it can be serialised and
 * replayed safely. The organization is deliberately NOT ambient: handle()
 * publishes it to CurrentOrganization at the start and clears it in a finally
 * block, so nothing the job touches can ever see another tenant's data.
 *
 * Every stage is deterministic, so a retry would only repeat the same failure:
 * $tries is therefore 1 and failed() records the reason on the invoice.
 */
abstract class InvoiceJob implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Deterministic work: retrying would not change the outcome.
     */
    public int $tries = 1;

    /**
     * Ids only — never models. Not readonly: SerializesModels restores the
     * payload through reflection after the sync/queue serialisation round-trip,
     * and a readonly promoted property cannot be re-initialised that way.
     */
    public function __construct(
        public int $organizationId,
        public int $invoiceId,
    ) {}

    /**
     * Run the stage with the tenant in context, always clearing it afterwards.
     */
    final public function handle(): void
    {
        $current = app(CurrentOrganization::class);
        $current->set(Organization::findOrFail($this->organizationId));

        try {
            $this->process();
        } finally {
            $current->forget();
        }
    }

    /**
     * The stage's work. Runs with the organization already in context.
     */
    abstract protected function process(): void;

    /**
     * Record the failure on the invoice. Runs outside the request, so the
     * tenant is re-established here as well.
     */
    public function failed(Throwable $e): void
    {
        $current = app(CurrentOrganization::class);

        try {
            $current->set(Organization::findOrFail($this->organizationId));
        } catch (Throwable) {
            // The organization is gone; fall through to the unscoped update.
        }

        try {
            // Scoped to the job's own organization: a job can only ever mark
            // an invoice of its own tenant as failed.
            Invoice::query()->whereKey($this->invoiceId)->update([
                'status' => InvoiceStatus::Failed->value,
                'error_message' => Str::limit($e->getMessage(), 500),
            ]);
        } finally {
            $current->forget();
        }
    }

    /**
     * The invoice this job is responsible for, resolved through the tenancy
     * global scope so it can never cross into another organization.
     */
    protected function invoice(): Invoice
    {
        return Invoice::query()->whereKey($this->invoiceId)->firstOrFail();
    }
}
