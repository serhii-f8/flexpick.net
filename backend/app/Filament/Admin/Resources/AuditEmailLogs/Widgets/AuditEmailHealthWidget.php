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

    protected function getStats(): array
    {
        $windowHours = (int) config('audit.delivery_rate_window_hours');

        $attempted = AuditEmailLog::query()->attemptedWithin($windowHours)->count();
        $failed = AuditEmailLog::query()->failedWithin($windowHours)->count();

        $rate = $attempted === 0
            ? '—'
            : round(($attempted - $failed) / $attempted * 100).'%';

        return [
            Stat::make(__('Delivered (7 days)'), $rate)
                ->color($attempted > 0 && ($failed / $attempted * 100) > (int) config('health.flexpick.mail_failure.fail_percent') ? 'danger' : 'success'),
            Stat::make(__('Attempted (7 days)'), $attempted)->color('gray'),
            Stat::make(__('Failed (7 days)'), $failed)->color($failed > 0 ? 'danger' : 'gray'),
        ];
    }
}
