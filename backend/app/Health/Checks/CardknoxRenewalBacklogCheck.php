<?php

namespace App\Health\Checks;

use App\Constants\PaymentProviderConstants;
use App\Constants\SubscriptionStatus;
use App\Models\CardknoxCharge;
use App\Models\Subscription;
use Carbon\Carbon;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Cardknox renewals are charged by our scheduler, not the gateway. If the
 * scheduler or Horizon dies, nobody else will notice a renewal never ran.
 */
class CardknoxRenewalBacklogCheck extends Check
{
    public function run(): Result
    {
        $hours = (int) config('health.flexpick.cardknox.renewal_backlog_hours');

        $overdue = Subscription::query()
            ->where('status', SubscriptionStatus::ACTIVE->value)
            ->where('is_canceled_at_end_of_cycle', false)
            ->where('ends_at', '<', now()->subHours($hours))
            ->whereHas('paymentProvider', fn ($query) => $query->where('slug', PaymentProviderConstants::CARDKNOX_SLUG))
            ->get()
            ->reject(fn (Subscription $subscription): bool => CardknoxCharge::query()
                ->where('invoice_base', 'S'.$subscription->id.'-'.Carbon::parse($subscription->ends_at)->format('Ymd'))
                ->exists());

        $result = Result::make()
            ->meta(['overdue' => $overdue->count()])
            ->shortSummary("{$overdue->count()} overdue");

        if ($overdue->isNotEmpty()) {
            return $result->failed("{$overdue->count()} Cardknox subscription(s) are more than {$hours}h past renewal with no charge attempt; is app:charge-cardknox-renewals running?");
        }

        return $result->ok();
    }
}
