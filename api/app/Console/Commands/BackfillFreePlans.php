<?php

namespace App\Console\Commands;

use App\Domain\Billing\Models\Subscription;
use App\Models\Organization;
use Illuminate\Console\Command;

/**
 * Backfills a free/active subscription for every organization that has none.
 *
 * It is deliberately additive and idempotent: it ONLY creates the rows that are
 * missing and never touches (or overwrites) an organization that already has a
 * subscription, so it is safe to re-run at any time. `--dry-run` lists what
 * would change and writes nothing.
 */
class BackfillFreePlans extends Command
{
    protected $signature = 'billing:backfill-free-plans
                            {--dry-run : List the organizations that would change without writing anything}';

    protected $description = 'Create a free/active subscription for every organization that has none.';

    public function handle(): int
    {
        $planCode = (string) config('rateguard.billing.default_plan', 'free');
        $dryRun = (bool) $this->option('dry-run');

        // Organizations that already have a subscription are never touched.
        $subscribed = Subscription::withoutTenancy()->pluck('organization_id')->all();

        $missing = Organization::query()
            ->whereNotIn('id', $subscribed)
            ->orderBy('id')
            ->get();

        if ($missing->isEmpty()) {
            $this->info('Every organization already has a subscription. Nothing to do.');

            return self::SUCCESS;
        }

        foreach ($missing as $organization) {
            if ($dryRun) {
                $this->line(sprintf(
                    '[dry-run] would create a "%s" subscription for #%d %s',
                    $planCode,
                    $organization->getKey(),
                    $organization->name,
                ));

                continue;
            }

            // firstOrCreate keeps a re-run (or a concurrent run) from violating
            // the unique organization_id index, and never overwrites a row.
            Subscription::firstOrCreate(
                ['organization_id' => $organization->getKey()],
                ['plan_code' => $planCode, 'status' => Subscription::STATUS_ACTIVE],
            );

            $this->line(sprintf(
                'Created a "%s" subscription for #%d %s',
                $planCode,
                $organization->getKey(),
                $organization->name,
            ));
        }

        $this->info($dryRun
            ? sprintf('%d organization(s) would be backfilled.', $missing->count())
            : sprintf('%d organization(s) backfilled.', $missing->count()));

        return self::SUCCESS;
    }
}
