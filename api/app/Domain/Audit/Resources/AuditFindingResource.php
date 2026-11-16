<?php

namespace App\Domain\Audit\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Domain\Audit\Models\AuditFinding
 */
class AuditFindingResource extends JsonResource
{
    /**
     * Money stays in integer cents on the wire; the client formats it.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'invoice_line_id' => $this->invoice_line_id,
            'tracking_number' => $this->whenLoaded(
                'invoiceLine',
                fn () => $this->invoiceLine?->tracking_number,
            ),
            'rule' => $this->rule,
            'severity' => $this->severity,
            'expected_cents' => $this->expected_cents,
            'billed_cents' => $this->billed_cents,
            'recoverable_cents' => $this->recoverable_cents,
            'status' => $this->status,
            'details' => $this->details,
        ];
    }
}
