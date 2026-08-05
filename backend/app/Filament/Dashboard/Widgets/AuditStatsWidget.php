<?php

namespace App\Filament\Dashboard\Widgets;

use App\Constants\AuditRequestStatus;
use App\Models\AuditRequest;
use App\Services\AuditReport\AuditEntitlementService;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AuditStatsWidget extends BaseWidget
{
    protected ?string $pollingInterval = null;

    protected static ?int $sort = 1;

    public static function statusBuckets(): array
    {
        return [
            'in_progress' => [
                AuditRequestStatus::NEW->value,
                AuditRequestStatus::PENDING_VERIFICATION->value,
                AuditRequestStatus::QUEUED->value,
                AuditRequestStatus::ANALYZING->value,
            ],
            'expert_review' => [AuditRequestStatus::EXPERT_REVIEW->value],
            'needs_action' => [
                AuditRequestStatus::NEEDS_FOLLOWUP->value,
                AuditRequestStatus::AWAITING_ACCESS->value,
                AuditRequestStatus::AWAITING_PAYMENT->value,
            ],
            'completed' => [AuditRequestStatus::REPORT_READY->value, AuditRequestStatus::SENT->value, AuditRequestStatus::HANDLED->value],
            'failed' => [AuditRequestStatus::FAILED->value],
        ];
    }

    protected function getStats(): array
    {
        $user = auth()->user();
        $tenant = Filament::getTenant();
        $entitlements = app(AuditEntitlementService::class);

        $allowance = $tenant !== null ? $entitlements->subscriptionAllowance($tenant) : 0;
        $deepAiCredits = $tenant !== null ? $entitlements->deepAiCredits($tenant) : 0;
        $stats = [];

        if ($allowance > 0) {
            $used = $entitlements->dashboardRunsUsedThisMonth($user);
            $remaining = $entitlements->remainingDashboardRuns($user, $tenant);
            $usagePercent = (int) round(min($used, $allowance) / $allowance * 100);

            $stats[] = Stat::make(__('Analyses remaining this month'), $remaining.' / '.$allowance)
                ->description(__(':percent% of your plan used', ['percent' => $usagePercent]))
                ->color($remaining > 0 ? 'success' : 'warning');
        } else {
            $limit = $entitlements->freeRunsLimit($user->email);
            $used = $entitlements->freeRunsUsed($user->email);

            $stats[] = Stat::make(__('Free audits remaining'), max(0, $limit - $used).' / '.$limit)
                ->description(__(':used of :limit free audits used', ['used' => $used, 'limit' => $limit]))
                ->color($used < $limit ? 'success' : 'warning');
        }

        if ($deepAiCredits > 0) {
            $remainingDeepAi = $entitlements->remainingDeepAiRuns($user, $tenant);

            $stats[] = Stat::make(__('Deep AI credits remaining this month'), $remainingDeepAi.' / '.$deepAiCredits)
                ->color($remainingDeepAi > 0 ? 'success' : 'warning');
        }

        $buckets = self::statusBuckets();

        return [
            ...$stats,
            Stat::make(__('In progress'), AuditRequest::forUser($user)->whereIn('status', $buckets['in_progress'])->count())->color('info'),
            Stat::make(__('Awaiting expert review'), AuditRequest::forUser($user)->whereIn('status', $buckets['expert_review'])->count())->color('warning'),
            Stat::make(__('Needs your action'), AuditRequest::forUser($user)->whereIn('status', $buckets['needs_action'])->count())->color('warning'),
            Stat::make(__('Completed'), AuditRequest::forUser($user)->whereIn('status', $buckets['completed'])->count())->color('success'),
            Stat::make(__('Failed'), AuditRequest::forUser($user)->whereIn('status', $buckets['failed'])->count())->color('danger'),
        ];
    }

    public static function canView(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        return app(AuditEntitlementService::class)->hasAuditAccess($user, Filament::getTenant());
    }
}
