<?php

namespace App\Filament\Admin\Resources\AuditEmailLogs\Widgets;

use App\Models\AuditEmailLog;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Same window and same scopes as AuditAdminStatsWidget's email tile, so the
 * dashboard and this page cannot report different numbers.
 */
class AuditEmailHealthWidget extends BaseWidget
{
    protected ?string $pollingInterval = null;

    // Filament 5 widgets lazy-load by default (AJAX-fetched after an
    // x-intersect), so a plain, non-Livewire GET request never sees this
    // widget's content. This strip is meant to be visible immediately.
    protected static bool $isLazy = false;

    private const WINDOW_HOURS = 168;

    protected function getStats(): array
    {
        $attempted = AuditEmailLog::query()->attemptedWithin(self::WINDOW_HOURS)->count();
        $failed = AuditEmailLog::query()->failedWithin(self::WINDOW_HOURS)->count();

        $rate = $attempted === 0
            ? '—'
            : round(($attempted - $failed) / $attempted * 100).'%';

        return [
            Stat::make(__('Delivered (7 days)'), $rate)
                ->color($attempted > 0 && $failed / $attempted > 0.25 ? 'danger' : 'success'),
            Stat::make(__('Attempted (7 days)'), $attempted)->color('gray'),
            Stat::make(__('Failed (7 days)'), $failed)->color($failed > 0 ? 'danger' : 'gray'),
        ];
    }
}
