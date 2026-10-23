<?php

namespace App\Filament\Dashboard\Widgets;

use App\Filament\Dashboard\Pages\AuditInsights;
use App\Services\AuditReport\AuditInsightsService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AuditInsightsStatsWidget extends BaseWidget
{
    /** Rendered on the Audit Insights page only. */
    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $stats = app(AuditInsightsService::class)->stats();

        if ($stats === null) {
            return [];
        }

        $pct = fn (?int $value): string => $value === null ? '—' : $value.'%';
        $metrics = $stats['metrics'];

        return [
            Stat::make(__('Audits performed'), number_format($stats['audits_total']))
                ->description(__('Completed audit runs on the platform')),
            Stat::make(__('Codebases analyzed'), number_format($stats['codebases']))
                ->description(__(':lines lines of code', ['lines' => self::compact($stats['loc_analyzed'])])),
            Stat::make(__('Exposed secrets'), $pct($metrics['secrets_pct']))
                ->description(__('of codebases had API keys or credentials in the code'))
                ->color('danger'),
            Stat::make(__('No automated tests'), $pct($metrics['no_tests_pct']))
                ->description(__('of codebases had no test files at all'))
                ->color('warning'),
            Stat::make(__('Vulnerable dependencies'), $pct($metrics['vulnerable_dependencies_pct']))
                ->description(__('of codebases used a package with a known vulnerability'))
                ->color('danger'),
            Stat::make(__('No CI pipeline'), $pct($metrics['no_ci_pct']))
                ->description(__('of codebases had no continuous integration'))
                ->color('warning'),
            Stat::make(__('No error monitoring'), $pct($metrics['no_error_monitoring_pct']))
                ->description(__('of codebases would not notice errors in production'))
                ->color('warning'),
            Stat::make(__('Average health score'), $stats['average_score'] === null ? '—' : $stats['average_score'].'/100')
                ->description(__(':pct scored below 50', ['pct' => $pct($metrics['low_score_pct'])])),
        ];
    }

    public static function canView(): bool
    {
        return AuditInsights::canAccess();
    }

    /** 2400000 → "2.4M": a marketing number, not an accounting one. */
    private static function compact(int $number): string
    {
        return match (true) {
            $number >= 1_000_000 => round($number / 1_000_000, 1).'M',
            $number >= 1_000 => round($number / 1_000).'K',
            default => (string) $number,
        };
    }
}
