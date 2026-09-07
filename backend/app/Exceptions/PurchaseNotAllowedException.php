<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown by CheckoutService when an attributed buyer's target plan or
 * product has no usable partner offering (spec §6, catalog restriction) —
 * the server-side twin of the display filter in PartnerPricingResolver::
 * purchasablePlans()/purchasableProducts(), so a crafted direct request
 * cannot buy what the storefront never showed.
 */
class PurchaseNotAllowedException extends Exception {}
