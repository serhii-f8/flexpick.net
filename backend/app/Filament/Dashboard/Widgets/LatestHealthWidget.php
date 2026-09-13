<?php

namespace App\Filament\Dashboard\Widgets;

use App\Constants\AuditRequestStatus;
use App\Filament\Dashboard\Pages\AuditReports;
use App\Filament\Dashboard\Resources\AuditRequests\AuditRequestResource;
use App\Mapper\AuditRequestStatusMapper;
use App\Models\AuditRequest;
use App\Services\AuditReport\AuditEntitlementService;
use App\Services\AuditReport\AuditReportService;
use App\Services\AuditReport\ScoreChartBuilder;
use App\Support\RepoName;
use App\Support\ScoreBand;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/**
 * The first thing on the home page: the health of the repository the user
 * audited most recently. A scored repo shows its score, trend and risk mix;
 * an in-flight audit shows where it is; a fresh account gets an invitation.
 */
class LatestHealthWidget extends Widget
{
    protected string $view = 'filament.dashboard.widgets.latest-health-widget';

    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = ['default' => 1, 'md' => 2, 'xl' => 2];

    public static function canView(): bool
    {
        $tenant = Filament::getTenant();

        return auth()->check() && $tenant !== null && app(AuditEntitlementService::class)->hasAuditAccess($tenant);
    }

    protected function getViewData(): array
    {
        $tenant = Filament::getTenant();

        /** @var AuditRequest|null $latest */
        $latest = AuditRequest::forTenant($tenant)
            ->with('report')
            ->latest()
            ->first();

        $runUrl = AuditReports::getUrl();

        if ($latest === null) {
            return ['state' => 'empty', 'runUrl' => $runUrl];
        }

        $score = data_get($latest->report?->payload, 'scores.overall');

        if (! is_int($score) || $latest->isHeldForExpertReview()) {
            $mapper = app(AuditRequestStatusMapper::class);

            return [
                'state' => 'pending',
                'runUrl' => $runUrl,
                'repo' => RepoName::short($latest->repo_url),
                'statusLabel' => $latest->isHeldForExpertReview()
                    ? __('In expert review')
                    : ($latest->status === AuditRequestStatus::ANALYZING->value
                        ? __('Analyzing now')
                        : $mapper->mapForDisplay($latest->status)),
                'statusColor' => $mapper->mapColor($latest->status),
                'statusHint' => AuditRequestResource::statusDescription($latest),
                'viewUrl' => AuditRequestResource::getUrl('view', ['record' => $latest]),
                'submittedAt' => $latest->created_at,
            ];
        }

        $history = AuditRequest::forTenant($tenant)
            ->with('report')
            ->whereIn('repo_url', [rtrim($latest->repo_url, '/'), rtrim($latest->repo_url, '/').'/'])
            ->oldest()
            ->get()
            ->filter(fn (AuditRequest $request): bool => is_int(data_get($request->report?->payload, 'scores.overall')))
            ->values();

        $scores = $history->map(fn (AuditRequest $request): int => data_get($request->report->payload, 'scores.overall'));
        $previous = $scores->count() > 1 ? $scores->get($scores->count() - 2) : null;

        $risks = collect(data_get($latest->report->payload, 'risks', []))
            ->countBy('impact')
            ->only(['high', 'medium', 'low']);

        return [
            'state' => 'scored',
            'runUrl' => $runUrl,
            'repo' => RepoName::short($latest->repo_url),
            'tier' => $latest->tier?->label(),
            'completedAt' => $latest->report->created_at,
            'score' => $score,
            'band' => ScoreBand::fromScore($score),
            'delta' => $previous !== null ? $score - $previous : null,
            'chartPoints' => app(ScoreChartBuilder::class)->build(
                $scores,
                $history->map(fn (AuditRequest $request) => $request->report->created_at),
            ),
            'risks' => $risks,
            'reportUrl' => app(AuditReportService::class)->signedUrl($latest->report),
            'viewUrl' => AuditRequestResource::getUrl('view', ['record' => $latest]),
        ];
    }
}
