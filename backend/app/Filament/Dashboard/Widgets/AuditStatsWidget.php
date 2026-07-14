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

    protected function getStats(): array
    {
        $user = auth()->user();
        $tenant = Filament::getTenant();
        $entitlements = app(AuditEntitlementService::class);

        $allowance = $tenant !== null ? $entitlements->subscriptionAllowance($tenant) : 0;

        if ($allowance > 0) {
            $used = $entitlements->dashboardRunsUsedThisMonth($user);
            $remaining = $entitlements->remainingDashboardRuns($user, $tenant);
            $usagePercent = (int) round(min($used, $allowance) / $allowance * 100);

            $quotaStat = Stat::make(__('Analyses remaining this month'), $remaining.' / '.$allowance)
                ->description(__(':percent% of your plan used', ['percent' => $usagePercent]))
                ->color($remaining > 0 ? 'success' : 'warning');
        } else {
            $limit = $entitlements->freeRunsLimit($user->email);
            $used = $entitlements->freeRunsUsed($user->email);

            $quotaStat = Stat::make(__('Free audits remaining'), max(0, $limit - $used).' / '.$limit)
                ->description(__(':used of :limit free audits used', ['used' => $used, 'limit' => $limit]))
                ->color($used < $limit ? 'success' : 'warning');
        }

        return [
            $quotaStat,
            Stat::make(__('In progress'), AuditRequest::forUser($user)->whereIn('status', [
                AuditRequestStatus::QUEUED->value,
                AuditRequestStatus::ANALYZING->value,
            ])->count())->color('info'),
            Stat::make(__('Completed'), AuditRequest::forUser($user)->whereIn('status', [
                AuditRequestStatus::REPORT_READY->value,
                AuditRequestStatus::SENT->value,
            ])->count())->color('success'),
            Stat::make(__('Failed'), AuditRequest::forUser($user)->where('status', AuditRequestStatus::FAILED->value)->count())
                ->color('danger'),
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
