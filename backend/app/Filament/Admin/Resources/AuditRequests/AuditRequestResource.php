<?php

namespace App\Filament\Admin\Resources\AuditRequests;

use App\Constants\AuditRequestStatus;
use App\Filament\Admin\Resources\AuditRequests\Pages\ListAuditRequests;
use App\Filament\Admin\Resources\AuditRequests\Pages\ViewAuditRequest;
use App\Jobs\GenerateAuditReport;
use App\Mapper\AuditRequestStatusMapper;
use App\Models\AuditRequest;
use App\Services\AuditReport\AuditEntitlementService;
use App\Services\AuditReport\AuditReportService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

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
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Request'))->schema([
                TextEntry::make('name'),
                TextEntry::make('email'),
                TextEntry::make('repo_url'),
                TextEntry::make('message'),
                TextEntry::make('status'),
                TextEntry::make('failure_reason'),
                TextEntry::make('email_verified_at')->dateTime(config('app.datetime_format')),
                TextEntry::make('marketing_consent'),
                TextEntry::make('source'),
                TextEntry::make('report.uuid')->label(__('Report')),
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
                TextColumn::make('repo_url')->limit(40),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (AuditRequest $record, AuditRequestStatusMapper $mapper): string => $mapper->mapColor($record->status))
                    ->formatStateUsing(fn (string $state, AuditRequestStatusMapper $mapper) => $mapper->mapForDisplay($state)),
                TextColumn::make('email_verified_at')->dateTime(config('app.datetime_format'))->label(__('Verified'))->placeholder(__('No')),
                IconColumn::make('marketing_consent')->boolean()->label(__('Consent')),
                IconColumn::make('free_run')->boolean()->label(__('Free run')),
                TextColumn::make('source'),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make(),
                Action::make('retry')
                    ->label(__('Retry pipeline'))
                    ->requiresConfirmation()
                    ->visible(fn (AuditRequest $record): bool => $record->repo_url !== null && in_array($record->status, [
                        AuditRequestStatus::FAILED->value,
                        AuditRequestStatus::NEEDS_FOLLOWUP->value,
                        AuditRequestStatus::REPORT_READY->value,
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
        ];
    }
}
