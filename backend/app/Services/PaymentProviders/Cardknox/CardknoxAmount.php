<?php

namespace App\Services\PaymentProviders\Cardknox;

final class CardknoxAmount
{
    /**
     * @param  list<int>  $discountIdsUsed  discounts applied to this charge, counted
     *                                      into discount_uses only when it succeeds
     */
    public function __construct(
        public readonly int $subtotal,
        public readonly int $usage,
        public readonly int $discount,
        public readonly int $credit,
        public readonly int $total,
        public readonly array $discountIdsUsed = [],
    ) {}
}
