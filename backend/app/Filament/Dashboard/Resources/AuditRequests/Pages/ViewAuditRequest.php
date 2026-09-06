<?php

namespace App\Filament\Dashboard\Resources\AuditRequests\Pages;

use App\Filament\Dashboard\Resources\AuditRequests\AuditRequestResource;
use App\Models\AuditRequest;
use App\Services\AuditReport\AuditReportService;
use App\Support\RepoName;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewAuditRequest extends ViewRecord
{
    protected static string $resource = AuditRequestResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var AuditRequest $record */
        $record = $this->getRecord();

        return RepoName::short($record->repo_url) ?: __('Audit');
    }

    public function getSubheading(): string|Htmlable|null
    {
        /** @var AuditRequest $record */
        $record = $this->getRecord();

        return __(':tier · submitted :date', [
            'tier' => $record->tier?->label() ?? __('Audit'),
            'date' => $record->created_at->format(config('app.date_format', 'd/m/Y')),
        ]);
    }

    protected function getHeaderActions(): array
    {
        /** @var AuditRequest $record */
        $record = $this->getRecord();

        return [
            Action::make('viewOnline')
                ->label(__('Open report'))
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
