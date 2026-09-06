<?php

namespace App\Services;

use App\Constants\PaymentProviderConstants;
use App\Models\OneTimeProduct;
use App\Models\PartnerPlanOffering;
use App\Models\PartnerProductOffering;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PaymentProviders\PaymentService;
use Illuminate\Support\Collection;

/**
 * The single answer to "does a usable partner offering exist for this buyer
 * and this item?" (spec §8.1).
 *
 * Both sides of the feature ask this service rather than re-deriving the
 * rules: the storefront and the totals calculation read it, and
 * PurchaseSnapshotService delegates to it when stamping partner_tenant_id
 * onto a new order. Two implementations of "usable" would drift, and the
 * drift would be a customer quoted one price and charged another.
 *
 * "Usable" means all four of:
 *   - the buyer resolves to a partner tenant — an authenticated user through
 *     users.partner_tenant_id and only that, or an anonymous visitor through
 *     an active referral code in session,
 *   - that tenant's Partner Plan is currently active (spec §7.5),
 *   - the offering exists and is_enabled,
 *   - the offering is still at or above the live admin floor (spec §5.5).
 *
 * Plus three operational preconditions, all about the Offline payment
 * provider being the only thing that can ever collect a partner price:
 *   - the Offline provider must be active AND open to new payments — the two
 *     columns checkout itself filters on (a partner price with checkout
 *     switched off would quote a price with no way to pay it),
 *   - for a plan offering specifically, Offline must actually support the
 *     plan's type (today: flat-rate only — Offline cannot bill seat-based
 *     or usage-based plans at all, partner or not), and
 *   - for a plan with a trial, this buyer must still be eligible for one —
 *     a returning customer who has used up their trials makes checkout ask
 *     for a provider that can skip the trial, which Offline cannot do.
 * Failing closed here makes display and checkout degrade together — a plan
 * this gate rejects shows base pricing and keeps every provider, exactly as
 * if no partner offering existed.
 */
class PartnerPricingResolver
{
    /** @var array<int, Tenant|null> keyed by user id, 0 for an anonymous visitor */
    private array $tenantMemo = [];

    /** @var array<string, PartnerPlanOffering|null> keyed by "<userKey>:<planId>" */
    private array $planOfferingMemo = [];

    /** @var array<string, PartnerProductOffering|null> keyed by "<userKey>:<productId>" */
    private array $productOfferingMemo = [];

    private ?bool $offlineUsableMemo = null;

    /** @var array<int, bool> keyed by plan id */
    private array $offlineSupportsPlanMemo = [];

    public function __construct(
        private PartnerAttributionService $attributionService,
        private PartnerCapabilityService $capabilityService,
        private PartnerCatalogService $catalogService,
    ) {}

    /**
     * Drop every memo. Required in tests whenever offerings, subscriptions or
     * the Offline provider row are mutated after something has already
     * resolved them — the container binding is scoped, so the instance
     * outlives the change.
     */
    public function flush(): void
    {
        $this->tenantMemo = [];
        $this->planOfferingMemo = [];
        $this->productOfferingMemo = [];
        $this->offlineUsableMemo = null;
        $this->offlineSupportsPlanMemo = [];
    }

    public function resolvePartnerTenant(?User $user = null): ?Tenant
    {
        $key = $this->userKey($user);

        if (array_key_exists($key, $this->tenantMemo)) {
            return $this->tenantMemo[$key];
        }

        return $this->tenantMemo[$key] = $this->computePartnerTenant($user);
    }

    public function usablePlanOffering(?User $user, Plan $plan): ?PartnerPlanOffering
    {
        $key = $this->userKey($user).':'.$plan->id;

        if (array_key_exists($key, $this->planOfferingMemo)) {
            return $this->planOfferingMemo[$key];
        }

        return $this->planOfferingMemo[$key] = $this->computePlanOffering($user, $plan);
    }

