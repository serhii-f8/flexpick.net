<?php

namespace App\Services\AuditReport;

use App\Constants\AuditFunding;
use App\Constants\AuditTier;
use App\Models\AuditRequest;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantParameter;
use App\Services\SubscriptionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who may run an audit, and out of which pool.
 *
 * Every quota is a *workspace* quota: plan allowance (metered monthly),
 * purchased one-time credits (never expire) and the lifetime free-run
 * quota all live on the Tenant, so every member draws from the same pool
 * and sees the same remaining counts. The only email-keyed arm left is
 * the anonymous landing-page funnel (`*ForEmail`), where no workspace
 * exists yet; those rows are claimed into the visitor's first workspace by
 * ClaimAuditRequestsForTenant and count there from then on.
 */
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

    // -- Anonymous funnel (no workspace yet) --------------------------------

    /** The configured quota only: a bonus belongs to a workspace, not an inbox. */
    public function freeRunsLimitForEmail(string $email): int
    {
        return (int) config('audit.free_reports_limit');
    }

    public function freeRunsUsedForEmail(string $email): int
    {
        return AuditRequest::query()
            ->where('email', $email)
            ->where('free_run', true)
            ->whereNull('credit_refunded_at')
            ->count();
    }

    public function hasFreeRunForEmail(string $email): bool
    {
        return $this->freeRunsUsedForEmail($email) < $this->freeRunsLimitForEmail($email);
    }

    // -- Workspace ---------------------------------------------------------

    public function freeRunsLimit(Tenant $tenant): int
    {
        return (int) config('audit.free_reports_limit') + $this->tenantParameter($tenant, self::BONUS_PARAM);
    }

    public function freeRunsUsed(Tenant $tenant): int
    {
        return AuditRequest::query()
            ->forTenant($tenant)
            ->where('free_run', true)
            ->whereNull('credit_refunded_at')
            ->count();
    }

    public function hasFreeRun(Tenant $tenant): bool
    {
        return $this->freeRunsUsed($tenant) < $this->freeRunsLimit($tenant);
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
     * Hands back the run a request spent, for a repository we never got to
     * analyze. Free and allowance runs are metered by the row itself, so
     * stamping it takes it out of the count; a purchase spent a credit (or
     * was paid by card), so it earns a fresh credit of its tier.
     *
     * Idempotent: returns false, and changes nothing, when the request was
     * already refunded.
     */
    public function refund(AuditRequest $auditRequest): bool
    {
        return DB::transaction(function () use ($auditRequest): bool {
            $claimed = AuditRequest::query()
                ->whereKey($auditRequest->getKey())
                ->whereNull('credit_refunded_at')
                ->update(['credit_refunded_at' => now()]);

            if ($claimed === 0) {
                return false;
            }

            $auditRequest->refresh();

            if ($auditRequest->funding === AuditFunding::PURCHASE && $auditRequest->tier !== null) {
                if ($auditRequest->tenant === null) {
                    $auditRequest->appendPipelineLog('refund_unassigned', 'Purchased run has no workspace to return its credit to');

                    return true;
                }

                $this->grantPurchasedCredit($auditRequest->tenant, $auditRequest->tier);
            }

            return true;
        });
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
                // The snapshot frozen at purchase wins: a partner-granted
                // override, or the base value as it stood when the customer
                // bought, must not drift when an admin edits the product
                // afterwards (spec §6.3).
                $snapshotValue = data_get($subscription->quota_snapshot, $key);

                if ($snapshotValue !== null) {
                    return (int) $snapshotValue;
                }

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
    public function runsUsedThisMonth(Tenant $tenant, AuditTier $tier): int
    {
        return AuditRequest::query()
            ->forTenant($tenant)
            ->where('funding', AuditFunding::ALLOWANCE->value)
            ->where('tier', $tier->value)
            ->where('created_at', '>=', now()->startOfMonth())
            ->whereNull('credit_refunded_at')
            ->count();
    }

    public function remainingRuns(Tenant $tenant, AuditTier $tier): int
    {
        return $this->quotaFor($tenant, $tier)->remaining();
    }

    public function quotaFor(Tenant $tenant, AuditTier $tier): TierQuota
    {
        // Diagnostic defaults to the workspace's lifetime free-run count, but
        // a plan that grants a monthly Diagnostic allowance (every paid plan
        // does) uses that instead -- same metered semantics every other tier
        // already has, so nothing downstream needs to know Diagnostic is
        // special-cased at all once this is decided.
        $subscriptionAllowance = $this->allowance($tenant, $tier);
        $isLifetime = $tier === AuditTier::DIAGNOSTIC && $subscriptionAllowance === 0;
        $baseLimit = $isLifetime ? $this->freeRunsLimit($tenant) : $subscriptionAllowance;

        return new TierQuota(
            tier: $tier,
            label: $tier->label(),
            // A purchased credit never expires and is never metered
            // monthly, so it always widens the limit rather than resetting
            // with the calendar -- see consume()/spendPurchasedCredit() for
            // how it's actually drawn down.
            limit: $baseLimit + $this->purchasedCreditBalance($tenant, $tier),
            used: $isLifetime ? $this->freeRunsUsed($tenant) : $this->runsUsedThisMonth($tenant, $tier),
            isLifetime: $isLifetime,
            priceCents: $this->tierPriceCents($tier),
        );
    }

    private function purchasedCreditParam(AuditTier $tier): string
    {
        return 'audit_purchased_credits_'.$tier->value;
    }

    public function purchasedCreditBalance(Tenant $tenant, AuditTier $tier): int
    {
        return $this->tenantParameter($tenant, $this->purchasedCreditParam($tier));
    }

    /**
     * Grants a one-time, never-expiring credit for the tier -- what a
     * one-time tier product purchase resolves to when there's no dashboard
     * intent or prior diagnostic to run it against immediately (see
     * HandleAuditTierOrder). Spent later via consume(), whenever any member
     * submits a run for any repo -- not tied to the purchase.
     */
    public function grantPurchasedCredit(Tenant $tenant, AuditTier $tier, int $quantity = 1): void
    {
        $this->adjustTenantParameter($tenant, $this->purchasedCreditParam($tier), $quantity);
    }

    public function spendPurchasedCredit(Tenant $tenant, AuditTier $tier): void
    {
        $this->adjustTenantParameter($tenant, $this->purchasedCreditParam($tier), -1);
    }

    /**
     * Decides which pool a new run draws from, spending it immediately if
     * it's a purchased credit -- called exactly once, at the moment an
     * AuditRequest is actually created (never merely to check availability;
     * use quotaFor()/TierQuota::hasRuns() for that). Plan allowance is
     * always drawn first: it resets every month regardless of use, while a
     * purchased credit never expires, so spending the credit before the
     * allowance would waste it for nothing.
     */
    public function consume(Tenant $tenant, AuditTier $tier, TierQuota $quota): AuditFunding
    {
        if ($quota->isLifetime && $this->hasFreeRun($tenant)) {
            return AuditFunding::FREE;
        }

        if (! $quota->isLifetime && $this->runsUsedThisMonth($tenant, $tier) < $this->allowance($tenant, $tier)) {
            return AuditFunding::ALLOWANCE;
        }

        $this->spendPurchasedCredit($tenant, $tier);

        return AuditFunding::PURCHASE;
    }

    /** @return list<TierQuota> */
    public function quotas(Tenant $tenant): array
    {
        return array_map(
            fn (AuditTier $tier): TierQuota => $this->quotaFor($tenant, $tier),
            AuditTier::cases(),
        );
    }

    private function tierPriceCents(AuditTier $tier): ?int
    {
        return $tier->priceCents();
    }

    public function hasAuditAccess(Tenant $tenant): bool
    {
        if (AuditRequest::forTenant($tenant)->exists()) {
            return true;
        }

        // A fresh workspace has neither a prior request nor a subscription,
        // but still holds the free-run quota. Omitting this deadlocks it:
        // the dashboard UI that creates the first request stays hidden
        // precisely because there is no request yet.
        if ($this->hasFreeRun($tenant)) {
            return true;
        }

        // Any metered tier with a nonzero allowance grants access -- a tenant
        // holding only Expert credits must not be locked out of the nav.
        foreach (array_keys(self::QUOTA_KEYS) as $tierValue) {
            if ($this->allowance($tenant, AuditTier::from($tierValue)) > 0) {
                return true;
            }
        }

        // Finally, a tier the workspace can simply buy is itself access. With
        // the free quota at its production default of zero, this is the arm
        // that keeps a fresh direct signup out of a deadlock: no request, no
        // free run and no subscription used to hide the entire dashboard
        // audit UI, including the only in-app route to a checkout. A plain
        // config lookup (not quotaFor()) -- purchasable() is
        // priceCents !== null, and quotaFor() would re-run hasFreeRun()'s
        // queries for no reason.
        foreach (AuditTier::cases() as $tier) {
            if ($tier->priceCents() !== null) {
                return true;
            }
        }

        return false;
    }

    private function tenantParameter(Tenant $tenant, string $name): int
    {
        return (int) TenantParameter::query()
            ->where('tenant_id', $tenant->id)
            ->where('name', $name)
            ->value('value');
    }

    /**
     * Never dips below zero: a spend on an empty balance is a no-op. The row
     * is locked for the read-modify-write so two members spending the same
     * credit at once, or a grant racing a spend, cannot lose an update.
     */
    private function adjustTenantParameter(Tenant $tenant, string $name, int $delta): void
    {
        DB::transaction(function () use ($tenant, $name, $delta): void {
            $param = TenantParameter::query()
                ->where('tenant_id', $tenant->id)
                ->where('name', $name)
                ->lockForUpdate()
                ->first();

            $param ??= TenantParameter::query()->create([
                'tenant_id' => $tenant->id,
                'name' => $name,
                'value' => '0',
            ]);

            $param->update(['value' => (string) max(0, ((int) $param->value) + $delta)]);
        });
    }
}
