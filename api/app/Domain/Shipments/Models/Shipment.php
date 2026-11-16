<?php

namespace App\Domain\Shipments\Models;

use App\Domain\Contracts\Models\Contract;
use App\Domain\Invoices\Models\InvoiceLine;
use App\Models\Carrier;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shipment extends Model
{
    use BelongsToOrganization;

    /**
     * Weights are stored in kilograms, dimensions in centimetres.
     */
    protected $fillable = [
        'organization_id',
        'carrier_id',
        'contract_id',
        'tracking_number',
        'service_level',
        'ship_date',
        'origin_zip',
        'destination_zip',
        'actual_weight_kg',
        'length_cm',
        'width_cm',
        'height_cm',
    ];

    protected function casts(): array
    {
        return [
            'ship_date' => 'date',
            'actual_weight_kg' => 'decimal:2',
            'length_cm' => 'decimal:2',
            'width_cm' => 'decimal:2',
            'height_cm' => 'decimal:2',
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

    public function invoiceLines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /**
     * Dimensional weight in kilograms: L x W x H (cm) / dim divisor.
     */
    public function dimensionalWeightKg(int $dimDivisor = 5000): float
    {
        return round(($this->length_cm * $this->width_cm * $this->height_cm) / $dimDivisor, 2);
    }
}
