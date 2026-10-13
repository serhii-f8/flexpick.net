<?php

namespace App\Filament\Admin\Resources\Transactions\Pages;

use App\Constants\TransactionStatus;
use App\Filament\Admin\Resources\Transactions\TransactionResource;
use App\Models\Transaction;
use App\Services\PaymentProviders\Cardknox\CardknoxBillingService;
use App\Services\PaymentProviders\Cardknox\CardknoxChargeLedger;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewTranscription extends ViewRecord
{
    protected static string $resource = TransactionResource::class;

    protected function getHeaderActions(): array
    {
        // Cardknox has no dashboard of subscription objects to act on, so
        // refunds and disputes are recorded from here.
        $isCardknox = fn (Transaction $record): bool => $record->payment_provider_id === app(CardknoxChargeLedger::class)->provider()->id;

        return [
            Action::make('cardknox_refund')
                ->label(__('Refund'))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (Transaction $record): bool => $isCardknox($record) && $record->status === TransactionStatus::SUCCESS->value)
                ->action(function (Transaction $record, CardknoxBillingService $billing): void {
                    $result = $billing->refund($record);

                    $result->isApproved()
                        ? Notification::make()->title(__('Refunded'))->success()->send()
                        : Notification::make()->title(__('Refund failed'))->body($result->error)->danger()->send();
                }),
            Action::make('cardknox_mark_disputed')
                ->label(__('Mark disputed'))
                ->icon('heroicon-o-exclamation-triangle')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (Transaction $record): bool => $isCardknox($record) && $record->status === TransactionStatus::SUCCESS->value)
                ->action(function (Transaction $record, CardknoxChargeLedger $ledger): void {
                    $ledger->markDisputed($record);
                    Notification::make()->title(__('Marked as disputed'))->success()->send();
                }),
        ];
    }
}
