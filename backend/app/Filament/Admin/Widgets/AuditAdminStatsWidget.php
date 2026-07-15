<?php

namespace App\Filament\Admin\Widgets;

use App\Constants\AuditRequestStatus;
use App\Models\AuditRequest;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

class AuditAdminStatsWidget extends BaseWidget
{
    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $pending = [AuditRequestStatus::NEW->value, AuditRequestStatus::QUEUED->value, AuditRequestStatus::PENDING_VERIFICATION->value];
        $completed = [AuditRequestStatus::REPORT_READY->value, AuditRequestStatus::SENT->value];
        $manual = [AuditRequestStatus::NEEDS_FOLLOWUP->value, AuditRequestStatus::AWAITING_ACCESS->value, AuditRequestStatus::AWAITING_PAYMENT->value];

        return [
            Stat::make(__('Total audits'), AuditRequest::count())
                ->description(__(':today today · :week this week · :month this month', [
                    'today' => AuditRequest::whereDate('created_at', today())->count(),
                    'week' => AuditRequest::where('created_at', '>=', now()->startOfWeek())->count(),
                    'month' => AuditRequest::where('created_at', '>=', now()->startOfMonth())->count(),
                ])),
            Stat::make(__('Pending'), AuditRequest::whereIn('status', $pending)->count())->color('gray'),
            Stat::make(__('Analyzing'), AuditRequest::where('status', AuditRequestStatus::ANALYZING->value)->count())->color('info'),
            Stat::make(__('Completed'), AuditRequest::whereIn('status', $completed)->count())->color('success'),
            Stat::make(__('Failed'), AuditRequest::where('status', AuditRequestStatus::FAILED->value)->count())->color('danger'),
            Stat::make(__('Needs manual action'), AuditRequest::whereIn('status', $manual)->count())->color('warning'),
            Stat::make(__('Avg processing time'), $this->averageProcessingTime())
                ->description(__('From analysis start to report')),
            Stat::make(__('Email failures'), $this->emailFailures())->color('danger'),
            Stat::make(__('Audit queue depth'), $this->queueDepth())
                ->description(__('Jobs waiting on the audit queue'))
                ->url('/horizon', shouldOpenInNewTab: true),
        ];
    }

    private function averageProcessingTime(): string
    {
        $seconds = AuditRequest::whereNotNull('analysis_started_at')
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

    private function emailFailures(): int
    {
        // audit_email_logs ships with the Mailcoach workstream; render 0 until it lands
        if (! Schema::hasTable('audit_email_logs')) {
            return 0;
        }

        return (int) DB::table('audit_email_logs')->where('status', 'failed')->count();
    }

    private function queueDepth(): int|string
    {
        try {
            return Queue::connection('redis-audit')->size((string) config('audit.queue'));
        } catch (\Throwable) {
            return '—';
        }
    }

    public static function canView(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }
}
