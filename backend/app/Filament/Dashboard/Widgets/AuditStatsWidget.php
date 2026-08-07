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
        $buckets = self::statusBuckets();

        $definitions = [
            'in_progress' => [__('In progress'), 'info', 'heroicon-m-arrow-path', __('Being analyzed now')],
            'expert_review' => [__('Awaiting expert review'), 'warning', 'heroicon-m-eye', __('With a human reviewer')],
            'needs_action' => [__('Needs your action'), 'warning', 'heroicon-m-exclamation-triangle', __('Blocked until you respond')],
            'completed' => [__('Completed'), 'success', 'heroicon-m-check-circle', __('Reports delivered')],
            'failed' => [__('Failed'), 'danger', 'heroicon-m-x-circle', __('Could not be analyzed')],
        ];

        $stats = [];

        foreach ($definitions as $key => [$label, $color, $icon, $description]) {
            $count = AuditRequest::forUser($user)->whereIn('status', $buckets[$key])->count();

            // A wall of zeroes competes with the states that matter. Only
            // surface a bucket once it actually holds something.
            if ($count === 0) {
                continue;
            }

            $stats[] = Stat::make($label, $count)
                ->description($description)
                ->icon($icon)
                ->color($color);
        }

        return $stats;
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
