<?php

namespace App\Support\CashPayments;

use Carbon\Carbon;

/**
 * Shared parsing for cash_payments.sweep_from, the timestamp marking when
 * the cash-payment feature went live (see config/cash_payments.php). Three
 * sites need the exact same reading of it — the two sweep commands
 * (ExpirePendingCashOrders, IssueCashRenewalOrders) and
 * SubscriptionService::cleanupLocalSubscriptionStatuses(), which must leave
 * a pre-floor cash subscription to the old INACTIVE sweep rather than the
 * new PAST_DUE -> CANCELED ladder. One parse per call, not per row.
 */
class SweepFloor
{
    /**
     * A null or empty config value means no floor at all, rather than
     * crashing on Carbon::parse('').
     */
    public static function parse(): ?Carbon
    {
        $configured = (string) config('cash_payments.sweep_from');

        return $configured === '' ? null : Carbon::parse($configured);
    }
}
