<?php

namespace App\Filament\Dashboard\Resources\AuditRequests\Pages;

use App\Filament\Dashboard\Resources\AuditRequests\AuditRequestResource;
use App\Services\AuditReport\AuditReportService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewAuditRequest extends ViewRecord
{
    protected static string $resource = AuditRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewOnline')
                ->label(__('View online'))
                ->url(fn (): string => app(AuditReportService::class)->signedUrl($this->getRecord()->report))
                ->openUrlInNewTab()
                ->visible(fn (): bool => $this->getRecord()->report !== null),
            Action::make('downloadPdf')
                ->label(__('Download PDF'))
                ->url(fn (): string => route('reports.download', $this->getRecord()->report))
                ->openUrlInNewTab()
                ->visible(fn (): bool => $this->getRecord()->report !== null),
        ];
    }
}
