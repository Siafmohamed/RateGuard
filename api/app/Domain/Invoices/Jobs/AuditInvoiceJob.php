<?php

namespace App\Domain\Invoices\Jobs;

use App\Domain\Audit\Data\AuditContext;
use App\Domain\Audit\Data\ContractData;
use App\Domain\Audit\Data\InvoiceLineData;
use App\Domain\Audit\Data\ShipmentData;
use App\Domain\Audit\Rules\AuditEngine;
use App\Domain\Audit\Services\FindingWriter;
use App\Domain\Contracts\Models\Contract;
use App\Domain\Invoices\Models\InvoiceLine;
use App\Domain\Shipments\Models\Shipment;
use App\Enums\InvoiceStatus;

/**
 * Stage 3 — run the audit rules and persist the findings.
 *
 * The database is read once here and folded into immutable DTOs, so this job is
 * the only place that touches the ORM on the audit path: the rules themselves
 * are pure functions over AuditContext. Persistence is delegated to
 * FindingWriter, which is idempotent.
 */
class AuditInvoiceJob extends InvoiceJob
{
    protected function process(): void
    {
        $invoice = $this->invoice();
        $invoice->update([
            'status' => InvoiceStatus::Auditing->value,
            'error_message' => null,
        ]);

        $invoice->load('carrier');
        $carrierCode = (string) $invoice->carrier->code;

        $lines = $invoice->lines()
            ->get()
            ->map(fn (InvoiceLine $line) => InvoiceLineData::fromModel($line))
            ->all();

        $shipments = Shipment::query()
            ->whereIn('tracking_number', array_unique(array_map(
                fn (InvoiceLineData $line) => $line->trackingNumber,
                $lines,
            )))
            ->get()
            ->keyBy('tracking_number')
            ->map(fn (Shipment $shipment) => ShipmentData::fromModel($shipment))
            ->all();

        $contract = $invoice->contract_id !== null
            ? Contract::query()->with('rates')->find($invoice->contract_id)
            : null;

        $context = new AuditContext(
            carrierCode: $carrierCode,
            lines: $lines,
            shipmentsByTracking: $shipments,
            contract: ContractData::fromModel($contract, $carrierCode),
        );

        $findings = app(AuditEngine::class)->run($context);

        app(FindingWriter::class)->write($invoice, $findings);

        $invoice->update([
            'status' => InvoiceStatus::Audited->value,
            'audited_at' => now(),
        ]);
    }
}
