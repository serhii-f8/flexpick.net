<?php

namespace App\Services;

use App\Constants\PaymentProviderConstants;
use App\Constants\PlanType;
use App\Models\OneTimeProduct;
use App\Models\PartnerPlanOffering;
use App\Models\PartnerProductOffering;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PaymentProviders\PaymentProviderInterface;
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
 *     the fp_rc cookie,
 *   - that tenant's Partner Plan is currently active (spec §7.5),
 *   - the offering exists and is_enabled,
 *   - the offering is still at or above the live admin floor (spec §5.5).
 *
 * Plus operational preconditions, all about there being a provider that can
 * collect a partner price (PaymentProviderConstants::PARTNER_PRICE_SLUGS —
 * Offline cash or a Cardknox card):
 *   - at least one of them must be active AND open to new payments — the two
 *     columns checkout itself filters on (a partner price with checkout
 *     switched off would quote a price with no way to pay it),
 *   - a plan offering must be flat-rate (spec §2: a partner sets one price,
 *     with no seat or usage component), and one of those providers must
 *     support the plan, and
 *   - for a plan with a trial this buyer is no longer eligible for, checkout
 *     asks for a provider that can skip the trial, which Offline cannot do,
 *     so one of the providers must be able to.
 * Failing closed here makes display and checkout degrade together: for a
 * non-attributed buyer, a plan this gate rejects simply shows base pricing
 * and keeps every provider, exactly as if no partner offering existed. For
 * an attributed buyer this is no longer the whole story — purchasablePlans()/
 * purchasableProducts() (2026-09-07, catalog restriction) reuse this exact
 * gate to decide what a purchasing surface offers at all, so for them a plan
 * or product this gate rejects disappears from the catalog entirely and is
 * rejected server-side at checkout, rather than falling back to base pricing
 * (that fallback, base §8.2, was reversed by the catalog-restriction spec).
 */
class PartnerPricingResolver
{
    /** @var array<int, Tenant|null> keyed by user id, 0 for an anonymous visitor */
    private array $tenantMemo = [];

    /** @var array<string, PartnerPlanOffering|null> keyed by "<userKey>:<planId>" */
    private array $planOfferingMemo = [];

    /** @var array<string, PartnerProductOffering|null> keyed by "<userKey>:<productId>" */
    private array $productOfferingMemo = [];

    /** @var list<PaymentProviderInterface>|null */
    private ?array $partnerProvidersMemo = null;

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
        $this->partnerProvidersMemo = null;
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
     * The list a purchasing surface should actually offer (spec §3, catalog
     * restriction): unchanged for a non-attributed buyer or one whose
     * partner's plan has lapsed; narrowed to only usable offerings for an
     * attributed buyer. Reuses usablePlanOffering() rather than re-deriving
     * "usable" — decoratePlans() and this method must never disagree about
     * which plans are resellable.
     *
     * @param  Collection<int, Plan>  $plans
     * @return Collection<int, Plan>
     */
    public function purchasablePlans(Collection $plans, ?User $user = null): Collection
    {
        if ($this->resolvePartnerTenant($user) === null) {
            return $plans;
        }

        return $plans->filter(fn (Plan $plan): bool => $this->usablePlanOffering($user, $plan) !== null)->values();
    }

    /**
     * @param  Collection<int, OneTimeProduct>  $products
     * @return Collection<int, OneTimeProduct>
     */
    public function purchasableProducts(Collection $products, ?User $user = null): Collection
    {
        if ($this->resolvePartnerTenant($user) === null) {
            return $products;
        }

        return $products->filter(fn (OneTimeProduct $product): bool => $this->usableProductOffering($user, $product) !== null)->values();
    }

    /**
     * The cookie is a fallback for anonymous visitors only.
     *
     * An authenticated user's answer is users.partner_tenant_id and nothing
     * else, even when it is null. Attribution happens at exactly two places —
     * UserService (registration) and AttributePartnerOnLogin (login) — and both
     * re-issue the cookie from that row afterwards, so for a signed-in user the
     * cookie can never say anything the database does not (spec §3.4, §3.5).
     */
    private function computePartnerTenant(?User $user): ?Tenant
    {
        $tenant = $user === null
            ? $this->tenantFromCookie()
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

    private function tenantFromCookie(): ?Tenant
    {
        $code = $this->attributionService->cookieCode();

        return $code === null ? null : $this->attributionService->resolveTenantForCode($code);
    }

    private function computePlanOffering(?User $user, Plan $plan): ?PartnerPlanOffering
    {
        $tenant = $this->resolvePartnerTenant($user);

        if ($tenant === null || $plan->type !== PlanType::FLAT_RATE->value || ! $this->partnerProviderCanBillPlan($user, $plan)) {
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

        if ($tenant === null || $this->partnerProviders() === []) {
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
     * Whether a partner-price provider would survive every filter subscription
     * checkout applies for this buyer and plan.
     *
     * As soon as a buyer has used up their trial allowance, the subscription
     * checkout asks PaymentService for a provider that can *skip* the plan's
     * trial ($shouldSupportSkippingTrial). OfflineProvider::supportsSkippingTrial()
     * is hard false, so for such a buyer only a provider that can skip it
     * counts. An offering that survived with none would quote a partner price
     * into a list restrictToPartnerProviders() is about to empty: an unhandled
     * NoPaymentProvidersAvailableException 500. Failing closed makes display
     * and checkout degrade together (spec Decision 5).
     *
     * The eligibility rule itself belongs to SubscriptionService and is reused
     * rather than reimplemented. Resolved lazily via the container for the
     * usual reason — SubscriptionService -> CalculationService ->
     * PartnerPricingResolver is a real cycle.
     */
    private function partnerProviderCanBillPlan(?User $user, Plan $plan): bool
    {
        $mustSkipTrial = (bool) $plan->has_trial && ! app(SubscriptionService::class)->canUserHaveSubscriptionTrial($user);

        foreach ($this->partnerProviders() as $provider) {
            if ($provider->supportsPlan($plan) && (! $mustSkipTrial || $provider->supportsSkippingTrial())) {
                return true;
            }
        }

        return false;
    }

    /**
     * The partner-price providers that are active AND open to new payments.
     * Both columns, deliberately: checkout resolves its provider list through
     * PaymentService::getActivePaymentProvidersFromDatabase(), which adds
     * is_enabled_for_new_payments whenever $isNewPayment — and both checkout
     * forms pass true. Checking only is_active would let an admin's one-click
     * ToggleColumn leave a provider active but closed to new payments, and
     * then a partner price would be quoted with every provider filtered away
     * underneath it.
     *
     * PaymentService is resolved lazily via the container rather than
     * constructor-injected: it is reachable from checkout/subscription code
     * that this resolver is itself injected into.
     *
     * @return list<PaymentProviderInterface>
     */
    private function partnerProviders(): array
    {
        if ($this->partnerProvidersMemo !== null) {
            return $this->partnerProvidersMemo;
        }

        $slugs = PaymentProvider::query()
            ->whereIn('slug', PaymentProviderConstants::PARTNER_PRICE_SLUGS)
            ->where('is_active', true)
            ->where('is_enabled_for_new_payments', true)
            ->pluck('slug');

        $paymentService = app(PaymentService::class);

        return $this->partnerProvidersMemo = $slugs
            ->map(fn (string $slug): PaymentProviderInterface => $paymentService->getPaymentProviderBySlug($slug))
            ->values()
            ->all();
    }

    private function userKey(?User $user): int
    {
        return $user?->id ?? 0;
    }
}
