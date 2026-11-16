<?php

namespace App\Domain\Invoices\Models;

use App\Domain\Audit\Models\AuditFinding;
use App\Domain\Contracts\Models\Contract;
use App\Models\Carrier;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use BelongsToOrganization;

    /**
     * Money is stored as unsigned big integer cents plus a currency code.
     */
    protected $fillable = [
        'organization_id',
        'carrier_id',
        'contract_id',
        'invoice_number',
        'invoice_date',
        'currency',
        'total_cents',
        'status',
        'error_message',
        'audited_at',
        'source_file',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'total_cents' => 'integer',
            'audited_at' => 'datetime',
        ];
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * The billed lines of this invoice (including accessorial charges).
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /**
     * The audit findings raised against this invoice.
     */
    public function findings(): HasMany
    {
        return $this->hasMany(AuditFinding::class);
    }
}
