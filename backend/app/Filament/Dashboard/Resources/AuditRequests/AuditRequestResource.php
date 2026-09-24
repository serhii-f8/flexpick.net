<?php

namespace App\Filament\Dashboard\Resources\AuditRequests;

use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Filament\Dashboard\Resources\AuditRequests\Pages\ListAuditRequests;
use App\Filament\Dashboard\Resources\AuditRequests\Pages\ViewAuditRequest;
use App\Mapper\AuditRequestStatusMapper;
use App\Models\AuditRequest;
use App\Services\AuditReport\AuditEntitlementService;
use App\Services\AuditReport\AuditReportService;
use App\Support\RepoName;
use App\Support\ScoreBand;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AuditRequestResource extends Resource
{
    protected static ?string $model = AuditRequest::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static string|\UnitEnum|null $navigationGroup = 'Audits';

    protected static ?int $navigationSort = 2;

    protected static bool $isScopedToTenant = false;

    public static function getModelLabel(): string
    {
        return __('Audit');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Audits');
    }

    public static function getNavigationLabel(): string
    {
        return __('Audit history');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            // @phpstan-ignore-next-line method.notFound (forTenant is AuditRequest's own scope; Larastan can't see it through the parent's generic Builder<Model> return type)
            ->forTenant(Filament::getTenant())
            ->with(['report', 'user']);
    }

    public static function shouldRegisterNavigation(): bool
    {
        $tenant = Filament::getTenant();

        return auth()->check() && $tenant !== null && app(AuditEntitlementService::class)->hasAuditAccess($tenant);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('repo_url')
                    ->label(__('Repository'))
                    ->formatStateUsing(fn (string $state): string => RepoName::short($state))
                    ->tooltip(fn (AuditRequest $record): ?string => $record->repo_url)
                    ->extraAttributes(['class' => 'fp-repo'])
                    ->placeholder(__('No repository'))
                    ->searchable(),
                TextColumn::make('user.name')
                    ->label(__('Requested by'))
                    ->default(fn (AuditRequest $record): string => $record->name)
                    ->toggleable(),
                TextColumn::make('tier')
                    ->label(__('Audit type'))
                    ->badge()
                    ->color(fn (AuditTier $state): string => $state->badgeColor())
                    ->formatStateUsing(fn (AuditTier $state): string => $state->labelWithPrice()),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (AuditRequest $record, AuditRequestStatusMapper $mapper): string => $mapper->mapColor($record->status))
                    ->formatStateUsing(fn (string $state, AuditRequestStatusMapper $mapper): string => $mapper->mapForDisplay($state)),
                TextColumn::make('score')
                    ->label(__('Score'))
                    ->view('filament.dashboard.partials.score-cell'),
                TextColumn::make('source')
                    ->label(__('Source'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label(__('Submitted'))
                    ->dateTime(config('app.datetime_format'))
                    ->sortable(),
                TextColumn::make('report.created_at')
                    ->label(__('Completed'))
                    ->dateTime(config('app.datetime_format'))
                    ->placeholder('—'),
            ])
            ->recordUrl(fn (AuditRequest $record): string => static::getUrl('view', ['record' => $record]))
            ->filters([
                SelectFilter::make('status')
                    ->multiple()
                    ->options(
                        collect(AuditRequestStatus::cases())
                            ->mapWithKeys(fn (AuditRequestStatus $status) => [$status->value => app(AuditRequestStatusMapper::class)->mapForDisplay($status->value)])
                            ->all()
                    ),
                Filter::make('submitted')
                    ->schema([
                        DatePicker::make('submitted_from')->label(__('Submitted from')),
                        DatePicker::make('submitted_until')->label(__('Submitted until')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['submitted_from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                            ->when($data['submitted_until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->emptyStateHeading(__('No audits yet'))
            ->emptyStateDescription(__('Every audit you run shows up here with its status and score.'))
            ->emptyStateIcon('heroicon-o-document-magnifying-glass')
            ->defaultSort('created_at', 'desc');
    }

    /**
     * The result leads: the score panel is what the customer came for, the
     * timeline tells them where a pending audit stands, and the request
     * details sit last because they already know what they submitted.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 3])->columnSpanFull()->schema([
                Section::make(__('Results'))
                    ->columnSpan(['default' => 1, 'lg' => 2])
                    ->visible(fn (AuditRequest $record): bool => static::hasVisibleResults($record))
                    ->schema([
                        ViewEntry::make('results')
                            ->hiddenLabel()
                            ->view('filament.dashboard.partials.audit-results')
                            ->viewData(fn (AuditRequest $record): array => static::resultsViewData($record)),
                    ]),
                Section::make(__('Status'))
                    ->columnSpan(['default' => 1, 'lg' => fn (AuditRequest $record): int => static::hasVisibleResults($record) ? 1 : 3])
                    ->schema([
                        ViewEntry::make('timeline')
                            ->hiddenLabel()
                            ->view('filament.dashboard.partials.audit-timeline')
                            ->viewData(fn (AuditRequest $record): array => static::timelineViewData($record)),
                    ]),
            ]),
            Section::make(__('Request details'))
                ->collapsible()
                ->columnSpanFull()
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    TextEntry::make('repo_url')
                        ->label(__('Repository'))
                        ->url(fn (AuditRequest $record): ?string => $record->repo_url, shouldOpenInNewTab: true)
                        ->extraAttributes(['class' => 'fp-repo'])
                        ->placeholder(__('No repository')),
                    TextEntry::make('branch')
                        ->label(__('Branch'))
                        ->placeholder(__('Default branch')),
                    TextEntry::make('name')->label(__('Submitted by')),
                    TextEntry::make('email')->label(__('Report goes to')),
                    TextEntry::make('message')
                        ->label(__('Your note'))
                        ->columnSpanFull()
                        ->placeholder('—'),
                ]),
        ]);
    }

    public static function hasVisibleResults(AuditRequest $record): bool
    {
        return $record->report !== null
            && is_int(data_get($record->report->payload, 'scores.overall'))
            && ! $record->isHeldForExpertReview();
    }

    /** @return array<string, mixed> */
    public static function resultsViewData(AuditRequest $record): array
    {
        $payload = $record->report?->payload ?? [];
        $scores = collect(data_get($payload, 'scores', []));
        $overall = $scores->get('overall');

        return [
            'overall' => is_int($overall) ? $overall : null,
            'band' => is_int($overall) ? ScoreBand::fromScore($overall) : null,
            'summary' => data_get($payload, 'summary'),
            'categories' => $scores->except('overall')
                ->filter(fn ($value): bool => is_int($value))
                ->mapWithKeys(fn (int $value, string $key): array => [__(ucfirst(str_replace('_', ' ', $key))) => $value]),
            'risks' => collect(data_get($payload, 'risks', []))->countBy('impact')->only(['high', 'medium', 'low']),
            'fixFirst' => collect(data_get($payload, 'fix_first_plan', []))->take(3),
            'reportUrl' => $record->report !== null ? app(AuditReportService::class)->signedUrl($record->report) : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function timelineViewData(AuditRequest $record): array
    {
        $mapper = app(AuditRequestStatusMapper::class);
        $status = $record->status;

        $failed = in_array($status, [AuditRequestStatus::FAILED->value, AuditRequestStatus::NOT_ANALYZABLE->value], true);
        $verified = $record->email_verified_at !== null;
        $delivered = in_array($status, [
            AuditRequestStatus::REPORT_READY->value,
            AuditRequestStatus::SENT->value,
            AuditRequestStatus::HANDLED->value,
        ], true);
        $analyzed = $delivered || $record->report !== null || $record->isHeldForExpertReview();
        $analyzing = $status === AuditRequestStatus::ANALYZING->value;

        $steps = [
            ['label' => __('Submitted'), 'at' => $record->created_at, 'state' => 'done'],
            ['label' => __('Email verified'), 'at' => $record->email_verified_at, 'state' => $verified ? 'done' : ($failed ? 'skipped' : 'current')],
            ['label' => __('Analyzed'), 'at' => $analyzed ? $record->report?->created_at : null, 'state' => $analyzed ? 'done' : ($failed ? 'failed' : ($verified ? 'current' : 'todo'))],
            ['label' => __('Report sent'), 'at' => $delivered ? $record->report?->created_at : null, 'state' => $delivered ? 'done' : ($failed ? 'skipped' : ($analyzed ? 'current' : 'todo'))],
        ];

        if ($analyzing) {
            $steps[2]['state'] = 'current';
        }

        return [
            'steps' => $steps,
            'statusLabel' => $mapper->mapForDisplay($status),
            'statusColor' => $mapper->mapColor($status),
            'statusHint' => static::statusDescription($record),
            'failureReason' => $record->failure_reason,
            'blocked' => in_array($status, [
                AuditRequestStatus::NEEDS_FOLLOWUP->value,
                AuditRequestStatus::AWAITING_ACCESS->value,
                AuditRequestStatus::AWAITING_PAYMENT->value,
                AuditRequestStatus::PENDING_VERIFICATION->value,
            ], true),
        ];
    }

    public static function statusDescription(AuditRequest $record): string
    {
        return match ($record->status) {
            AuditRequestStatus::PENDING_VERIFICATION->value => __('Waiting for email confirmation.'),
            AuditRequestStatus::NEW->value, AuditRequestStatus::QUEUED->value => __('Your audit is queued and will start shortly.'),
            AuditRequestStatus::ANALYZING->value => __('We are analyzing your repository right now.'),
            AuditRequestStatus::REPORT_READY->value, AuditRequestStatus::SENT->value => __('Your report is ready.'),
            AuditRequestStatus::FAILED->value => __('This audit failed — see the reason below.'),
            AuditRequestStatus::NEEDS_FOLLOWUP->value => __('We need more information — please check your email.'),
            AuditRequestStatus::AWAITING_ACCESS->value => __('Invite :account as a read-only collaborator on your GitHub repository, then run a new audit from the Run an audit page.', ['account' => config('audit.github_account')]),
            AuditRequestStatus::NOT_ANALYZABLE->value => __("We couldn't analyze this repository, so this audit is closed and you weren't charged for it. If it's private, invite :account as a read-only collaborator on GitHub, then run a new audit.", ['account' => config('audit.github_account')]),
            AuditRequestStatus::AWAITING_PAYMENT->value => __('This audit is waiting for an available analysis. Upgrade your plan or buy a run to start it.'),
            AuditRequestStatus::EXPERT_REVIEW->value => __('Your report is complete and is being reviewed by our expert auditor before delivery.'),
            default => '',
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditRequests::route('/'),
            'view' => ViewAuditRequest::route('/{record}'),
        ];
    }
}
