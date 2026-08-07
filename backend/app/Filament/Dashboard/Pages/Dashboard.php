<?php

namespace App\Filament\Dashboard\Pages;

use App\Services\AuditReport\AuditEntitlementService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    /**
     * Filament's built-in Dashboard exposes no header actions, so the panel
     * registers this subclass instead. The action links to the Audit Reports
     * page rather than duplicating its submit form.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('runAudit')
                ->label(__('Run audit'))
                ->icon('heroicon-o-play')
                ->url(fn (): string => AuditReports::getUrl())
                ->visible(function (): bool {
                    $user = auth()->user();

                    return $user !== null
                        && app(AuditEntitlementService::class)->hasAuditAccess($user, Filament::getTenant());
                }),
        ];
    }
}
