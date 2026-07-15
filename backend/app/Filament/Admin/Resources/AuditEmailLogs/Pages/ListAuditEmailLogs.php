<?php

namespace App\Filament\Admin\Resources\AuditEmailLogs\Pages;

use App\Filament\Admin\Resources\AuditEmailLogs\AuditEmailLogResource;
use App\Models\AuditEmailLog;
use App\Services\AuditMail\MailcoachClient;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListAuditEmailLogs extends ListRecords
{
    protected static string $resource = AuditEmailLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshStatuses')
                ->label(__('Refresh statuses'))
                ->visible(fn (): bool => app(MailcoachClient::class)->isConfigured())
                ->action(function (): void {
                    $items = collect(app(MailcoachClient::class)->recentTransactionalMails())->keyBy('uuid');

                    AuditEmailLog::query()
                        ->whereNotNull('mailcoach_uuid')
                        ->whereIn('mailcoach_uuid', $items->keys())
                        ->get()
                        ->each(function (AuditEmailLog $log) use ($items): void {
                            $item = $items[$log->mailcoach_uuid];

                            $status = match (true) {
                                ! empty($item['bounced_at']) => AuditEmailLog::STATUS_BOUNCED,
                                ! empty($item['delivered_at']) => AuditEmailLog::STATUS_DELIVERED,
                                default => $log->status,
                            };

                            $log->update(['status' => $status]);
                        });

                    Notification::make()->title(__('Statuses refreshed'))->success()->send();
                }),
        ];
    }
}
