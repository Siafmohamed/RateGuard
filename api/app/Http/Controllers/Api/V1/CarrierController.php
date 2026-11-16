<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CarrierResource;
use App\Models\Carrier;

/**
 * Carrier reference data (UPS, FedEx, DHL...).
 *
 * The carriers table is GLOBAL: it has no organization_id and no tenancy
 * global scope, so this endpoint deliberately sits OUTSIDE the "org"
 * middleware. Every authenticated user sees the same list, whatever
 * organization they are currently acting in.
 */
class CarrierController extends Controller
{
    public function index()
    {
        $carriers = Carrier::query()
            ->orderBy('code')
            ->get();

        return CarrierResource::collection($carriers);
    }
}
