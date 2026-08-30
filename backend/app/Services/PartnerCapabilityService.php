<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\Tenant;
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
                $product = $subscription->plan?->product;

                return $product !== null && (bool) data_get($product->metadata, self::RESELLER_METADATA_KEY, false);
            });
    }

    /**
     * @return Collection<int, Subscription>
     */
    private function activeSubscriptionsFor(Tenant $tenant): Collection
    {
        return $this->activeSubscriptions[$tenant->id] ??= $this->subscriptionService->findActiveTenantSubscriptions($tenant);
    }
}
