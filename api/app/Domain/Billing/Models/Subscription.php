<?php

namespace App\Domain\Billing\Models;

use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * An organization's billing subscription. There is at most one row per
 * organization (enforced by a unique index); it records which plan the
 * organization is on and the lifecycle dates the API reports.
 *
 * No pricing lives here: the plan catalogue is configuration
 * (config/rateguard.php -> billing.plans) and only the selected code is stored.
 */
class Subscription extends Model
{
    use BelongsToOrganization;

    /**
     * The statuses the billing UI branches on.
     */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_CANCELED = 'canceled';

    protected $fillable = [
        'organization_id',
        'plan_code',
        'status',
        'renews_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'renews_at' => 'date',
            'ends_at' => 'date',
        ];
    }
}
