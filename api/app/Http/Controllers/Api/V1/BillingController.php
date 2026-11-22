<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Billing\Models\Subscription;
use App\Domain\Billing\PlanQuota;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Billing API for the current organization.
 *
 * The plan catalogue is served from configuration (see
 * config/rateguard.php -> billing.plans): this controller never invents plans
 * or pricing. The endpoints that require a payment provider (checkout, portal)
 * report Not Implemented rather than pretending to integrate one.
 */
class BillingController extends Controller
{
    /**
     * The billing overview: the plan the organization is on, its subscription
     * status and dates, how many invoices it has used, and the plan catalogue.
     *
     * The response is returned at the TOP LEVEL (no "data" envelope), matching
     * the frontend's billingOverviewSchema.
     */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Subscription::class);

        $organization = app(CurrentOrganization::class)->get();
        $subscription = Subscription::query()->first();

        $plans = $this->plans();

        return response()->json([
            'plan' => $this->planFor($plans, $subscription?->plan_code),
            'status' => $subscription?->status,
            'renews_at' => $subscription?->renews_at?->toDateString(),
            'ends_at' => $subscription?->ends_at?->toDateString(),
            // Tenancy-scoped: the current organization's invoices uploaded during
            // the current billing period (failed uploads excluded).
            'invoices_used' => $organization !== null
                ? app(PlanQuota::class)->used($organization)
                : 0,
            'plans' => $plans,
        ]);
    }

    /**
     * The plan catalogue, read-only. Available to any member of the
     * organization, like the overview.
     *
     * The response is returned at the TOP LEVEL (no "data" envelope), matching
     * the overview and the frontend's planListSchema.
     */
    public function catalogue(): JsonResponse
    {
        $this->authorize('viewAny', Subscription::class);

        return response()->json([
            'plans' => $this->plans(),
        ]);
    }

    /**
     * Start a checkout for another plan. Producing a hosted checkout URL needs
     * a payment provider, which is not integrated, so this reports Not
     * Implemented instead of inventing one.
     */
    public function checkout(Request $request): Response
    {
        $this->authorize('manage', Subscription::class);

        $validated = $request->validate([
            'plan' => ['required', 'string'],
        ]);

        $codes = array_column($this->plans(), 'code');

        if (! in_array($validated['plan'], $codes, true)) {
            abort(422, 'Unknown plan.');
        }

        return $this->paymentProviderNotConfigured();
    }

    /**
     * Open the provider-hosted customer portal. Also needs a payment provider.
     */
    public function portal(): Response
    {
        $this->authorize('manage', Subscription::class);

        return $this->paymentProviderNotConfigured();
    }

    /**
     * Cancel the subscription at the end of the current period: the status
     * becomes "canceled" and it is set to end on the current renewal date.
     */
    public function cancel(): Response
    {
        $this->authorize('manage', Subscription::class);

        $subscription = Subscription::query()->first();

        if ($subscription === null) {
            abort(409, 'There is no subscription to cancel.');
        }

        $subscription->update([
            'status' => Subscription::STATUS_CANCELED,
            'ends_at' => $subscription->renews_at ?? $subscription->ends_at,
        ]);

        return response()->noContent();
    }

    /**
     * Resume a subscription that was set to cancel at the end of the period.
     */
    public function resume(): Response
    {
        $this->authorize('manage', Subscription::class);

        $subscription = Subscription::query()->first();

        if ($subscription === null) {
            abort(409, 'There is no subscription to resume.');
        }

        $subscription->update([
            'status' => Subscription::STATUS_ACTIVE,
            'ends_at' => null,
        ]);

        return response()->noContent();
    }

    /**
     * The plan catalogue, straight from configuration. Empty until the plan
     * values are defined by the product owner.
     *
     * @return list<array{code: string, name: string, price_cents: int, invoice_limit: int|null}>
     */
    private function plans(): array
    {
        return array_values(config('rateguard.billing.plans', []));
    }

    /**
     * The catalogue entry matching the given plan code, or null when the
     * organization has no plan or the code is not in the catalogue.
     *
     * @param  list<array{code: string, name: string, price_cents: int, invoice_limit: int|null}>  $plans
     * @return array{code: string, name: string, price_cents: int, invoice_limit: int|null}|null
     */
    private function planFor(array $plans, ?string $code): ?array
    {
        if ($code === null) {
            return null;
        }

        foreach ($plans as $plan) {
            if (($plan['code'] ?? null) === $code) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * 501 for the endpoints that cannot work without a payment provider.
     *
     * The body is stable and machine-readable (the same nested shape the 402
     * plan-limit error uses), so the frontend can branch on the code instead of
     * parsing prose. The status stays 501.
     */
    private function paymentProviderNotConfigured(): Response
    {
        return response()->json([
            'error' => [
                'code' => 'payments_not_available',
                'message' => 'Online payments are not available yet.',
            ],
        ], 501);
    }
}
