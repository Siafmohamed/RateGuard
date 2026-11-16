<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Resources\AuditFindingResource;
use App\Domain\Invoices\Jobs\InvoicePipeline;
use App\Domain\Invoices\Models\Invoice;
use App\Domain\Invoices\Resources\InvoiceResource;
use App\Domain\Invoices\Support\InvoiceStorage;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvoiceRequest;
use App\Models\Carrier;
use App\Support\Tenancy\CurrentOrganization;

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
     */
    public function upload(StoreInvoiceRequest $request, InvoiceStorage $storage)
    {
        $this->authorize('create', Invoice::class);

        $validated = $request->validated();

        $organization = app(CurrentOrganization::class)->get();

        $carrier = Carrier::query()->where('code', $validated['carrier'])->firstOrFail();

        $path = $storage->store($request->file('file'), $organization);

        $invoice = Invoice::create([
            'carrier_id' => $carrier->getKey(),
            'invoice_number' => $validated['invoice_number'],
            'invoice_date' => $validated['invoice_date'],
            'currency' => 'USD',
            'total_cents' => 0,
            'status' => InvoiceStatus::Uploaded->value,
            'source_file' => $path,
        ]);

        InvoicePipeline::dispatch($invoice);

        return (new InvoiceResource($invoice->load('carrier')))
            ->response()
            ->setStatusCode(202);
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
