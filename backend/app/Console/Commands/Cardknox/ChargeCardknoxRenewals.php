<?php

namespace App\Console\Commands\Cardknox;

use App\Constants\PaymentProviderConstants;
use App\Constants\SubscriptionStatus;
use App\Constants\SubscriptionType;
use App\Models\Subscription;
use App\Services\PaymentProviders\Cardknox\CardknoxBillingService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class ChargeCardknoxRenewals extends Command
{
    protected $signature = 'app:charge-cardknox-renewals {--subscription= : Only this subscription UUID}';

    protected $description = 'Charge Cardknox subscriptions whose billing period has ended, and retry past-due ones';

    public function handle(CardknoxBillingService $billing): int
    {
        $query = Subscription::query()
            ->where('type', SubscriptionType::PAYMENT_PROVIDER_MANAGED)
            ->whereHas('paymentProvider', fn (Builder $q) => $q->where('slug', PaymentProviderConstants::CARDKNOX_SLUG))
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $active) => $active->where('status', SubscriptionStatus::ACTIVE->value)->where('ends_at', '<=', now()))
                // Retry timing lives in JSON; CardknoxBillingService::isDue() applies it.
                ->orWhere('status', SubscriptionStatus::PAST_DUE->value));

        if ($uuid = $this->option('subscription')) {
            $query->where('uuid', $uuid);
        }

        $charged = 0;

        foreach ($query->lazyById() as $subscription) {
            try {
                if ($billing->chargeRenewal($subscription) !== null) {
                    $charged++;
                }
            } catch (Throwable $e) {
                // One broken subscription must not stop everyone else's renewal.
                report($e);
            }
        }

        $this->info("Processed {$charged} Cardknox renewal attempt(s).");

        return self::SUCCESS;
    }
}
