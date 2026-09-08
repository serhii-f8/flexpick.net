<?php

namespace App\Services;

use App\Constants\PaymentProviderConstants;
use App\Constants\PlanType;
use App\Constants\SubscriptionConstants;
use App\Constants\SubscriptionStatus;
use App\Constants\SubscriptionType;
use App\Events\Subscription\InvoicePaymentFailed;
use App\Events\Subscription\Subscribed;
use App\Events\Subscription\SubscribedOffline;
use App\Events\Subscription\SubscriptionCancelled;
use App\Events\Subscription\SubscriptionRenewed;
use App\Exceptions\CouldNotCreateLocalSubscriptionException;
use App\Exceptions\SubscriptionCreationNotAllowedException;
use App\Exceptions\TenantException;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserSubscriptionTrial;
use App\Services\CashPayments\PurchaseSnapshotService;
use App\Services\PaymentProviders\PaymentProviderInterface;
use App\Support\CashPayments\SweepFloor;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubscriptionService
{
    public function __construct(
        private CalculationService $calculationService,
        private PlanService $planService,
    ) {}

    public function create(
        string $planSlug,
        int $userId,
        int $quantity,
        Tenant $tenant,
        ?PaymentProvider $paymentProvider = null,
        ?string $paymentProviderSubscriptionId = null,
        bool $localSubscription = false,
        ?Carbon $endsAt = null,
    ): Subscription {

        if (! $this->canCreateSubscription($tenant->id)) {
            throw new SubscriptionCreationNotAllowedException(sprintf('Subscription creation is not allowed for this tenant: %s', $tenant->uuid));
        }

        $plan = Plan::where('slug', $planSlug)->where('is_active', true)->firstOrFail();

        $newSubscription = null;
        DB::transaction(function () use ($plan, $userId, &$newSubscription, $paymentProvider, $paymentProviderSubscriptionId, $quantity, $tenant, $localSubscription, $endsAt) {
            $this->deleteAllNewSubscriptions($userId, $tenant);

            $planPrice = $this->calculationService->getPlanPrice($plan);
            $user = User::findOrFail($userId);

            // Only a subscription that can actually be collected in cash may
            // carry a partner price. A local subscription is the free-trial /
            // admin-comped path, and it is converted to a real payment through
            // a gateway (ConvertLocalSubscriptionCheckoutForm), which by
            // Decision 2 may never see a partner price — so it stays at base.
            $price = $localSubscription
                ? (int) $planPrice->price
                : $this->planPriceForBuyer($plan, $user);

            $subscriptionAttributes = [
                'uuid' => (string) Str::uuid(),
                'user_id' => $userId,
                'plan_id' => $plan->id,
                'price' => $price,
                'currency_id' => $planPrice->currency_id,
                'status' => SubscriptionStatus::NEW->value,
                'interval_id' => $plan->interval_id,
                'interval_count' => $plan->interval_count,
                'quantity' => $quantity,
                'tenant_id' => $tenant->id,
                'price_type' => $planPrice->type,
                'price_tiers' => $planPrice->tiers,
                'price_per_unit' => $planPrice->price_per_unit,
                'type' => SubscriptionType::PAYMENT_PROVIDER_MANAGED,
            ];

            // Resolved through the container rather than constructor-injected:
            // PurchaseSnapshotService -> PartnerCapabilityService ->
            // SubscriptionService is a container cycle.
            $snapshot = app(PurchaseSnapshotService::class)->forPlan($user, $plan);

            $subscriptionAttributes['partner_tenant_id'] = $snapshot['partner_tenant_id'];
            $subscriptionAttributes['base_price_snapshot'] = $snapshot['base_price_snapshot'];
            $subscriptionAttributes['quota_snapshot'] = $snapshot['quota_snapshot'];

            if ($paymentProvider) {
                $subscriptionAttributes['payment_provider_id'] = $paymentProvider->id;
            }

            if ($paymentProviderSubscriptionId) {
                $subscriptionAttributes['payment_provider_subscription_id'] = $paymentProviderSubscriptionId;
            }

            if ($localSubscription) {
                $subscriptionAttributes['type'] = SubscriptionType::LOCALLY_MANAGED;

                $endDate = $endsAt ?? ($plan->has_trial ? now()->addDays($this->calculateSubscriptionTrialDays($plan)) : null);
                if ($endDate === null) {
                    throw new CouldNotCreateLocalSubscriptionException('Could not determine local subscription end date');
                }

                $subscriptionAttributes['ends_at'] = $endDate;

                if ($plan->has_trial) {
                    $subscriptionAttributes['trial_ends_at'] = $endDate;
                }

                if ($this->shouldUserVerifyPhoneNumberForTrial($user)) {
                    $subscriptionAttributes['status'] = SubscriptionStatus::PENDING_USER_VERIFICATION->value;
                } else {
                    $subscriptionAttributes['status'] = SubscriptionStatus::ACTIVE->value;
                }
            }

            $newSubscription = Subscription::create($subscriptionAttributes);

            if ($localSubscription) {
                // if it's a local subscription, dispatch Subscribed event.
                // Payment provider subscriptions events are dispatched by payment provider strategy
                Subscribed::dispatch($newSubscription);
            }

            $this->updateUserSubscriptionTrials($newSubscription->id);
        });

        return $newSubscription;
    }

    /**
     * The flat price this buyer must actually be charged for a plan.
     *
     * CalculationService::getPlanPrice() is contractually the *base* price —
     * all five gateway providers create gateway-side products and prices from
     * it, so it must never be partner-substituted (Decision 2). That makes
     * this the write side's own responsibility: without it the subscription
     * row keeps the base price while the storefront and the checkout totals
     * show the partner's, and CashSubscriptionService::createPendingOrder()
     * then bills the base price and reports a zero margin.
     *
     * Only the flat price is substituted. Partner pricing is flat-rate-only
     * (PartnerPricingResolver enforces it), so for seat-based and usage-based
     * plans the resolver returns null and the base price stands untouched,
     * along with every other field taken from the PlanPrice row.
     *
     * Resolved lazily via the container rather than constructor-injected:
     * PartnerPricingResolver -> PartnerCapabilityService -> SubscriptionService
     * is a real container cycle. Same idiom as PurchaseSnapshotService above.
     */
    public function planPriceForBuyer(Plan $plan, User $user): int
    {
        return app(PartnerPricingResolver::class)->planPrice($user, $plan)
            ?? (int) $this->calculationService->getPlanPrice($plan)->price;
    }

    /**
     * Re-derive an existing NEW subscription's frozen price and snapshot for
     * the buyer standing at checkout right now.
     *
     * Both CheckoutService::initSubscriptionCheckout() and
     * initLocalSubscriptionCheckout() reuse a NEW subscription rather than
     * always creating one, and that row can predate the buyer's partner
     * attribution, the partner configuring the offering, or the partner
     * disabling it again. Nothing else re-derives it, so without this the
     * reuse path is the one way a stale price reaches a cash order.
     *
     * $localSubscription mirrors create()'s parameter of the same name and
     * carries the same rule: the local (free-trial) row is converted through
     * a gateway, which by Decision 2 may never see a partner price, so it is
     * repriced to base. The snapshot is still re-derived either way — it
     * records who sold the subscription, which stays true at base price.
     */
    public function syncPlanPurchaseForBuyer(Subscription $subscription, User $user, bool $localSubscription = false): Subscription
    {
        /** @var Plan $plan */
        $plan = $subscription->plan;

        // Same lazy-resolution reason as planPriceForBuyer() above.
        $snapshot = app(PurchaseSnapshotService::class)->forPlan($user, $plan);

        $subscription->fill([
            'price' => $localSubscription
                ? (int) $this->calculationService->getPlanPrice($plan)->price
                : $this->planPriceForBuyer($plan, $user),
            'partner_tenant_id' => $snapshot['partner_tenant_id'],
            'base_price_snapshot' => $snapshot['base_price_snapshot'],
            'quota_snapshot' => $snapshot['quota_snapshot'],
        ])->save();

        return $subscription;
    }

    public function shouldUserVerifyPhoneNumberForTrial(User $user): bool
    {
        return config('app.trial_without_payment.sms_verification_enabled') && ! $user->isPhoneNumberVerified();
    }

    public function canCreateSubscription(int $tenantId): bool
    {
        if (config('app.tenant_multiple_subscriptions_enabled')) {
            return true;
        }

        $notDeadSubscriptions = $this->findAllSubscriptionsThatAreNotDead($tenantId);

        return count($notDeadSubscriptions) === 0;
    }

    public function findAllSubscriptionsThatAreNotDead(int $tenantId): array
    {
        return Subscription::query()->where('tenant_id', $tenantId)
            ->where(function ($query) {
                $query->whereIn('status', SubscriptionConstants::SUBSCRIPTION_STATUS_THAT_ARE_NOT_DEAD);
            })
            ->get()
            ->toArray();
    }

    public function setAsPending(int $subscriptionId): void
    {
        // make it all in one statement to avoid overwriting webhook status updates
        Subscription::where('id', $subscriptionId)
            ->where('status', SubscriptionStatus::NEW->value)
            ->where('type', SubscriptionType::PAYMENT_PROVIDER_MANAGED)
            ->update([
                'status' => SubscriptionStatus::PENDING->value,
            ]);
    }

    public function deleteAllNewSubscriptions(int $userId, Tenant $tenant): void
    {
        Subscription::where('user_id', $userId)
            ->where('status', SubscriptionStatus::NEW->value)
            ->where('tenant_id', $tenant->id)
            ->delete();
    }

    public function findActiveUserSubscription(int $userId): ?Subscription
    {
        return Subscription::where('user_id', $userId)
            ->where('status', '=', SubscriptionStatus::ACTIVE->value)
            ->first();
    }

    public function findActiveTenantSubscriptions(?Tenant $tenant): Collection
    {
        $tenant = $tenant ?? Filament::getTenant();

        if (! $tenant) {
            return collect();
        }

        // Bypass Filament's tenancy global scope (same fix as
        // TenantPermissionService): this query already scopes explicitly by
        // the resolved $tenant above, so the global scope is redundant when
        // $tenant matches the current panel tenant and actively wrong when it
        // doesn't — e.g. a partner-pricing check for a DIFFERENT tenant than
        // the one the dashboard panel is currently scoped to (the customer's
        // own tenant) would otherwise silently see zero subscriptions for the
        // partner tenant and fail closed.
        return Subscription::withoutGlobalScope(filament()->getTenancyScopeName())
            ->where('tenant_id', $tenant->id)
            ->where('status', '=', SubscriptionStatus::ACTIVE->value)
            ->where('ends_at', '>', now())
            ->get();
    }

    /**
     * The tenant's active subscription that self-service Change Plan is
     * actually offered for, if any -- gateway-managed and not a partner
     * tenant, per canChangeSubscriptionPlan(). Used to route a generic
     * "Upgrade" link to the in-place Change Plan page instead of
     * /pricing's plan-purchase flow, which would otherwise silently spin
     * up a second workspace for a tenant that already has an active plan
     * (a tenant can only ever have one, per
     * TenantCreationService::findUserTenantsForNewSubscription()).
     */
    public function findChangeablePlanSubscription(?Tenant $tenant): ?Subscription
    {
        /** @var Subscription|null $subscription */
        $subscription = $this->findActiveTenantSubscriptions($tenant)
            ->first(fn (Subscription $subscription): bool => $this->canChangeSubscriptionPlan($subscription));

        return $subscription;
    }

    /**
     * True when any of the user's tenants has an active subscription that
     * isn't self-service-changeable (cash/partner-managed, or the tenant is
     * itself an active reseller). /pricing warns before purchase in this
     * case: buying a plan there always creates a brand-new workspace rather
     * than upgrading the existing one, since a tenant can only ever hold one
     * active subscription and this segment has no self-service path to
     * change the one it already has.
     */
    public function hasNonSelfServiceActiveSubscription(User $user): bool
    {
        foreach ($user->tenants as $tenant) {
            if ($this->findActiveTenantSubscriptions($tenant)->isNotEmpty()
                && $this->findChangeablePlanSubscription($tenant) === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cancels every not-dead cash subscription on the tenant so a fresh
     * cash purchase can attach to it instead of a new workspace getting
     * created (canCreateSubscription() guards against two live
     * subscriptions on one tenant otherwise). Only ever called via
     * CheckoutService::resolveSubscriptionTenant() for a tenant
     * TenantCreationService::findUserTenantWithSupersedableCashSubscription()
     * already confirmed has no gateway-managed subscription to protect.
     */
    public function supersedeCashSubscriptions(Tenant $tenant): void
    {
        $subscriptions = Subscription::withoutGlobalScope(filament()->getTenancyScopeName())
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', SubscriptionConstants::SUBSCRIPTION_STATUS_THAT_ARE_NOT_DEAD)
            ->get();

        foreach ($subscriptions as $subscription) {
            $this->updateSubscription($subscription, [
                'status' => SubscriptionStatus::CANCELED->value,
                'cancelled_at' => now(),
            ]);
        }
    }

    public function findActiveTenantSubscriptionProducts(?Tenant $tenant): Collection
    {
        return $this->findActiveTenantSubscriptions($tenant)
            ->map(function (Subscription $subscription) {
                return $subscription->plan->product;
            });
    }

    public function findActiveByTenantAndSubscriptionUuid(Tenant $tenant, string $subscriptionUuid): ?Subscription
    {
        return Subscription::where('tenant_id', $tenant->id)
            ->where('uuid', $subscriptionUuid)
            ->where('status', '=', SubscriptionStatus::ACTIVE->value)
            ->first();
    }

    public function findActiveTenantSubscriptionWithPlanType(PlanType $planType, ?Tenant $tenant): ?Subscription
    {
        if (! $tenant) {
            return null;
        }

        return Subscription::where('tenant_id', $tenant->id)
            ->where('status', '=', SubscriptionStatus::ACTIVE->value)
            ->whereHas('plan', function ($query) use ($planType) {
                $query->where('type', $planType->value);
            })->first();
    }

    public function findNewByPlanSlugAndTenant(string $planSlug, Tenant $tenant): ?Subscription
    {
        $plan = Plan::where('slug', $planSlug)->where('is_active', true)->firstOrFail();

        return Subscription::where('tenant_id', $tenant->id)
            ->where('plan_id', $plan->id)
            ->where('status', SubscriptionStatus::NEW->value)
            ->first();
    }

    public function findByUuidOrFail(string $uuid): Subscription
    {
        return Subscription::where('uuid', $uuid)->firstOrFail();
    }

    public function findByUuidAndUserIdOrFail(string $uuid, int $userId): Subscription
    {
        return Subscription::where('uuid', $uuid)
            ->where('user_id', $userId)
            ->firstOrFail();
    }

    public function isLocalSubscription(Subscription $subscription): bool
    {
        return $subscription->type === SubscriptionType::LOCALLY_MANAGED;
    }

    public function isIncompleteSubscription(Subscription $subscription): bool
    {
        return $this->isLocalSubscription($subscription) && $subscription->paymentProvider === null;
    }

    public function shouldSkipTrial(Subscription $subscription)
    {
        if ($this->isLocalSubscription($subscription) && $subscription->plan->has_trial) {
            return true;
        }

        return ! $this->canUserHaveSubscriptionTrial($subscription->user);
    }

    public function findById(int $id): ?Subscription
    {
        return Subscription::find($id);
    }

    public function findByPaymentProviderId(PaymentProvider $paymentProvider, string $paymentProviderSubscriptionId): ?Subscription
    {
        return Subscription::where('payment_provider_id', $paymentProvider->id)
            ->where('payment_provider_subscription_id', $paymentProviderSubscriptionId)
            ->first();
    }

    public function updateSubscription(
        Subscription $subscription,
        array $data
    ): Subscription {
        $oldStatus = $subscription->status;
        $newStatus = $data['status'] ?? $oldStatus;
        $oldEndsAt = $subscription->ends_at;
        $newEndsAt = $data['ends_at'] ?? $oldEndsAt;
        $subscription->update($data);

        $this->updateUserSubscriptionTrials($subscription->id);

        $this->handleDispatchingEvents(
            $oldStatus,
            $newStatus,
            $oldEndsAt,
            $newEndsAt,
            $subscription
        );

        return $subscription;
    }

    private function handleDispatchingEvents(
        string $oldStatus,
        string|SubscriptionStatus $newStatus,
        Carbon|string|null $oldEndsAt,
        Carbon|string|null $newEndsAt,
        Subscription $subscription
    ): void {
        $newStatus = $newStatus instanceof SubscriptionStatus ? $newStatus->value : $newStatus;

        if ($oldStatus !== $newStatus) {
            switch ($newStatus) {
                case SubscriptionStatus::ACTIVE->value:
                    Subscribed::dispatch($subscription);
                    break;
                case SubscriptionStatus::CANCELED->value:
                    SubscriptionCancelled::dispatch($subscription);
                    break;
            }
        }

        // if $oldEndsAt is string, convert it to Carbon
        if (is_string($oldEndsAt)) {
            $oldEndsAt = Carbon::parse($oldEndsAt);
        }

        // if $newEndsAt is string, convert it to Carbon
        if (is_string($newEndsAt)) {
            $newEndsAt = Carbon::parse($newEndsAt);
        }

        // if $newEndsAt > $oldEndsAt, then subscription is renewed
        if ($newEndsAt && $oldEndsAt && $newEndsAt->greaterThan($oldEndsAt)) {
            SubscriptionRenewed::dispatch($subscription, $oldEndsAt, $newEndsAt);
        }

        if ($newStatus == SubscriptionStatus::PENDING->value && $this->isLocalSubscription($subscription) && $subscription->paymentProvider->slug === PaymentProviderConstants::OFFLINE_SLUG) {
            // If the subscription is pending and it's an offline order, dispatch SubscribedOffline event (you can use this to let the user know that they need to pay offline)
            SubscribedOffline::dispatch($subscription);
        }
    }

    public function handleInvoicePaymentFailed(Subscription $subscription)
    {
        InvoicePaymentFailed::dispatch($subscription);
    }

    public function calculateSubscriptionTrialDays(Plan $plan): int
    {
        if (! $plan->has_trial) {
            return 0;
        }

        $interval = $plan->trialInterval()->firstOrFail();
        $intervalCount = $plan->trial_interval_count;

        $now = Carbon::now();

        return intval(round(abs(now()->add($interval->date_identifier, $intervalCount)->diffInDays($now))));
    }

    public function changePlan(Subscription $subscription, PaymentProviderInterface $paymentProviderStrategy, string $newPlanSlug, bool $isProrated = false): bool
    {
        if ($subscription->plan->slug === $newPlanSlug) {
            return false;
        }

        if (! $this->planService->isPlanChangeable($subscription->plan)) {
            return false;
        }

        $newPlan = $this->planService->getActivePlanBySlug($newPlanSlug);

        if (! $newPlan) {
            return false;
        }

        if ($subscription->plan->type != $newPlan->type) {
            return false;
        }

        if ($subscription->tenant_id !== null) {
            $tenantUserCount = $subscription->tenant->users()->count();
            if ($newPlan->max_users_per_tenant > 0 && $tenantUserCount > $newPlan->max_users_per_tenant) {
                return false;
            }
        }

        $changeResult = $paymentProviderStrategy->changePlan($subscription, $newPlan, $isProrated);

        if ($changeResult) {
            Subscribed::dispatch($subscription);

            return true;
        }

        return false;
    }

    public function canAddDiscount(Subscription $subscription)
    {
        return $subscription->type === SubscriptionType::PAYMENT_PROVIDER_MANAGED &&
            ($subscription->status === SubscriptionStatus::ACTIVE->value ||
            $subscription->status === SubscriptionStatus::PAST_DUE->value)
            && $subscription->price > 0
            && $subscription->discounts()->count() === 0  // only one discount per subscription for now
            && $subscription->paymentProvider->slug !== PaymentProviderConstants::LEMON_SQUEEZY_SLUG; // LemonSqueezy does not support discounts for active subscriptions
    }

    public function cancelSubscription(
        Subscription $subscription,
        PaymentProviderInterface $paymentProviderStrategy,
        string $reason,
        ?string $additionalInfo = null

    ): bool {
        $result = $paymentProviderStrategy->cancelSubscription($subscription);

        if ($result) {
            $this->updateSubscription($subscription, [
                'is_canceled_at_end_of_cycle' => true,
                'cancellation_reason' => $reason,
                'cancellation_additional_info' => $additionalInfo,
            ]);
        }

        return $result;
    }

    public function discardSubscriptionCancellation(Subscription $subscription, PaymentProviderInterface $paymentProviderStrategy): bool
    {
        $result = $paymentProviderStrategy->discardSubscriptionCancellation($subscription);

        if ($result) {
            $this->updateSubscription($subscription, [
                'is_canceled_at_end_of_cycle' => false,
                'cancellation_reason' => null,
                'cancellation_additional_info' => null,
            ]);
        }

        return $result;
    }

    /**
     * @param  mixed|null  $productSlug  - one or more product slugs to check subscription against
     *
     * @throws TenantException
     */
    public function isUserSubscribed(?User $user, mixed $productSlug = null, ?Tenant $tenant = null): bool
    {
        if (! $user) {
            return false;
        }

        $tenant = $tenant ?? Filament::getTenant();

        if (! $tenant) {
            throw new TenantException('Could not resolve tenant: You either need to specify a tenant or be in a tenant context to check if a user is subscribed.');
        }

        $userTenant = $user->tenants()->where('tenant_id', $tenant->id)->first();

        if (! $userTenant) {
            return false;
        }

        $subscriptions = $userTenant
            ->subscriptions()
            ->where('status', SubscriptionStatus::ACTIVE->value)
            ->where('ends_at', '>', Carbon::now())
            ->get();

        if ($productSlug) {
            $subscriptions = $subscriptions->filter(function (Subscription $subscription) use ($productSlug) {
                if (is_string($productSlug)) {
                    return $subscription->plan->product->slug === $productSlug;
                } elseif (is_array($productSlug)) {
                    return in_array($subscription->plan->product->slug, $productSlug);
                }

                return false;
            });
        }

        return $subscriptions->count() > 0;
    }

    public function isUserSubscribedViaAnyTenant(?User $user, ?string $productSlug = null): bool
    {
        if (! $user) {
            return false;
        }

        $tenantIds = $user->tenants()->pluck('tenant_id')->toArray();

        if (empty($tenantIds)) {
            return false;
        }

        $subscriptions = Subscription::whereIn('tenant_id', $tenantIds)
            ->where('status', SubscriptionStatus::ACTIVE->value)
            ->where('ends_at', '>', Carbon::now())
            ->get();

        if ($productSlug) {
            $subscriptions = $subscriptions->filter(function (Subscription $subscription) use ($productSlug) {
                return $subscription->plan->product->slug === $productSlug;
            });
        }

        return $subscriptions->count() > 0;
    }

    public function isUserTrialing(?User $user, ?string $productSlug = null, ?Tenant $tenant = null): bool
    {
        if (! $user) {
            return false;
        }

        $tenant = $tenant ?? Filament::getTenant();

        if (! $tenant) {
            throw new TenantException('Could not resolve tenant: You either need to specify a tenant or be in a tenant context to check if a user is trialing.');
        }

        $userTenant = $user->tenants()->where('tenant_id', $tenant->id)->first();

        if (! $userTenant) {
            return false;
        }

        $subscriptions = $userTenant->subscriptions()
            ->where('status', SubscriptionStatus::ACTIVE->value)
            ->where('trial_ends_at', '>', Carbon::now())
            ->get();

        if ($productSlug) {
            $subscriptions = $subscriptions->filter(function (Subscription $subscription) use ($productSlug) {
                return $subscription->plan->product->slug === $productSlug;
            });
        }

        return $subscriptions->count() > 0;
    }

    public function getTenantSubscriptionProductMetadata(?Tenant $tenant = null): array
    {
        $tenant = $tenant ?? Filament::getTenant();

        if (! $tenant) {
            return [];
        }

        $subscriptions = $tenant->subscriptions()
            ->where('status', SubscriptionStatus::ACTIVE->value)
            ->where('ends_at', '>', Carbon::now())
            ->get();

        if ($subscriptions->count() === 0) {
            // if there is no active subscriptions, return metadata of default product
            $defaultProduct = Product::where('is_default', true)->first();

            if (! $defaultProduct) {
                return [];
            }

            return $defaultProduct->metadata ?? [];
        }

        // if there is 1 subscription, return metadata of its product
        if ($subscriptions->count() === 1) {
            return $subscriptions->first()->plan->product->metadata ?? [];
        }

        // if there are multiple subscriptions, return array of product-slug => metadata
        return $subscriptions->mapWithKeys(function (Subscription $subscription) {
            return [$subscription->plan->product->slug => $subscription->plan->product->metadata ?? []];
        })->toArray();
    }

    public function canEditSubscriptionPaymentDetails(Subscription $subscription)
    {
        return $subscription->type === SubscriptionType::PAYMENT_PROVIDER_MANAGED &&
            (bool) optional($subscription->paymentProvider)->is_active &&
            ($subscription->status === SubscriptionStatus::ACTIVE->value ||
                $subscription->status === SubscriptionStatus::PAST_DUE->value ||
                $subscription->status === SubscriptionStatus::CANCELED->value
            );
    }

    public function canCancelSubscription(Subscription $subscription)
    {
        return $subscription->type === SubscriptionType::PAYMENT_PROVIDER_MANAGED &&
            ! $subscription->is_canceled_at_end_of_cycle &&
            $subscription->status === SubscriptionStatus::ACTIVE->value;
    }

    public function canDiscardSubscriptionCancellation(Subscription $subscription)
    {
        return $subscription->type === SubscriptionType::PAYMENT_PROVIDER_MANAGED &&
            $subscription->is_canceled_at_end_of_cycle &&
            $subscription->status === SubscriptionStatus::ACTIVE->value;
    }

    public function canChangeSubscriptionPlan(Subscription $subscription)
    {
        return $subscription->type === SubscriptionType::PAYMENT_PROVIDER_MANAGED &&
            $this->planService->isPlanChangeable($subscription->plan) &&
            $subscription->status === SubscriptionStatus::ACTIVE->value &&
            ! $this->tenantIsPartner($subscription);
    }

    /**
     * A partner tenant's plans are administered by the operator, not
     * self-served (spec §5). Resolved lazily: PartnerCapabilityService
     * constructor-injects this service, so the dependency cannot run both ways.
     */
    private function tenantIsPartner(Subscription $subscription): bool
    {
        /** @var Tenant|null $tenant */
        $tenant = $subscription->tenant;

        return $tenant !== null && app(PartnerCapabilityService::class)->tenantIsActivePartner($tenant);
    }

    public function getLocalSubscriptionExpiringIn(int $days)
    {
        return Subscription::where('type', SubscriptionType::LOCALLY_MANAGED)
            ->where('status', SubscriptionStatus::ACTIVE->value)
            ->where('payment_provider_id', null)
            // on that exact day
            ->whereDate('ends_at', Carbon::now()->addDays($days)->toDateString())
            ->get();
    }

    public function canEndSubscription(Subscription $subscription)
    {
        return $this->isLocalSubscription($subscription) &&
            $subscription->status === SubscriptionStatus::ACTIVE->value;
    }

    public function canUpdateSubscription(Subscription $subscription)
    {
        return $this->isLocalSubscription($subscription);
    }

    public function endSubscription(Subscription $subscription): bool
    {
        if (! $this->isLocalSubscription($subscription)) {
            return false;
        }

        $this->updateSubscription($subscription, [
            'status' => SubscriptionStatus::INACTIVE->value,
            'ends_at' => now(),
            'trial_ends_at' => now(),
        ]);

        return true;
    }

    public function cleanupLocalSubscriptionStatuses()
    {
        $sweepFloor = SweepFloor::parse();

        $subscriptions = Subscription::where('type', SubscriptionType::LOCALLY_MANAGED)
            ->where('status', SubscriptionStatus::ACTIVE->value)
            ->where('ends_at', '<', now())
            // Cash subscriptions created on or after cash_payments.sweep_from
            // have their own PAST_DUE -> CANCELED ladder in
            // app:expire-pending-cash-orders. Flipping them to INACTIVE here
            // would race it and skip the grace window. A cash subscription
            // that predates the floor was never picked up by that ladder
            // (the commands floor their own queries the same way), so it
            // must fall through to this sweep exactly as it did before the
            // cash-payment feature existed.
            ->whereNot(function (Builder $query) use ($sweepFloor) {
                $query->where('price', '>', 0)
                    ->whereHas('paymentProvider', fn (Builder $provider) => $provider->where('slug', PaymentProviderConstants::OFFLINE_SLUG))
                    ->when($sweepFloor !== null, fn (Builder $q) => $q->where('created_at', '>=', $sweepFloor));
            })
            ->get();

        $subscriptions->each(function (Subscription $subscription) {
            $this->updateSubscription($subscription, [
                'status' => SubscriptionStatus::INACTIVE->value,
            ]);
        });
    }

    public function updateUserSubscriptionTrials(int $subscriptionId)
    {
        $subscription = Subscription::where('id', $subscriptionId)
            ->where('status', SubscriptionStatus::ACTIVE->value)
            ->whereNotNull('trial_ends_at')
            ->first();

        if (! $subscription) {
            return;
        }

        $user = $subscription->user;

        // if user already has a trial for this subscription, do not create another one
        UserSubscriptionTrial::query()->firstOrCreate([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
        ], [
            'trial_ends_at' => $subscription->trial_ends_at,
        ]);
    }

    public function getUserSubscriptionTrialCount(int $userId): int
    {
        return UserSubscriptionTrial::where('user_id', $userId)->count();
    }

    public function canUserHaveSubscriptionTrial(?User $user): bool
    {
        if (! $user) {
            return true;
        }

        if (! config('app.limit_user_trials.enabled')) {
            return true;
        }

        if ($this->getUserSubscriptionTrialCount($user->id) >= config('app.limit_user_trials.max_count')) {
            return false;
        }

        return true;
    }

    public function activateSubscriptionsPendingUserVerification(User $user)
    {
        $subscriptions = Subscription::where('user_id', $user->id)
            ->where('status', SubscriptionStatus::PENDING_USER_VERIFICATION->value)
            ->get();

        $subscriptions->each(function (Subscription $subscription) {
            $this->updateSubscription($subscription, [
                'status' => SubscriptionStatus::ACTIVE->value,
            ]);
        });
    }

    public function subscriptionRequiresUserVerification(Subscription $subscription): bool
    {
        return $subscription->status === SubscriptionStatus::PENDING_USER_VERIFICATION->value &&
            $this->shouldUserVerifyPhoneNumberForTrial($subscription->user);
    }
}
