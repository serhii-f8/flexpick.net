<?php

namespace App\Constants;

class PaymentProviderConstants
{
    public const STRIPE_SLUG = 'stripe';

    public const PADDLE_SLUG = 'paddle';

    public const LEMON_SQUEEZY_SLUG = 'lemon-squeezy';

    public const CREEM_SLUG = 'creem';

    public const POLAR_SLUG = 'polar';

    public const CARDKNOX_SLUG = 'cardknox';

    public const OFFLINE_SLUG = 'offline';

    /**
     * Providers that charge an amount the app computes, so they can collect a
     * partner's own price. The other gateways bill their own price objects,
     * which only ever carry the base price.
     */
    public const PARTNER_PRICE_SLUGS = [self::OFFLINE_SLUG, self::CARDKNOX_SLUG];
}
