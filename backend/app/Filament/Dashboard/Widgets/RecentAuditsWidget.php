<?php

namespace App\Filament\Dashboard\Widgets;

use App\Filament\Dashboard\Resources\AuditRequests\AuditRequestResource;
use App\Mapper\AuditRequestStatusMapper;
use App\Models\AuditRequest;
use App\Services\AuditReport\AuditEntitlementService;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentAuditsWidget extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        // Resolved once per render and closed over below. Calling this inside
        // the column closure would issue one query per row.
        $previousScores = $this->previousScores();

        return $table
            ->heading(__('Recent audits'))
            ->query(
                AuditRequest::forUser(auth()->user())
                    ->with('report')
                    ->latest()
                    ->limit(5)
            )
            ->paginated(false)
            ->columns([
                TextColumn::make('repo_url')
                    ->label(__('Repository'))
                    ->limit(50)
                    ->placeholder(__('No repository')),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (AuditRequest $record, AuditRequestStatusMapper $mapper): string => $mapper->mapColor($record->status))
                    ->formatStateUsing(fn (string $state, AuditRequestStatusMapper $mapper): string => $mapper->mapForDisplay($state)),
                TextColumn::make('score')
                    ->label(__('Score'))
                    ->badge()
                    ->color('gray')
                    ->state(function (AuditRequest $record) use ($previousScores): string {
                        $score = data_get($record->report?->payload, 'scores.overall');

                        if ($score === null) {
                            return '—';
                        }

                        $previous = $previousScores[$record->repo_url] ?? null;

                        if ($previous === null) {
                            return (string) $score;
                        }

                        return $score.' ('.sprintf('%+d', $score - $previous).')';
                    }),
                TextColumn::make('created_at')
                    ->label(__('Submitted'))
                    ->dateTime(config('app.datetime_format')),
            ])
            ->recordUrl(fn (AuditRequest $record): string => AuditRequestResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading(__('No audits yet'))
            ->emptyStateDescription(__('Run your first audit to see results here.'))
            ->emptyStateIcon('heroicon-o-document-magnifying-glass');
    }

    /**
     * Previous overall score per repo_url, for the repos currently in view.
     * One query total; computing this per row would be an N+1.
     *
     * @return array<string, int>
     */
    private function previousScores(): array
    {
        $user = auth()->user();

        return AuditRequest::forUser($user)
            ->with('report')
            ->whereNotNull('repo_url')
            ->latest()
            ->get()
            ->groupBy('repo_url')
            ->map(function ($requests): ?int {
                $scored = $requests
                    ->map(fn (AuditRequest $r): ?int => data_get($r->report?->payload, 'scores.overall'))
                    ->filter(fn (?int $s): bool => $s !== null)
                    ->values();

                // Index 0 is the newest; index 1 is what we compare against.
                return $scored->get(1);
            })
            ->filter()
            ->all();
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
