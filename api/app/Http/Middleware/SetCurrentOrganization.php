<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetCurrentOrganization
{
    /**
     * Resolve the organization for the current request from the authenticated
     * user's own memberships, optionally narrowed by the X-Organization-Id
     * header. The resolved organization is stored on the request attributes
     * and published to the CurrentOrganization singleton, which powers the
     * tenancy global scope used by every tenant model and policy.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        $query = $user->organizations();

        if ($organizationId = $request->header('X-Organization-Id')) {
            $query->whereKey($organizationId);
        }

        // Default to the user's first membership when no header is provided.
        $organization = $query->orderBy('organization_user.id')->first();

        if (! $organization) {
            abort(403, 'You do not belong to this organization.');
        }

        $request->attributes->set('organization', $organization);

        app(CurrentOrganization::class)->set($organization);

        return $next($request);
    }
}
