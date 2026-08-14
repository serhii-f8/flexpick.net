<?php

namespace App\Filament\Admin\Resources\AuditRequests;

use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Filament\Admin\Resources\AuditRequests\Pages\EditAuditRequest;
use App\Filament\Admin\Resources\AuditRequests\Pages\ListAuditRequests;
use App\Filament\Admin\Resources\AuditRequests\Pages\ViewAuditRequest;
use App\Jobs\GenerateAuditReport;
use App\Mapper\AuditRequestStatusMapper;
use App\Models\AuditEmailLog;
use App\Models\AuditRequest;
use App\Services\AuditReport\AuditEntitlementService;
use App\Services\AuditReport\AuditReportService;
use App\Services\AuditReport\PromptComposer;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AuditRequestResource extends Resource
{
    protected static ?string $model = AuditRequest::class;

    public static function getNavigationGroup(): ?string
    {
        return __('Audits');
    }

    public static function getModelLabel(): string
    {
        return __('Audit Request');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return true;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('emailLogs');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Audit request'))->schema([
                Select::make('status')
                    ->options(
                        collect(AuditRequestStatus::cases())
                            ->mapWithKeys(fn (AuditRequestStatus $status) => [$status->value => app(AuditRequestStatusMapper::class)->mapForDisplay($status->value)])
                            ->all()
                    )
                    ->required(),
                Select::make('tier')
                    ->label(__('Audit type'))
                    ->options(
                        collect(AuditTier::cases())
                            ->mapWithKeys(fn (AuditTier $tier): array => [$tier->value => $tier->label()])
                            ->all()
                    )
                    ->helperText(__('Changing this and re-running the pipeline re-analyses at the new tier.'))
                    ->required(),
                TextInput::make('repo_url')->url()->maxLength(2048),
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('email')->email()->required()->maxLength(255),
                Textarea::make('message')->rows(4),
                Textarea::make('admin_context')
                    ->label(__('Additional analysis context'))
                    ->helperText(__('Appended to the AI prompt on the next run of this audit.'))
                    ->rows(4),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Request'))->schema([
                TextEntry::make('name'),
                TextEntry::make('email'),
                TextEntry::make('repo_url')->label(__('Repository')),
                TextEntry::make('message')->placeholder('—'),
                TextEntry::make('status')
                    ->badge()
                    ->color(fn (AuditRequest $record, AuditRequestStatusMapper $mapper): string => $mapper->mapColor($record->status))
                    ->formatStateUsing(fn (string $state, AuditRequestStatusMapper $mapper): string => $mapper->mapForDisplay($state)),
                TextEntry::make('tier'),
                TextEntry::make('source'),
                TextEntry::make('marketing_consent'),
                TextEntry::make('email_verified_at')->dateTime(config('app.datetime_format'))->placeholder('—'),
                TextEntry::make('tenants')
                    ->label(__('Company / workspaces'))
                    ->state(fn (AuditRequest $record): string => $record->user?->tenants()->pluck('name')->implode(', ') ?: '—'),
                TextEntry::make('admin_context')
                    ->label(__('Additional analysis context'))
                    ->placeholder('—'),
            ]),

            Section::make(__('Timeline'))->schema([
                TextEntry::make('failure_reason')
                    ->label(__('Failure reason'))
                    ->color('danger')
                    ->visible(fn (AuditRequest $record): bool => $record->failure_reason !== null),
                TextEntry::make('analysis_started_at')->dateTime(config('app.datetime_format'))->placeholder('—'),
                TextEntry::make('analysis_completed_at')->dateTime(config('app.datetime_format'))->placeholder('—'),
                ViewEntry::make('pipeline_log')
                    ->label('')
                    ->view('filament.admin.audit.pipeline-timeline')
                    ->columnSpanFull(),
            ]),

            Section::make(__('Results'))
                ->visible(fn (AuditRequest $record): bool => $record->report !== null)
                ->schema([
                    TextEntry::make('report.uuid')->label(__('Report')),
                    TextEntry::make('overall_score')
                        ->label(__('Overall score'))
                        ->state(fn (AuditRequest $record): string => (string) data_get($record->report?->payload, 'scores.overall', '—')),
                ]),

            Section::make(__('Emails'))
                ->visible(fn (AuditRequest $record): bool => $record->emailLogs->isNotEmpty())
                ->schema([
                    RepeatableEntry::make('emailLogs')
                        ->label('')
                        ->schema([
                            TextEntry::make('recipient'),
                            TextEntry::make('mailable')->label(__('Notification'))->badge()->color('gray'),
                            TextEntry::make('status')
                                ->badge()
                                ->color(fn (string $state): string => match ($state) {
                                    AuditEmailLog::STATUS_DELIVERED => 'success',
                                    AuditEmailLog::STATUS_SENT => 'info',
                                    AuditEmailLog::STATUS_PENDING => 'gray',
                                    default => 'danger',
                                }),
                            TextEntry::make('attempts'),
                            TextEntry::make('sent_at')->label(__('Last attempt'))->dateTime(config('app.datetime_format'))->placeholder('—'),
                        ])
                        ->columns(5),
                ]),

            Section::make(__('Next-run prompt preview'))->collapsed()->schema([
                TextEntry::make('prompt_preview')
                    ->label('')
                    ->state(fn (AuditRequest $record): string => app(PromptComposer::class)->preview($record))
                    ->markdown(false)
                    ->extraAttributes(['style' => 'white-space: pre-wrap; font-family: monospace;']),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->dateTime(config('app.datetime_format'))->sortable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('repo_url')->limit(40)->searchable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (AuditRequest $record, AuditRequestStatusMapper $mapper): string => $mapper->mapColor($record->status))
                    ->formatStateUsing(fn (string $state, AuditRequestStatusMapper $mapper) => $mapper->mapForDisplay($state)),
                TextColumn::make('email_verified_at')->dateTime(config('app.datetime_format'))->label(__('Verified'))->placeholder(__('No')),
                IconColumn::make('marketing_consent')->boolean()->label(__('Consent')),
                IconColumn::make('free_run')->boolean()->label(__('Free run')),
                TextColumn::make('source'),
                TextColumn::make('age')
                    ->label(__('Age'))
                    ->state(fn (AuditRequest $record) => $record->created_at)
                    ->since()
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('created_at', $direction)),
                TextColumn::make('email_logs_count')
                    ->label(__('Emails'))
                    ->counts('emailLogs')
                    ->badge()
                    ->color(fn (AuditRequest $record): string => $record->emailLogs->contains(
                        fn ($log): bool => in_array($log->status, [AuditEmailLog::STATUS_FAILED, AuditEmailLog::STATUS_BOUNCED], true),
                    ) ? 'danger' : 'gray'),
            ])
            ->recordClasses(fn (AuditRequest $record): ?string => match (true) {
                $record->status === AuditRequestStatus::FAILED->value => 'bg-danger-50 dark:bg-danger-500/10',
                default => null,
            })
            ->defaultSort('created_at', 'desc')
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
            ->recordActions([
                EditAction::make(),
                ViewAction::make(),
                Action::make('retry')
                    ->label(__('Retry pipeline'))
                    ->requiresConfirmation()
                    ->visible(fn (AuditRequest $record): bool => $record->repo_url !== null && in_array($record->status, [
                        AuditRequestStatus::FAILED->value,
                        AuditRequestStatus::NEEDS_FOLLOWUP->value,
                        AuditRequestStatus::REPORT_READY->value,
                        AuditRequestStatus::EXPERT_REVIEW->value,
                    ], true))
                    ->action(function (AuditRequest $record): void {
                        $record->update(['status' => AuditRequestStatus::QUEUED->value, 'failure_reason' => null]);
                        GenerateAuditReport::dispatch($record);
                    }),
                Action::make('launch')
                    ->label(__('Launch report'))
                    ->requiresConfirmation()
                    ->visible(fn (AuditRequest $record): bool => $record->repo_url !== null && in_array($record->status, [
                        AuditRequestStatus::AWAITING_ACCESS->value,
                        AuditRequestStatus::AWAITING_PAYMENT->value,
                    ], true))
                    ->action(function (AuditRequest $record): void {
                        $entitlements = app(AuditEntitlementService::class);
                        if ($entitlements->hasFreeRun($record->email)) {
                            $entitlements->consumeFreeRun($record);
                        }

                        $record->update(['status' => AuditRequestStatus::QUEUED->value, 'failure_reason' => null]);
                        GenerateAuditReport::dispatch($record);
                    }),
                Action::make('grantUnlock')
                    ->label(__('Grant full unlock'))
                    ->requiresConfirmation()
                    ->visible(fn (AuditRequest $record): bool => $record->report()->first()?->unlocked_at === null && $record->report()->exists())
                    ->action(fn (AuditRequest $record) => app(AuditReportService::class)->unlock($record->report()->first())),
                Action::make('markHandled')
                    ->label(__('Mark handled'))
                    ->visible(fn (AuditRequest $record): bool => $record->status === AuditRequestStatus::NEEDS_FOLLOWUP->value)
                    ->action(fn (AuditRequest $record) => $record->update(['status' => AuditRequestStatus::HANDLED->value])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditRequests::route('/'),
            'view' => ViewAuditRequest::route('/{record}'),
            'edit' => EditAuditRequest::route('/{record}/edit'),
        ];
    }
}
