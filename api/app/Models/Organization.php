<?php

namespace App\Models;

use App\Domain\Billing\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Organization extends Model
{
    /** @use HasFactory<\Database\Factories\OrganizationFactory> */
    use HasFactory;

    protected $fillable = ['name', 'slug'];

    /**
     * The users that belong to the organization.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * The organization's billing subscription, if one has been created yet.
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    /**
     * The plan the organization is on, as its catalogue entry.
     *
     * Falls back to the configured default plan (free) when the organization has
     * no subscription row or an unknown plan code, so callers never have to
     * handle a null plan. When no catalogue is configured at all, it returns an
     * unlimited placeholder rather than guessing a limit.
     *
     * @return array{code: string, name: string, price_cents: int, invoice_limit: int|null, features: array<string, bool>}
     */
    public function currentPlan(): array
    {
        $plans = config('rateguard.billing.plans', []);
        $default = config('rateguard.billing.default_plan', 'free');
        $code = $this->subscription?->plan_code ?? $default;

        foreach ([$code, $default] as $candidate) {
            foreach ($plans as $plan) {
                if (($plan['code'] ?? null) === $candidate) {
                    return $plan;
                }
            }
        }

        // No catalogue configured: never block rather than invent a limit.
        return [
            'code' => $code,
            'name' => ucfirst($code),
            'price_cents' => 0,
            'invoice_limit' => null,
            'features' => [],
        ];
    }
}