    public function usableProductOffering(?User $user, OneTimeProduct $product): ?PartnerProductOffering
    {
        $key = $this->userKey($user).':'.$product->id;

        if (array_key_exists($key, $this->productOfferingMemo)) {
            return $this->productOfferingMemo[$key];
        }

        return $this->productOfferingMemo[$key] = $this->computeProductOffering($user, $product);
    }

    public function planPrice(?User $user, Plan $plan): ?int
    {
        $offering = $this->usablePlanOffering($user, $plan);

        return $offering === null ? null : (int) $offering->price;
    }

    public function productPrice(?User $user, OneTimeProduct $product): ?int
    {
        $offering = $this->usableProductOffering($user, $product);

        return $offering === null ? null : (int) $offering->price;
    }

    /**
     * Stamp each plan with the price this buyer would actually pay.
     *
     * Sets two transient attributes — never persisted, because listing models
     * are read-only here:
     *   - partner_price: int|null, the partner's price, or null for base pricing
     *   - partner_tenant_name: string|null, for the "sold through" label
     *
     * A plan with no usable offering keeps partner_price = null and is left in
     * the collection at base price (spec §8.2, as amended 2026-09-05).
     *
     * @param  Collection<int, Plan>  $plans
     * @return Collection<int, Plan>
     */
    public function decoratePlans(Collection $plans, ?User $user = null): Collection
    {
        $tenant = $this->resolvePartnerTenant($user);

        foreach ($plans as $plan) {
            $price = $this->planPrice($user, $plan);

            // @phpstan-ignore-next-line property.notFound (transient attribute, never persisted — see method docblock)
            $plan->partner_price = $price;
            // @phpstan-ignore-next-line property.notFound (transient attribute, never persisted — see method docblock)
            $plan->partner_tenant_name = $price === null ? null : $tenant?->name;
        }

        return $plans;
    }

    /**
     * @param  Collection<int, OneTimeProduct>  $products
     * @return Collection<int, OneTimeProduct>
     */
    public function decorateProducts(Collection $products, ?User $user = null): Collection
    {
        $tenant = $this->resolvePartnerTenant($user);

        foreach ($products as $product) {
            $price = $this->productPrice($user, $product);

            // @phpstan-ignore-next-line property.notFound (transient attribute, never persisted — see class docblock)
            $product->partner_price = $price;
            // @phpstan-ignore-next-line property.notFound (transient attribute, never persisted — see class docblock)
            $product->partner_tenant_name = $price === null ? null : $tenant?->name;
        }

        return $products;
    }

    /**
     * The session referral code is a fallback for anonymous visitors only.
     *
     * An authenticated user's answer is users.partner_tenant_id and nothing
     * else, even when it is null. Attribution happens at exactly two places —
     * UserService (registration) and AttributePartnerOnLogin (login); there is
     * no checkout attribution, and PartnerAttributionSource::ORDER and ::LINK
     * are declared but never dispatched. So an already-logged-in direct
     * customer who clicks a partner referral link mid-session has no login
     * event coming to attribute them: falling back to the session code would
     * start quoting them marked-up, cash-only prices indefinitely, and a
     * purchase would stamp orders.partner_tenant_id while users.partner_tenant_id
     * stayed null — leaving the admin's Attribution Source and Attributed At
     * columns rendering "—" for that order.
     */
    private function computePartnerTenant(?User $user): ?Tenant
    {
        $tenant = $user === null
            ? $this->tenantFromPendingCode()
            : $this->attributedTenant($user);

        if ($tenant === null) {
            return null;
        }

        return $this->capabilityService->tenantIsActivePartner($tenant) ? $tenant : null;
    }

    private function attributedTenant(User $user): ?Tenant
    {
        /** @var Tenant|null $tenant */
        $tenant = $user->partnerTenant;

        return $tenant;
    }

    private function tenantFromPendingCode(): ?Tenant
    {
        $code = $this->attributionService->pendingCode();

        return $code === null ? null : $this->attributionService->resolveTenantForCode($code);
    }

