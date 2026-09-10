<?php

namespace App\Http\Controllers;

use App\Dto\CartItemDto;
use App\Services\DiscountService;
use App\Services\OneTimeProductService;
use App\Services\PartnerPricingResolver;
use App\Services\SessionService;

class ProductCheckoutController extends Controller
{
    public function __construct(
        private DiscountService $discountService,
        private OneTimeProductService $productService,
        private SessionService $sessionService,
        private PartnerPricingResolver $partnerPricingResolver,
    ) {}

    public function productCheckout()
    {
        $cartDto = $this->sessionService->getCartDto();

        if (empty($cartDto->items)) {
            return redirect()->route('home');
        }

        return view('checkout.product');
    }

    public function addToCart(string $productSlug, int $quantity = 1)
    {
        $product = $this->productService->getProductWithPriceBySlug($productSlug);

        if ($product === null) {
            abort(404);
        }

        if (! $product->is_active) {
            abort(404);
        }

        // Every route into this action -- the pricing page, the audit unlock
        // funnel, and the audit tier-upgrade funnel -- shares this one entry
        // point, so this is the only place a purchasability check covers all
        // of them. Checked before any cart state is touched: an attributed
        // buyer whose partner hasn't enabled this product must never reach a
        // rendered checkout page (and never mutate the cart) only to be
        // rejected on final submit by CheckoutService's guard.
        //
        // Not-visible products are exempt, mirroring
        // CheckoutService::assertProductPurchasable(): no partner offering
        // can ever exist for one (PartnerProductPricingTable lists only
        // is_visible products), and AuditReportController::unlock() sends
        // every buyer here for the not-visible audit-report-unlock SKU.
        if ($product->is_visible && $this->partnerPricingResolver->purchasableProducts(collect([$product]), auth()->user())->isEmpty()) {
            return redirect()->back()->with('error', __('This product is not currently available for your account.'));
        }

        $cartDto = $this->sessionService->clearCartDto();  // use getCartDto() instead of clearCartDto() when allowing full cart checkout with multiple items

        if ($quantity < 1) {
            $quantity = 1;
        }

        if ($product->max_quantity != 0 && $quantity > $product->max_quantity) {
            $quantity = $product->max_quantity;
        }

        // if product is already in cart, increase quantity
        foreach ($cartDto->items as $item) {
            if ($item->productId == $product->id) {
                $item->quantity += $quantity;
                $item->quantity = min($item->quantity, $product->max_quantity);
                $this->sessionService->saveCartDto($cartDto);

                return redirect()->route('checkout.product');
            }
        }

        $cartItem = new CartItemDto;
        $cartItem->productId = $product->id;
        $cartItem->quantity = $quantity;

        $cartDto->items[] = $cartItem;

        $this->sessionService->saveCartDto($cartDto);

        return redirect()->route('checkout.product');
    }

    public function productCheckoutSuccess()
    {
        $cartDto = $this->sessionService->getCartDto();

        if ($cartDto->orderId === null) {
            return redirect()->route('home');
        }

        if ($cartDto->discountCode !== null) {
            $this->discountService->redeemCodeForOrder($cartDto->discountCode, auth()->user(), $cartDto->orderId);
        }

        $this->sessionService->clearCartDto();

        return view('checkout.product-thank-you');
    }
}
