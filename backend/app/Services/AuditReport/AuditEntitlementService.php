<?php

namespace App\Services\AuditReport;

use App\Constants\AuditTier;
use App\Models\AuditRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserParameter;
use App\Services\SubscriptionService;

class AuditEntitlementService
{
    public const BONUS_PARAM = 'audit_bonus_free_runs';

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

    public function consumeFreeRun(AuditRequest $auditRequest): void
    {
        $auditRequest->update(['free_run' => true]);
    }

    public function subscriptionAllowance(Tenant $tenant): int
    {
        return $this->planMetadata($tenant, 'audit_analyses_per_month');
    }

    public function deepAiCredits(Tenant $tenant): int
    {
        return $this->planMetadata($tenant, 'audit_deep_ai_credits');
    }

    private function planMetadata(Tenant $tenant, string $key): int
    {
        return (int) $this->subscriptionService->findActiveTenantSubscriptions($tenant)
            ->map(fn ($subscription): int => (int) data_get($subscription->plan?->product?->metadata, $key, 0))
            ->max();
    }

    /** Automated-tier dashboard runs consume the subscription allowance. */
    public function dashboardRunsUsedThisMonth(User $user): int
    {
        return $this->runsThisMonth($user, AuditTier::AUTOMATED);
    }

    public function deepAiRunsUsedThisMonth(User $user): int
    {
        return $this->runsThisMonth($user, AuditTier::DEEP_AI);
    }

    private function runsThisMonth(User $user, AuditTier $tier): int
    {
        return AuditRequest::query()
            ->where('user_id', $user->id)
            ->where('source', 'dashboard')
            ->where('tier', $tier->value)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    public function remainingDashboardRuns(User $user, Tenant $tenant): int
    {
        return max(0, $this->subscriptionAllowance($tenant) - $this->dashboardRunsUsedThisMonth($user));
    }

    public function remainingDeepAiRuns(User $user, Tenant $tenant): int
    {
        return max(0, $this->deepAiCredits($tenant) - $this->deepAiRunsUsedThisMonth($user));
    }

    public function hasAuditAccess(User $user, ?Tenant $tenant): bool
    {
        if (AuditRequest::forUser($user)->exists()) {
            return true;
        }

        return $tenant !== null && $this->subscriptionAllowance($tenant) > 0;
    }
}
