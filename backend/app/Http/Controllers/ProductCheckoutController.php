<?php

namespace App\Http\Controllers;

use App\Dto\CartItemDto;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\DiscountService;
use App\Services\OneTimeProductService;
use App\Services\PartnerPricingResolver;
use App\Services\PurchaseLandingService;
use App\Services\SessionService;
use App\Services\TenantCreationService;
use Illuminate\Http\Request;

class ProductCheckoutController extends Controller
{
    public function __construct(
        private DiscountService $discountService,
        private OneTimeProductService $productService,
        private SessionService $sessionService,
        private PartnerPricingResolver $partnerPricingResolver,
        private TenantCreationService $tenantCreationService,
        private PurchaseLandingService $purchaseLanding,
    ) {}

    public function productCheckout()
    {
        $cartDto = $this->sessionService->getCartDto();

        if (empty($cartDto->items)) {
            return redirect()->route('home');
        }

        return view('checkout.product');
    }

    public function addToCart(Request $request, string $productSlug, int $quantity = 1)
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

        // A dashboard tier purchase (AuditReports::purchase(),
        // AuditRequestController::purchaseRun()) writes its checkout intent on
        // one workspace and names it here with `?tenant=`. Without that pin,
        // ProductTenantPicker defaults the order to the user's FIRST orderable
        // workspace, and for a member of several HandleAuditTierOrder would
        // run -- or credit -- the wrong one. The uuid comes off the query
        // string, so it is only honoured when this user may order for that
        // workspace; anything else falls through to the picker's default.
        $pinnedTenant = $this->pinnedTenant($request);

        if ($pinnedTenant !== null) {
            $cartDto->tenantUuid = $pinnedTenant->uuid;
            $cartDto->shouldCreateNewTenant = false;
        }

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

    private function pinnedTenant(Request $request): ?Tenant
    {
        $user = auth()->user();
        $uuid = $request->query('tenant');

        if ($user === null || ! is_string($uuid) || $uuid === '') {
            return null;
        }

        return $this->tenantCreationService->findUserTenantForNewOrderByUuid($user, $uuid);
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

        $order = Order::find($cartDto->orderId);

        return $this->purchaseLanding->redirect(
            auth()->user(),
            $order?->tenant,
            __('Your order is being processed and you will receive an email with your order details shortly.'),
        );
    }
}
