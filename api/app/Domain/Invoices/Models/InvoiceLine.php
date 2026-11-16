<?php

namespace App\Domain\Invoices\Models;

use App\Domain\Audit\Models\AuditFinding;
use App\Domain\Shipments\Models\Shipment;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single billed charge on an invoice, including accessorials/surcharges.
 */
class InvoiceLine extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'invoice_id',
        'shipment_id',
        'tracking_number',
        'charge_type',
        'description',
        'billed_weight_kg',
        'amount_cents',
    ];

    protected function casts(): array
    {
        return [
            'billed_weight_kg' => 'decimal:2',
            'amount_cents' => 'integer',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(AuditFinding::class);
    }
}
