<?php

namespace App\Filament\Dashboard\Resources\AuditRequests\Pages;

use App\Filament\Dashboard\Pages\AuditReports;
use App\Filament\Dashboard\Resources\AuditRequests\AuditRequestResource;
use App\Services\AuditReport\AuditEntitlementService;
use App\Services\AuditReport\TierQuota;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;

class ListAuditRequests extends ListRecords
{
    protected static string $resource = AuditRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runNewAudit')
                ->label(__('Run new audit'))
                ->url(fn (): string => AuditReports::getUrl())
                ->visible(fn (): bool => collect(
                    app(AuditEntitlementService::class)->quotas(auth()->user(), Filament::getTenant())
                )->contains(fn (TierQuota $quota): bool => $quota->hasRuns() || $quota->purchasable())),
        ];
    }
}
