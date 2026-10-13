<?php

namespace App\Health\Checks;

use App\Constants\PaymentProviderConstants;
use App\Constants\TransactionStatus;
use App\Health\FailureRate;
use App\Models\Transaction;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

class CardknoxDeclineRateCheck extends Check
{
    public function run(): Result
    {
        $config = (array) config('health.flexpick.cardknox.decline');
        $since = now()->subHours((int) $config['window_hours']);

        $settled = Transaction::query()
            ->where('created_at', '>=', $since)
            ->whereIn('status', [TransactionStatus::SUCCESS->value, TransactionStatus::FAILED->value])
            ->whereHas('paymentProvider', fn ($query) => $query->where('slug', PaymentProviderConstants::CARDKNOX_SLUG));

        $total = (clone $settled)->count();
        $failed = (clone $settled)->where('status', TransactionStatus::FAILED->value)->count();

        $rate = new FailureRate($total, $failed, (int) $config['min_samples']);

        $result = Result::make()
            ->meta(['total' => $rate->total, 'failed' => $rate->failed, 'percent' => $rate->percent()])
            ->shortSummary("{$rate->percent()}% of {$rate->total}");

        if (! $rate->belowFloor() && $rate->percent() > (int) $config['fail_percent']) {
            return $result->failed("Cardknox decline rate is {$rate->percent()}% over the last {$config['window_hours']}h ({$rate->failed}/{$rate->total}).");
        }

        return $result->ok();
    }
}
