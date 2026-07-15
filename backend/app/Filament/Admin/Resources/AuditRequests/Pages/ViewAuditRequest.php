<?php

namespace App\Filament\Admin\Resources\AuditRequests\Pages;

use App\Exceptions\AiAnalysisException;
use App\Filament\Admin\Resources\AuditRequests\AuditRequestResource;
use App\Services\AuditReport\ReportPayload;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewAuditRequest extends ViewRecord
{
    protected static string $resource = AuditRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
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
                                        ReportPayload::validate($decoded);
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
