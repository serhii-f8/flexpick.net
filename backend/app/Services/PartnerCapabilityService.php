<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;

class PartnerCapabilityService
{
    public const RESELLER_METADATA_KEY = 'enables_reseller_program';

    /**
     * Per-request memo of active subscriptions, keyed by tenant id — same
     * rationale as AuditEntitlementService: avoids fanning out into repeat
     * queries when Filament renders the tenant nav multiple times.
     *
     * @var array<int, Collection<int, Subscription>>
     */
    private array $activeSubscriptions = [];

    public function __construct(
        private SubscriptionService $subscriptionService,
    ) {}

    public function tenantIsActivePartner(Tenant $tenant): bool
    {
        return $this->activeSubscriptionsFor($tenant)
            ->contains(function (Subscription $subscription): bool {
                /** @var Plan|null $plan */
                $plan = $subscription->plan;

                if ($plan === null) {
                    return false;
                }

                /** @var Product|null $product */
                $product = $plan->product;

                return $product !== null && (bool) data_get($product->metadata, self::RESELLER_METADATA_KEY, false);
            });
    }

    /**
     * The one gate every item in the dashboard's Partner group shares
     * (spec §4). Resources add their own tenancy permission on top.
     */
    public function userCanAccessPartnerArea(?Tenant $tenant, ?User $user): bool
    {
        return $tenant !== null && $user !== null && $this->tenantIsActivePartner($tenant);
    }

    /**
     * @return Collection<int, Subscription>
     */
    private function activeSubscriptionsFor(Tenant $tenant): Collection
    {
        return $this->activeSubscriptions[$tenant->id] ??= $this->subscriptionService->findActiveTenantSubscriptions($tenant);
    }
}
