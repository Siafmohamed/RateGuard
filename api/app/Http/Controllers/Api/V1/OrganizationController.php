<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    /**
     * List the authenticated user's organizations together with their role.
     */
    public function index(Request $request)
    {
        $organizations = $request->user()->organizations()
            ->orderBy('organizations.id')
            ->get()
            ->map(fn (Organization $organization) => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'role' => $organization->pivot->role,
            ]);

        return response()->json(['organizations' => $organizations]);
    }

    /**
     * Show the organization resolved for the current request.
     */
    public function show(Request $request)
    {
        $organization = $request->attributes->get('organization');

        $this->authorize('view', $organization);

        return response()->json(['organization' => $organization]);
    }

    /**
     * Update the organization resolved for the current request.
     */
    public function update(Request $request)
    {
        $organization = $request->attributes->get('organization');

        $this->authorize('update', $organization);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $organization->update($validated);

        return response()->json(['organization' => $organization]);
    }
}
