<?php

namespace App\Services;

use App\Constants\OrderStatus;
use App\Models\Order;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * What a partner has actually earned from reselling (spec §8).
 *
 * Margin exists only on cash orders that carried a partner price: the
 * customer paid the partner's price, the partner owes the platform the base
 * price, and the difference is theirs once the order is approved. The same
 * per-order rule PartnerOrderResource shows in its "Margin" column, summed
 * over the orders its "Approved" tab lists.
 */
class PartnerMarginService
{
    /**
     * Sum of (amount paid - base price) over approved cash orders, in the
     * currency's minor units. Pending orders have not earned anything yet;
     * rejected and refunded ones never will.
     */
    public function earnedMargin(Tenant $partnerTenant): int
    {
        // Same amount rule as OrderApprovalService::amountDue(): the
        // discounted total when one was applied, the gross total otherwise.
        $paid = 'CASE WHEN orders.total_amount_after_discount > 0 THEN orders.total_amount_after_discount ELSE orders.total_amount END';

        return (int) Order::query()
            ->where('orders.partner_tenant_id', $partnerTenant->id)
            ->where('orders.is_local', true)
            ->where('orders.status', OrderStatus::SUCCESS->value)
            ->whereNotNull('orders.base_price_snapshot')
            ->sum(DB::raw("({$paid}) - orders.base_price_snapshot"));
    }
}
