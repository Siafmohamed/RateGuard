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
}
