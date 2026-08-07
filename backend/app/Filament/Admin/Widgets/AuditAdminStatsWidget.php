<?php

namespace App\Filament\Admin\Widgets;

use App\Constants\AuditRequestStatus;
use App\Filament\Admin\Resources\AuditEmailLogs\AuditEmailLogResource;
use App\Filament\Admin\Resources\AuditRequests\AuditRequestResource;
use App\Filament\Admin\Resources\ExpertReviews\ExpertReviewResource;
use App\Mapper\AuditRequestStatusMapper;
use App\Models\AuditEmailLog;
use App\Models\AuditRequest;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Spatie\Health\ResultStores\ResultStore;
use Throwable;

/**
 * The operator's "is anything on fire" block. Deliberately does NOT use
 * InteractsWithPageFilters: the dashboard's date range applies to revenue
 * metrics, but a date-ranged failure count answers a question nobody asks
 * during triage.
 */
class AuditAdminStatsWidget extends BaseWidget
{
    /** Below the revenue widgets, which occupy sorts 0-3. */
    protected static ?int $sort = 10;

    protected ?string $pollingInterval = '60s';

    protected function getHeading(): ?string
    {
        return __('Audit operations');
    }

    protected function getDescription(): ?string
    {
        return __('Live · :freshness', ['freshness' => $this->healthFreshness()]);
    }

    protected function getStats(): array
    {
        $deliveryRateDescription = $this->deliveryRateDescription();

        return [
            $this->problemStat(
                label: __('Failed audits'),
                count: AuditRequest::query()
                    ->where('status', AuditRequestStatus::FAILED->value)
                    ->where('created_at', '>=', now()->subDay())
                    ->count(),
                color: 'danger',
                icon: 'heroicon-m-x-circle',
                url: AuditRequestResource::getUrl('index', ['activeTab' => 'failed'], panel: 'admin'),
            ),
            $this->problemStat(
                label: __('Stuck in pipeline'),
                count: AuditRequest::query()->stuck()->count(),
                color: 'danger',
                icon: 'heroicon-m-clock',
                url: AuditRequestResource::getUrl('index', ['activeTab' => 'stuck'], panel: 'admin'),
                description: __('queued >:queued m or analyzing >:analyzing m', [
                    'queued' => (int) config('health.flexpick.oldest_queued_minutes'),
                    'analyzing' => (int) config('health.flexpick.oldest_analyzing_minutes'),
                ]),
            ),
            $this->problemStat(
                label: __('Needs manual action'),
                count: AuditRequest::query()->needsManualAction()->count(),
                color: 'warning',
                icon: 'heroicon-m-hand-raised',
                url: AuditRequestResource::getUrl('index', ['activeTab' => 'needs-action'], panel: 'admin'),
                description: $this->manualActionBreakdown(),
            ),
            $this->problemStat(
                label: __('Expert review overdue'),
                count: AuditRequest::query()->breachingExpertReviewSla()->count(),
                color: 'warning',
                icon: 'heroicon-m-clipboard-document-check',
                url: ExpertReviewResource::getUrl('index', panel: 'admin'),
                description: $this->oldestBreachingReview(),
            ),
            $this->problemStat(
                label: __('Email failures'),
                count: $this->emailFailures(),
                color: 'warning',
                icon: 'heroicon-m-envelope',
                url: AuditEmailLogResource::getUrl('index', ['activeTab' => 'failed-24h'], panel: 'admin'),
                description: $deliveryRateDescription,
                // The delivery rate is worth reading even when nothing failed.
                descriptionWhenQuiet: $deliveryRateDescription,
            ),
            Stat::make(__('Pipeline'), $this->queueDepth())
                ->description(__('avg :time · :count audits today', [
                    'time' => $this->averageProcessingTime(),
                    'count' => AuditRequest::query()->whereDate('created_at', today())->count(),
                ]))
                ->url('/horizon', shouldOpenInNewTab: true),
        ];
    }

    /**
     * A problem tile is gray, iconless and unlinked at zero, and coloured,
     * icon-bearing and clickable when it is not. Severity has to be legible
     * before the number is read -- which is exactly what the previous ten
     * identical tiles failed to do.
     */
    private function problemStat(
        string $label,
        int $count,
        string $color,
        string $icon,
        string $url,
        ?string $description = null,
        ?string $descriptionWhenQuiet = null,
    ): Stat {
        $stat = Stat::make($label, $count);

        if ($count === 0) {
            return $stat
                ->color('gray')
                ->description($descriptionWhenQuiet ?? __('All clear'));
        }

        return $stat
            ->color($color)
            ->icon($icon)
            ->description($description)
            ->descriptionColor($color)
            ->url($url);
    }

    private function manualActionBreakdown(): ?string
    {
        $counts = AuditRequest::query()
            ->needsManualAction()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        if ($counts->isEmpty()) {
            return null;
        }

        $mapper = app(AuditRequestStatusMapper::class);

        return $counts
            ->map(fn (int $count, string $status): string => $count.' '.mb_strtolower($mapper->mapForDisplay($status)))
            ->implode(' · ');
    }

    private function oldestBreachingReview(): ?string
    {
        $oldest = AuditRequest::query()->breachingExpertReviewSla()->min('analysis_completed_at');

        if ($oldest === null) {
            return null;
        }

        return __('oldest waiting :hours h', [
            'hours' => (int) Carbon::parse($oldest)->diffInHours(now(), true),
        ]);
    }

    private function emailFailures(): int
    {
        // Tolerate the table being absent so the block still renders on a
        // partial schema.
        if (! Schema::hasTable('audit_email_logs')) {
            return 0;
        }

        return AuditEmailLog::query()->failedWithin()->count();
    }

    private function deliveryRateDescription(): ?string
    {
        if (! Schema::hasTable('audit_email_logs')) {
            return null;
        }

        $windowHours = (int) config('audit.delivery_rate_window_hours');

        $attempted = AuditEmailLog::query()->attemptedWithin($windowHours)->count();

        if ($attempted === 0) {
            return null;
        }

        $failed = AuditEmailLog::query()->failedWithin($windowHours)->count();

        return __(':rate% delivered over 7 days', [
            'rate' => (int) round(($attempted - $failed) / $attempted * 100),
        ]);
    }

    private function averageProcessingTime(): string
    {
        $seconds = AuditRequest::query()
            ->whereNotNull('analysis_started_at')
            ->whereNotNull('analysis_completed_at')
            ->select(DB::raw('AVG(TIMESTAMPDIFF(SECOND, analysis_started_at, analysis_completed_at)) as avg_seconds'))
            ->value('avg_seconds');

        if ($seconds === null) {
            return '—';
        }

        return $seconds >= 3600
            ? round($seconds / 3600, 1).'h'
            : round($seconds / 60).'m';
    }

    private function queueDepth(): int|string
    {
        try {
            return Queue::connection('redis-audit')->size((string) config('audit.queue'));
        } catch (Throwable) {
            return '—';
        }
    }

    /**
     * The dead-man's switch: if health:check stops storing results while this
     * block keeps rendering, a frozen scheduler is visible here rather than
     * silently freezing every number above it.
     */
    private function healthFreshness(): string
    {
        try {
            $results = app(ResultStore::class)->latestResults();
        } catch (Throwable) {
            return __('health checks unavailable');
        }

        if ($results === null) {
            return __('health checks have never run');
        }

        return __('health checks last ran :minutes min ago', [
            'minutes' => (int) Carbon::instance($results->finishedAt)->diffInMinutes(now(), true),
        ]);
    }

    public static function canView(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }
}
