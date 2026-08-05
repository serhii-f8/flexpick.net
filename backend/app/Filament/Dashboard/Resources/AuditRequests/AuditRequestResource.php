<?php

namespace App\Filament\Dashboard\Resources\AuditRequests;

use App\Constants\AuditRequestStatus;
use App\Filament\Dashboard\Resources\AuditRequests\Pages\ListAuditRequests;
use App\Filament\Dashboard\Resources\AuditRequests\Pages\ViewAuditRequest;
use App\Mapper\AuditRequestStatusMapper;
use App\Models\AuditRequest;
use App\Services\AuditReport\AuditEntitlementService;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
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

    protected static bool $isScopedToTenant = false;

    public static function getModelLabel(): string
    {
        return __('Audit');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Audits');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            // @phpstan-ignore-next-line method.notFound (forUser is AuditRequest's own scope; Larastan can't see it through the parent's generic Builder<Model> return type)
            ->forUser(auth()->user())
            ->with('report');
    }

    public static function shouldRegisterNavigation(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        return app(AuditEntitlementService::class)->hasAuditAccess($user, Filament::getTenant());
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
                    ->limit(50)
                    ->placeholder(__('No repository'))
                    ->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (AuditRequest $record, AuditRequestStatusMapper $mapper): string => $mapper->mapColor($record->status))
                    ->formatStateUsing(fn (string $state, AuditRequestStatusMapper $mapper): string => $mapper->mapForDisplay($state)),
                TextColumn::make('score')
                    ->label(__('Score'))
                    ->state(fn (AuditRequest $record): string => (string) data_get($record->report?->payload, 'scores.overall', '—')),
                TextColumn::make('source')
                    ->label(__('Source')),
                TextColumn::make('created_at')
                    ->label(__('Submitted'))
                    ->dateTime(config('app.datetime_format'))
                    ->sortable(),
                TextColumn::make('report.created_at')
                    ->label(__('Completed'))
                    ->dateTime(config('app.datetime_format'))
                    ->placeholder('—'),
            ])
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
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Project'))->schema([
                TextEntry::make('repo_url')
                    ->label(__('Repository'))
                    ->url(fn (AuditRequest $record): ?string => $record->repo_url, shouldOpenInNewTab: true)
                    ->placeholder(__('No repository')),
                TextEntry::make('name')->label(__('Submitted by')),
                TextEntry::make('email'),
                TextEntry::make('source'),
                TextEntry::make('message')->placeholder('—'),
            ]),
            Section::make(__('Status & timeline'))->schema([
                TextEntry::make('status')
                    ->badge()
                    ->color(fn (AuditRequest $record, AuditRequestStatusMapper $mapper): string => $mapper->mapColor($record->status))
                    ->formatStateUsing(fn (string $state, AuditRequestStatusMapper $mapper): string => $mapper->mapForDisplay($state)),
                TextEntry::make('status_description')
                    ->label('')
                    ->state(fn (AuditRequest $record): string => static::statusDescription($record)),
                TextEntry::make('failure_reason')
                    ->label(__('Failure reason'))
                    ->color('danger')
                    ->visible(fn (AuditRequest $record): bool => $record->failure_reason !== null),
                TextEntry::make('created_at')->label(__('Submitted'))->dateTime(config('app.datetime_format')),
                TextEntry::make('email_verified_at')->label(__('Email verified'))->dateTime(config('app.datetime_format'))->placeholder('—'),
                TextEntry::make('report.created_at')->label(__('Completed'))->dateTime(config('app.datetime_format'))->placeholder('—'),
            ]),
            Section::make(__('Results'))
                ->visible(fn (AuditRequest $record): bool => $record->report !== null)
                ->schema([
                    TextEntry::make('overall_score')
                        ->label(__('Overall score'))
                        ->state(fn (AuditRequest $record): string => (string) data_get($record->report?->payload, 'scores.overall', '—')),
                    TextEntry::make('category_scores')
                        ->label(__('Category scores'))
                        ->state(function (AuditRequest $record): string {
                            $scores = collect(data_get($record->report?->payload, 'scores', []))
                                ->except('overall')
                                ->map(fn ($value, $key) => __(ucfirst(str_replace('_', ' ', $key))).': '.$value);

                            return $scores->isEmpty() ? '—' : $scores->implode(' · ');
                        }),
                    TextEntry::make('risks_summary')
                        ->label(__('Risks'))
                        ->state(function (AuditRequest $record): string {
                            $risks = collect(data_get($record->report?->payload, 'risks', []));

                            if ($risks->isEmpty()) {
                                return __('None found');
                            }

                            return $risks->countBy('impact')
                                ->map(fn (int $count, string $impact) => $count.' '.__($impact))
                                ->implode(' · ');
                        }),
                ]),
        ]);
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
            AuditRequestStatus::AWAITING_ACCESS->value => __('Invite flexpick-audit as a read-only collaborator on your GitHub repository. We launch the audit as soon as the invite lands.'),
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
