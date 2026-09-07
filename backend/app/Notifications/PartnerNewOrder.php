<?php

namespace App\Notifications;

use App\Filament\Dashboard\Resources\PartnerOrders\PartnerOrderResource;
use App\Models\Order;
use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * The bell counterpart of the PartnerNewPendingOrder mail (spec §8.3).
 */
class PartnerNewOrder extends Notification
{
    use Queueable;

    public function __construct(
        public Order $order,
        public Tenant $partnerTenant,
        public int $amountDue,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $currency = $this->order->currency?->code ?? config('app.default_currency');

        return FilamentNotification::make()
            ->title(__('New order from :customer', ['customer' => $this->order->user?->email ?? __('a customer')]))
            ->body(__(':amount, :method. Approve it once you have the cash.', [
                'amount' => money($this->amountDue, $currency),
                'method' => $this->order->is_local ? __('cash') : __('card'),
            ]))
            ->icon('heroicon-o-banknotes')
            ->actions([
                Action::make('view')
                    ->label(__('View orders'))
                    ->url(PartnerOrderResource::getUrl('index', ['activeTab' => 'pending'], panel: 'dashboard', tenant: $this->partnerTenant)),
            ])
            ->getDatabaseMessage();
    }
}
