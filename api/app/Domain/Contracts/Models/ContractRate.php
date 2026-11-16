<?php

namespace App\Domain\Contracts\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single rate-card line item: a price for a weight band, or — when
 * charge_type is set — an allowed accessorial/surcharge (fuel, residential...).
 */
class ContractRate extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'contract_id',
        'service_level',
        'zone',
        'min_weight_kg',
        'max_weight_kg',
        'rate_cents',
        'charge_type',
    ];

    protected function casts(): array
    {
        return [
            'min_weight_kg' => 'decimal:2',
            'max_weight_kg' => 'decimal:2',
            'rate_cents' => 'integer',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
