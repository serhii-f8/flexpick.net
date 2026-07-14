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
        return $table
            ->heading(__('Recent audits'))
            ->query(
                AuditRequest::forUser(auth()->user())
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
                TextColumn::make('created_at')
                    ->label(__('Submitted'))
                    ->dateTime(config('app.datetime_format')),
            ])
            ->recordUrl(fn (AuditRequest $record): string => AuditRequestResource::getUrl('view', ['record' => $record]));
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
