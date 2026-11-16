<?php

namespace App\Providers;

use App\Domain\Audit\Models\AuditFinding;
use App\Domain\Audit\Rules\DimensionalWeightRule;
use App\Domain\Audit\Rules\DuplicateChargeRule;
use App\Domain\Audit\Rules\RuleRegistry;
use App\Domain\Audit\Rules\UnauthorizedSurchargeRule;
use App\Domain\Claims\Models\Claim;
use App\Domain\Content\Clients\WordPressContentClient;
use App\Domain\Content\Contracts\ContentClientInterface;
use App\Domain\Contracts\Models\Contract;
use App\Domain\Invoices\Models\Invoice;
use App\Domain\Shipments\Models\Shipment;
use App\Policies\AuditFindingPolicy;
use App\Policies\ClaimPolicy;
use App\Policies\ContractPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\ShipmentPolicy;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One organization in context per request/job lifecycle.
        $this->app->singleton(CurrentOrganization::class);

        // Which audit rules run for which carrier. Registering an additional
        // carrier-specific rule later is a single line, e.g.
        // $registry->forCarrier('DHL', new SomeNewRule()).
        $this->app->singleton(RuleRegistry::class, function () {
            $registry = new RuleRegistry;

            foreach (['UPS', 'FEDEX', 'DHL'] as $carrier) {
                $registry->forCarrier($carrier, new DimensionalWeightRule);
                $registry->forCarrier($carrier, new DuplicateChargeRule);
                $registry->forCarrier($carrier, new UnauthorizedSurchargeRule);
            }

            return $registry;
        });

        // Content gateway: the repository/controllers depend on the contract,
        // so swapping WordPress for another headless CMS is a one-line change.
        $this->app->bind(ContentClientInterface::class, WordPressContentClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Models live under app/Domain/**/Models, so Laravel's convention-based
        // policy discovery (app/Models -> app/Policies) does not find them.
        // They are therefore registered explicitly.
        Gate::policy(Contract::class, ContractPolicy::class);
        Gate::policy(Shipment::class, ShipmentPolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(AuditFinding::class, AuditFindingPolicy::class);
        Gate::policy(Claim::class, ClaimPolicy::class);
    }
}
