<?php

namespace App\Domain\Contracts\Models;

use App\Models\Carrier;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contract extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'carrier_id',
        'name',
        'effective_from',
        'effective_to',
        'dim_divisor',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'dim_divisor' => 'integer',
        ];
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    /**
     * The rate-card line items of this contract.
     */
    public function rates(): HasMany
    {
        return $this->hasMany(ContractRate::class);
    }

    /**
     * The contract that governs an invoice: the organization's contract for the
     * carrier whose effective window covers the invoice date, falling back to
     * the most recent one for that carrier. The query is tenancy-scoped, so —
     * with an organization in context — it can only return this tenant's rows.
     */
    public static function matchingFor(int $carrierId, string $invoiceDate): ?self
    {
        return static::query()
            ->where('carrier_id', $carrierId)
            ->where('effective_from', '<=', $invoiceDate)
            ->where(fn ($query) => $query
                ->whereNull('effective_to')
                ->orWhere('effective_to', '>=', $invoiceDate))
            ->orderByDesc('effective_from')
            ->first()
            ?? static::query()
                ->where('carrier_id', $carrierId)
                ->orderByDesc('effective_from')
                ->first();
    }
}
