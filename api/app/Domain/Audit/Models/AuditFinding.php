<?php

namespace App\Domain\Audit\Models;

use App\Domain\Claims\Models\Claim;
use App\Domain\Invoices\Models\Invoice;
use App\Domain\Invoices\Models\InvoiceLine;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A discrepancy raised by an audit rule against one billed line.
 */
class AuditFinding extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'invoice_id',
        'invoice_line_id',
        'rule',
        'severity',
        'expected_cents',
        'billed_cents',
        'recoverable_cents',
        'status',
        'details',
    ];

    protected function casts(): array
    {
        return [
            'expected_cents' => 'integer',
            'billed_cents' => 'integer',
            'recoverable_cents' => 'integer',
            'details' => 'array',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class);
    }

    public function claim(): HasOne
    {
        return $this->hasOne(Claim::class);
    }
}
