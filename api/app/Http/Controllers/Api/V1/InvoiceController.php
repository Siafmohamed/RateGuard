<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Resources\AuditFindingResource;
use App\Domain\Billing\PlanQuota;
use App\Domain\Contracts\Models\Contract;
use App\Domain\Invoices\Jobs\InvoicePipeline;
use App\Domain\Invoices\Models\Invoice;
use App\Domain\Invoices\Resources\InvoiceResource;
use App\Domain\Invoices\Support\InvoiceStorage;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvoiceRequest;
use App\Models\Carrier;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    /**
     * List the invoices of the current organization.
     *
     * The tenancy global scope is already applied, so this can never leak
     * another organization's rows.
     */
    public function index()
    {
        $this->authorize('viewAny', Invoice::class);

        $invoices = Invoice::query()
            ->with('carrier')
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->paginate(15);

        return InvoiceResource::collection($invoices);
    }

    /**
     * Upload a carrier invoice, store it via InvoiceStorage and start the
     * parse -> normalize -> audit -> draft-claims pipeline.
     *
     * The plan's invoice quota is enforced BEFORE the file is stored or any job
     * is dispatched: a blocked upload leaves no file, no invoice row and no
     * queued work behind.
     */
    public function upload(StoreInvoiceRequest $request, InvoiceStorage $storage)
    {
        $this->authorize('create', Invoice::class);

        $validated = $request->validated();

        $organization = app(CurrentOrganization::class)->get();

        $carrier = Carrier::query()->where('code', $validated['carrier'])->firstOrFail();

        // Link the organization's contract for this carrier so the audit has a
        // rate card to compare the invoice against.
        $contract = $this->matchingContract($carrier, $validated['invoice_date']);

        // Count-then-create inside one transaction, serialized per organization
        // by a row lock, so two simultaneous uploads can never both take the
        // last slot of the plan.
        $invoice = DB::transaction(function () use ($organization, $carrier, $contract, $request, $validated, $storage) {
            Organization::query()->whereKey($organization->getKey())->lockForUpdate()->first();

            app(PlanQuota::class)->assertCanUpload($organization);

            $path = $storage->store($request->file('file'), $organization);

            return Invoice::create([
                'carrier_id' => $carrier->getKey(),
                'contract_id' => $contract?->getKey(),
                'invoice_number' => $validated['invoice_number'],
                'invoice_date' => $validated['invoice_date'],
                'currency' => 'USD',
                'total_cents' => 0,
                'status' => InvoiceStatus::Uploaded->value,
                'source_file' => $path,
            ]);
        });

        // Dispatched only after the transaction commits, so a rollback can never
        // leave a job running against a row that was never written.
        InvoicePipeline::dispatch($invoice);

        return (new InvoiceResource($invoice->load('carrier')))
            ->response()
            ->setStatusCode(202);
    }

    /**
     * The contract that governs an invoice: the organization's contract for the
     * carrier whose effective window covers the invoice date, falling back to
     * the most recent one for that carrier. The query is tenancy-scoped, so it
     * can only ever return a contract of the current organization.
     */
    private function matchingContract(Carrier $carrier, string $invoiceDate): ?Contract
    {
        return Contract::query()
            ->where('carrier_id', $carrier->getKey())
            ->where('effective_from', '<=', $invoiceDate)
            ->where(fn ($query) => $query
                ->whereNull('effective_to')
                ->orWhere('effective_to', '>=', $invoiceDate))
            ->orderByDesc('effective_from')
            ->first()
            ?? Contract::query()
                ->where('carrier_id', $carrier->getKey())
                ->orderByDesc('effective_from')
                ->first();
    }

    /**
     * Show a single invoice of the current organization.
     *
     * Route model binding resolves through the tenancy global scope, so an
     * invoice that belongs to another organization simply 404s.
     */
    public function show(Invoice $invoice)
    {
        $this->authorize('view', $invoice);

        $invoice->load('carrier');
        $invoice->loadCount('findings');
        $invoice->loadSum('findings as recoverable_total_cents', 'recoverable_cents');

        return new InvoiceResource($invoice);
    }

    /**
     * The audit findings raised against an invoice, paginated.
     */
    public function findings(Invoice $invoice)
    {
        $this->authorize('view', $invoice);

        $findings = $invoice->findings()
            ->with('invoiceLine')
            ->orderBy('id')
            ->paginate(15);

        return AuditFindingResource::collection($findings);
    }

    /**
     * Re-run the normalize -> audit -> draft-claims stages on an invoice that
     * has already been parsed.
     */
    public function audit(Invoice $invoice)
    {
        $this->authorize('update', $invoice);

        if (in_array($invoice->status, [InvoiceStatus::Parsing->value, InvoiceStatus::Auditing->value], true)) {
            abort(409, 'The invoice is still being processed.');
        }

        InvoicePipeline::dispatchAudit($invoice);

        return (new InvoiceResource($invoice->load('carrier')))
            ->response()
            ->setStatusCode(202);
    }
}
