<?php

namespace App\Console\Commands\CashPayments;

use App\Constants\OrderStatus;
use App\Constants\OrderType;
use App\Constants\PaymentProviderConstants;
use App\Constants\SubscriptionStatus;
use App\Constants\SubscriptionType;
use App\Models\Subscription;
use App\Services\CashPayments\CashSubscriptionService;
use App\Support\CashPayments\SweepFloor;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class IssueCashRenewalOrders extends Command
{
    protected $signature = 'app:issue-cash-renewal-orders';

    protected $description = 'Open a pending order for each cash subscription approaching the end of its cycle';

    public function __construct(
        private CashSubscriptionService $cashSubscriptionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $leadHours = (int) config('cash_payments.renewal_lead_hours');
        $sweepFloor = SweepFloor::parse();

        $subscriptions = Subscription::query()
            ->where('type', SubscriptionType::LOCALLY_MANAGED)
            ->where('status', SubscriptionStatus::ACTIVE->value)
            ->where('price', '>', 0)
            ->where('is_canceled_at_end_of_cycle', false)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now()->addHours($leadHours))
            // A subscription created before the cash-payment feature existed
            // (config('cash_payments.sweep_from')) is left alone: it predates
            // the feature, so it never opted into the renewal-order ladder.
            ->when($sweepFloor !== null, fn (Builder $query) => $query->where('created_at', '>=', $sweepFloor))
            ->whereHas('paymentProvider', fn (Builder $query) => $query->where('slug', PaymentProviderConstants::OFFLINE_SLUG))
            // One open renewal order at a time. Once approved, ends_at moves
            // beyond the lead window, so the next cycle is picked up naturally.
            ->whereDoesntHave('orders', fn (Builder $query) => $query
                ->where('type', OrderType::RENEWAL->value)
                ->where('status', OrderStatus::PENDING->value))
            ->get();

        foreach ($subscriptions as $subscription) {
            $this->cashSubscriptionService->createPendingOrder($subscription, OrderType::RENEWAL);
        }

        $this->info("Opened {$subscriptions->count()} cash renewal order(s).");

        return self::SUCCESS;
    }
}
