<?php

namespace App\Filament\Dashboard\Resources\AuditRequests\Pages;

use App\Filament\Dashboard\Pages\AuditReports;
use App\Filament\Dashboard\Resources\AuditRequests\AuditRequestResource;
use App\Services\AuditReport\AuditEntitlementService;
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
                ->visible(function (): bool {
                    $tenant = Filament::getTenant();

                    return $tenant !== null
                        && app(AuditEntitlementService::class)->remainingDashboardRuns(auth()->user(), $tenant) > 0;
                }),
        ];
    }
}
