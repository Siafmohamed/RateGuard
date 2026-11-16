<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Carrier reference data (UPS, FedEx, DHL...).
 *
 * This table is global: it intentionally has no organization_id and does not
 * use the BelongsToOrganization trait, so every organization sees the same rows.
 */
class Carrier extends Model
{
    protected $fillable = ['code', 'name'];
}