    private function computePlanOffering(?User $user, Plan $plan): ?PartnerPlanOffering
    {
        $tenant = $this->resolvePartnerTenant($user);

        if ($tenant === null || ! $this->offlineProviderAcceptsNewPayments() || ! $this->offlineProviderSupportsPlan($plan)) {
            return null;
        }

        if (! $this->offlineCanBillTrialPlanFor($user, $plan)) {
            return null;
        }

        $offering = PartnerPlanOffering::where('tenant_id', $tenant->id)
            ->where('plan_id', $plan->id)
            ->where('is_enabled', true)
            ->first();

        if ($offering === null) {
            return null;
        }

        return $this->catalogService->isPlanOfferingBelowMinimum($offering) ? null : $offering;
    }

    private function computeProductOffering(?User $user, OneTimeProduct $product): ?PartnerProductOffering
    {
        $tenant = $this->resolvePartnerTenant($user);

        if ($tenant === null || ! $this->offlineProviderAcceptsNewPayments()) {
            return null;
        }

        $offering = PartnerProductOffering::where('tenant_id', $tenant->id)
            ->where('one_time_product_id', $product->id)
            ->where('is_enabled', true)
            ->first();

        if ($offering === null) {
            return null;
        }

        return $this->catalogService->isProductOfferingBelowMinimum($offering) ? null : $offering;
    }

    /**
     * Both columns, deliberately: checkout resolves its provider list through
     * PaymentService::getActivePaymentProvidersFromDatabase(), which adds
     * is_enabled_for_new_payments whenever $isNewPayment — and both checkout
     * forms pass true. Checking only is_active would let an admin's one-click
     * ToggleColumn leave Offline active but closed to new payments, and then a
     * partner price would be quoted with every provider filtered away
     * underneath it: an unhandled NoPaymentProvidersAvailableException, i.e. a
     * 500 for the customer.
     */
    /**
     * The only buyer-dependent Offline precondition.
     *
     * As soon as a buyer has used up their trial allowance, the subscription
     * checkout asks PaymentService for a provider that can *skip* the plan's
     * trial ($shouldSupportSkippingTrial), and OfflineProvider::supportsSkippingTrial()
     * is hard false — so Offline is not on the list at all. An offering that
     * survived to here would quote a partner price into a list
     * restrictToPartnerProviders() is about to empty: the same unhandled
     * NoPaymentProvidersAvailableException 500 as the gate below. Failing
     * closed makes display and checkout degrade together (spec Decision 5):
     * such a buyer simply sees base pricing and keeps every provider.
     *
     * The eligibility rule itself belongs to SubscriptionService and is reused
     * rather than reimplemented. Resolved lazily via the container for the
     * usual reason — SubscriptionService -> CalculationService ->
     * PartnerPricingResolver is a real cycle.
     */
    private function offlineCanBillTrialPlanFor(?User $user, Plan $plan): bool
    {
        if (! $plan->has_trial) {
            return true;
        }

        return app(SubscriptionService::class)->canUserHaveSubscriptionTrial($user);
    }

    private function offlineProviderAcceptsNewPayments(): bool
    {
        return $this->offlineUsableMemo ??= PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)
            ->where('is_active', true)
            ->where('is_enabled_for_new_payments', true)
            ->exists();
    }

    /**
     * Resolved lazily via the container rather than constructor-injected,
     * consistent with the rule that nothing reachable from
     * SubscriptionService may constructor-inject this resolver — the same
     * cycle applies in reverse here, since PaymentService is reachable from
     * checkout/subscription code that this resolver is itself injected into.
     *
     * Asks the OfflineProvider instance itself rather than re-hardcoding
     * PlanType::FLAT_RATE here, so the two checks cannot drift apart.
     */
    private function offlineProviderSupportsPlan(Plan $plan): bool
    {
        return $this->offlineSupportsPlanMemo[$plan->id] ??= app(PaymentService::class)
            ->getPaymentProviderBySlug(PaymentProviderConstants::OFFLINE_SLUG)
            ->supportsPlan($plan);
    }

    private function userKey(?User $user): int
    {
        return $user?->id ?? 0;
    }
}
