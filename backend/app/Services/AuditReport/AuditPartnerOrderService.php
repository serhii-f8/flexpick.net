<?php

namespace App\Services\AuditReport;

use App\Constants\PaymentProviderConstants;
use App\Listeners\Order\HandleAuditTierOrder;
use App\Models\AuditRequest;
use App\Models\OneTimeProduct;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\TenantParameter;
use App\Services\CashPayments\PurchaseSnapshotService;
use App\Services\CurrencyService;
use App\Services\OrderService;
use App\Services\PartnerPricingResolver;
use Illuminate\Support\Facades\Cache;

/**
 * A referred customer's audit that has to be paid for goes straight to the
 * partner who invited them: a pending cash order at the partner's price, on
 * the customer's workspace, exactly as if the customer had checked out with
 * the Offline provider themselves. OrderService fires OrderedOffline, which
 * emails the partner and puts it in their Partner Orders; their approval
 * completes the order and HandleAuditTierOrder runs this request.
 */
class AuditPartnerOrderService
{
    public const META_KEY = 'partner_order_id';

    public function __construct(
        private PartnerPricingResolver $partnerPricing,
        private OrderService $orderService,
        private PurchaseSnapshotService $snapshots,
        private CurrencyService $currencies,
    ) {}

    /**
     * The order now awaiting the partner's approval, or null when there is no
     * partner who can sell this tier to this customer (the customer then pays
     * the usual way). Safe to call again: a request gets one order.
     */
    public function openFor(AuditRequest $auditRequest): ?Order
    {
        $user = $auditRequest->user;
        $tenant = $auditRequest->tenant;

        if ($user === null || $tenant === null || $this->partnerPricing->resolvePartnerTenant($user) === null) {
            return null;
        }

        $product = OneTimeProduct::query()->where('slug', $auditRequest->tier->productSlug())->first();
        $offering = $product === null ? null : $this->partnerPricing->usableProductOffering($user, $product);
        $offline = PaymentProvider::query()->where('slug', PaymentProviderConstants::OFFLINE_SLUG)->first();

        if ($product === null || $offering === null || $offline === null) {
            return null;
        }

        // A lock, not a transaction: OrderService::create() fires OrderedOffline to a
        // queued listener, which must find the order already committed.
        return Cache::lock("audit-partner-order:{$auditRequest->id}", 30)->block(10, function () use ($auditRequest, $user, $tenant, $product, $offering, $offline): Order {
            $locked = AuditRequest::query()->whereKey($auditRequest->id)->firstOrFail();
            $existingId = $locked->meta[self::META_KEY] ?? null;

            if ($existingId !== null && ($existing = Order::withoutGlobalScopes()->find($existingId)) !== null) {
                return $existing;
            }

            $price = (int) $offering->price;
            $currency = $this->currencies->getCurrency();

            $order = $this->orderService->create(
                $user,
                $tenant,
                paymentProvider: $offline,
                totalAmount: $price,
                totalAmountAfterDiscount: $price,
                currency: $currency,
                orderItems: [[
                    'one_time_product_id' => $product->id,
                    'quantity' => 1,
                    'currency_id' => $currency->id,
                    'price_per_unit' => $price,
                    'price_per_unit_after_discount' => $price,
                    'discount_per_unit' => 0,
                ]],
                isLocal: true,
                snapshot: $this->snapshots->forProduct($user, $product),
            );

            $locked->update(['meta' => array_merge((array) $locked->meta, [self::META_KEY => $order->id])]);
            $auditRequest->setRawAttributes($locked->getAttributes(), true);

            // Names the request this order pays for (see HandleAuditTierOrder::intentRequestFor()).
            TenantParameter::updateOrCreate(
                ['tenant_id' => $tenant->id, 'name' => HandleAuditTierOrder::INTENT_PARAM],
                ['value' => $auditRequest->uuid],
            );

            return $order;
        });
    }
}
