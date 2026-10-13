<?php

namespace App\Health\Checks;

use App\Constants\PaymentProviderConstants;
use App\Constants\TransactionStatus;
use App\Models\Transaction;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

class CardknoxPendingTransactionsCheck extends Check
{
    public function run(): Result
    {
        $minutes = (int) config('health.flexpick.cardknox.pending_minutes');

        $stuck = Transaction::query()
            ->where('status', TransactionStatus::PENDING->value)
            ->where('created_at', '<', now()->subMinutes($minutes))
            ->whereHas('paymentProvider', fn ($query) => $query->where('slug', PaymentProviderConstants::CARDKNOX_SLUG))
            ->count();

        $result = Result::make()->meta(['stuck' => $stuck])->shortSummary("{$stuck} stuck");

        if ($stuck > 0) {
            return $result->failed("{$stuck} Cardknox transaction(s) pending for over {$minutes} minutes; reconciliation may be failing.");
        }

        return $result->ok();
    }
}
