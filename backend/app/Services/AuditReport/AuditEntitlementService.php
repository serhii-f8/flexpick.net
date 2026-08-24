<?php

namespace App\Services\AuditReport;

use App\Constants\AuditFunding;
use App\Constants\AuditTier;
use App\Models\AuditRequest;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserParameter;
use App\Services\SubscriptionService;
use Illuminate\Support\Collection;

class AuditEntitlementService
{
    public const BONUS_PARAM = 'audit_bonus_free_runs';

    /**
     * Per-request memo of active subscriptions, keyed by tenant id.
     *
     * hasAuditAccess() and quotas() each call allowance()/planMetadata() once
     * per metered tier -- without this, a single Filament render fans out
     * into a handful of duplicate, uncached, non-eager-loaded subscription
     * queries against the same tenant.
     *
     * @var array<int|string, Collection<int, Subscription>>
     */
    private array $activeSubscriptions = [];

    public function __construct(
        private SubscriptionService $subscriptionService,
    ) {}

    public function freeRunsLimit(string $email): int
    {
        $bonus = 0;

        $userId = User::where('email', $email)->value('id');
        if ($userId !== null) {
            $bonus = (int) UserParameter::query()
                ->where('user_id', $userId)
                ->where('name', self::BONUS_PARAM)
                ->value('value');
        }

        return (int) config('audit.free_reports_limit') + $bonus;
    }

    public function freeRunsUsed(string $email): int
    {
        return AuditRequest::query()
            ->where('email', $email)
            ->where('free_run', true)
            ->count();
    }

    public function hasFreeRun(string $email): bool
    {
        return $this->freeRunsUsed($email) < $this->freeRunsLimit($email);
    }

    /** Spends a free run. Sets both markers so metering and the lifetime
     *  count can never disagree about how a run was funded. */
    public function consumeFreeRun(AuditRequest $auditRequest): void
    {
        $auditRequest->update([
            'free_run' => true,
            'funding' => AuditFunding::FREE->value,
        ]);
    }

    /**
     * Plan-metadata key per metered tier -- one key per tier, no aliases.
     * DIAGNOSTIC is listed like any other: a tenant with no plan still falls
     * back to the lifetime free quota in quotaFor(), so being metered here
     * costs a plan-less user nothing.
     */
    private const QUOTA_KEYS = [
        AuditTier::DIAGNOSTIC->value => 'audit_diagnostic_credits',
        AuditTier::DEEP_AI->value => 'audit_deep_ai_credits',
        AuditTier::EXPERT->value => 'audit_expert_credits',
    ];

    public function allowance(?Tenant $tenant, AuditTier $tier): int
    {
        $key = self::QUOTA_KEYS[$tier->value] ?? null;

        if ($tenant === null || $key === null) {
            return 0;
        }

        return $this->planMetadata($tenant, $key);
    }

    private function planMetadata(Tenant $tenant, string $key): int
    {
        return (int) $this->activeSubscriptionsFor($tenant)
            ->map(function (Subscription $subscription) use ($key): int {
                /** @var Plan|null $plan */
                $plan = $subscription->plan;

                if ($plan === null) {
                    return 0;
                }

                /** @var Product|null $product */
                $product = $plan->product;

                return $product === null ? 0 : (int) data_get($product->metadata, $key, 0);
            })
            ->max();
    }

    /**
     * @return Collection<int, Subscription>
     */
    private function activeSubscriptionsFor(Tenant $tenant): Collection
    {
        return $this->activeSubscriptions[$tenant->id] ??= $this->subscriptionService->findActiveTenantSubscriptions($tenant);
    }

    /**
     * Runs this calendar month that came out of the plan.
     *
     * Keyed on `funding`, not `source`: a checkout intent awaiting payment and
     * a purchased run are both dashboard-sourced but neither spends quota.
     */
    public function runsUsedThisMonth(User $user, AuditTier $tier): int
    {
        return AuditRequest::query()
            ->where('user_id', $user->id)
            ->where('funding', AuditFunding::ALLOWANCE->value)
            ->where('tier', $tier->value)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    public function remainingRuns(User $user, ?Tenant $tenant, AuditTier $tier): int
    {
        return $this->quotaFor($user, $tenant, $tier)->remaining();
    }

    public function quotaFor(User $user, ?Tenant $tenant, AuditTier $tier): TierQuota
    {
        // Diagnostic defaults to the per-email lifetime free-run count, but a
        // tenant whose plan grants a monthly Diagnostic allowance (every
        // paid plan does) uses that instead -- same metered
        // semantics every other tier already has, so nothing downstream
        // needs to know Diagnostic is special-cased at all once this is
        // decided.
        $subscriptionAllowance = $this->allowance($tenant, $tier);
        $isLifetime = $tier === AuditTier::DIAGNOSTIC && $subscriptionAllowance === 0;

        return new TierQuota(
            tier: $tier,
            label: $tier->label(),
            limit: $isLifetime ? $this->freeRunsLimit($user->email) : $subscriptionAllowance,
            used: $isLifetime ? $this->freeRunsUsed($user->email) : $this->runsUsedThisMonth($user, $tier),
            isLifetime: $isLifetime,
            priceCents: $this->tierPriceCents($tier),
        );
    }

    /** @return list<TierQuota> */
    public function quotas(User $user, ?Tenant $tenant): array
    {
        return array_map(
            fn (AuditTier $tier): TierQuota => $this->quotaFor($user, $tenant, $tier),
            AuditTier::cases(),
        );
    }

    private function tierPriceCents(AuditTier $tier): ?int
    {
        return $tier->priceCents();
    }

    public function hasAuditAccess(User $user, ?Tenant $tenant): bool
    {
        if (AuditRequest::forUser($user)->exists()) {
            return true;
        }

        // A user who signs up directly has neither a prior request nor a
        // subscription, but still holds the free-run quota. Omitting this
        // deadlocks them: the dashboard UI that creates their first request
        // stays hidden precisely because they have no request yet.
        if ($this->hasFreeRun($user->email)) {
            return true;
        }

        // Any metered tier with a nonzero allowance grants access -- a tenant
        // holding only Expert credits must not be locked out of the nav.
        if ($tenant !== null) {
            foreach (array_keys(self::QUOTA_KEYS) as $tierValue) {
                if ($this->allowance($tenant, AuditTier::from($tierValue)) > 0) {
                    return true;
                }
            }
        }

        // Finally, a tier the user can simply buy is itself access. With the
        // free quota at its production default of zero, this is the arm that
        // keeps a fresh direct signup out of a deadlock: no request, no free
        // run and no subscription used to hide the entire dashboard audit UI,
        // including the only in-app route to a checkout. A plain config
        // lookup (not quotaFor()) -- purchasable() is priceCents !== null,
        // and quotaFor() would re-run hasFreeRun()'s queries for no reason.
        foreach (AuditTier::cases() as $tier) {
            if ($tier->priceCents() !== null) {
                return true;
            }
        }

        return false;
    }
}
