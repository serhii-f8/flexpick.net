<?php

namespace App\Filament\Admin\Resources\AuditRequests\Pages;

use App\Exceptions\AiAnalysisException;
use App\Filament\Admin\Resources\AuditRequests\AuditRequestResource;
use App\Models\AuditRequest;
use App\Services\AuditReport\AuditReportService;
use App\Services\AuditReport\ReportPayload;
use App\Services\AuditRequestService;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewAuditRequest extends ViewRecord
{
    protected static string $resource = AuditRequestResource::class;

    protected function getHeaderActions(): array
    {
        /** @var AuditRequest $record */
        $record = $this->getRecord();

        return [
            EditAction::make(),
            DeleteAction::make()
                ->using(fn (AuditRequest $record) => app(AuditRequestService::class)->delete($record))
                ->successRedirectUrl(AuditRequestResource::getUrl('index')),
            Action::make('viewOnline')
                ->label(__('View report'))
                ->icon('heroicon-m-arrow-top-right-on-square')
                ->url(fn (): string => app(AuditReportService::class)->signedUrl($record->report))
                ->openUrlInNewTab()
                ->visible(fn (): bool => $record->report !== null && ! $record->isHeldForExpertReview()),
            Action::make('downloadPdf')
                ->label(__('Download PDF'))
                ->icon('heroicon-m-arrow-down-tray')
                ->url(fn (): string => route('reports.download', $record->report))
                ->openUrlInNewTab()
                // reports.download 404s with no file behind the row, and the
                // PDF is written after the payload, so report !== null is not
                // enough on its own.
                ->visible(fn (): bool => $record->report?->pdf_path !== null && ! $record->isHeldForExpertReview()),
            Action::make('editResults')
                ->label(__('Edit results'))
                ->visible(fn (): bool => $this->getRecord()->report !== null)
                ->schema([
                    Textarea::make('payload')
                        ->label(__('Report payload (JSON)'))
                        ->helperText(__('The hosted web report reads this live. The PDF stays unchanged until the audit is re-run.'))
                        ->rows(20)
                        ->default(fn (): string => json_encode($this->getRecord()->report->payload, JSON_PRETTY_PRINT))
                        ->required()
                        ->rules([
                            function () {
                                return function (string $attribute, $value, Closure $fail) {
                                    $decoded = json_decode((string) $value, true);

                                    if (! is_array($decoded)) {
                                        $fail(__('The payload must be valid JSON.'));

                                        return;
                                    }

                                    try {
                                        ReportPayload::validate($decoded, $this->getRecord()->report->payload_schema_version);
                                    } catch (AiAnalysisException $e) {
                                        $fail($e->getMessage());
                                    }
                                };
                            },
                        ]),
                ])
                ->action(function (array $data): void {
                    $this->getRecord()->report->update(['payload' => json_decode($data['payload'], true)]);

                    Notification::make()
                        ->title(__('Report payload updated'))
                        ->success()
                        ->send();
                }),
        ];
    }
}
