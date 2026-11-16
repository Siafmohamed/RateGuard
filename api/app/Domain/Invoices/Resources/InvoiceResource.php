<?php

namespace App\Domain\Invoices\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Domain\Invoices\Models\Invoice
 */
class InvoiceResource extends JsonResource
{
    /**
     * Transform the invoice into an array.
     *
     * Money stays in integer cents on the wire; the client formats it.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'carrier_id' => $this->carrier_id,
            'carrier' => $this->whenLoaded('carrier', fn () => [
                'id' => $this->carrier->id,
                'code' => $this->carrier->code,
                'name' => $this->carrier->name,
            ]),
            'contract_id' => $this->contract_id,
            'invoice_number' => $this->invoice_number,
            'invoice_date' => $this->invoice_date?->toDateString(),
            'currency' => $this->currency,
            'total_cents' => $this->total_cents,
            'status' => $this->status,
            'error_message' => $this->error_message,
            'audited_at' => $this->audited_at?->toIso8601String(),
            'findings_count' => $this->whenCounted('findings'),
            'recoverable_total_cents' => $this->when(
                $this->resource->recoverable_total_cents !== null,
                fn () => (int) $this->resource->recoverable_total_cents,
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
