<?php

namespace App\Filament\Admin\Widgets;

use App\Constants\SubscriptionStatus;
use App\Models\AuditRequest;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AuditsByPlanWidget extends BaseWidget
{
    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $audits = AuditRequest::query()
            ->where('created_at', '>=', now()->startOfMonth())
            ->with(['user.subscriptions' => fn ($query) => $query
                ->where('status', SubscriptionStatus::ACTIVE->value)
                ->where('ends_at', '>', now())
                ->with('plan'), ])
            ->get();

        $byPlan = $audits
            ->groupBy(function (AuditRequest $audit): string {
                $plan = $audit->user?->subscriptions->first()?->plan;

                return $plan?->name ?? __('Free / no plan');
            })
            ->map->count()
            ->sortDesc();

        if ($byPlan->isEmpty()) {
            return [Stat::make(__('Audits by plan (this month)'), 0)];
        }

        return $byPlan
            ->map(fn (int $count, string $planName): Stat => Stat::make($planName, $count)
                ->description(__('audits this month')))
            ->values()
            ->all();
    }

    public static function canView(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }
}
