<?php

namespace App\Filament\Dashboard\Resources\AuditRequests\Pages;

use App\Filament\Dashboard\Resources\AuditRequests\AuditRequestResource;
use App\Models\AuditRequest;
use App\Services\AuditReport\AuditReportService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewAuditRequest extends ViewRecord
{
    protected static string $resource = AuditRequestResource::class;

    protected function getHeaderActions(): array
    {
        /** @var AuditRequest $record */
        $record = $this->getRecord();

        return [
            Action::make('viewOnline')
                ->label(__('View online'))
                ->url(fn (): string => app(AuditReportService::class)->signedUrl($record->report))
                ->openUrlInNewTab()
                ->visible(fn (): bool => $record->report !== null && ! $record->isHeldForExpertReview()),
            Action::make('downloadPdf')
                ->label(__('Download PDF'))
                ->url(fn (): string => route('reports.download', $record->report))
                ->openUrlInNewTab()
                ->visible(fn (): bool => $record->report !== null && ! $record->isHeldForExpertReview()),
        ];
    }
}
