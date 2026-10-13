<?php

namespace App\Services\PaymentProviders\Cardknox;

use App\Constants\PaymentProviderConstants;
use App\Constants\PlanType;
use App\Models\Discount;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\CurrencyService;
use App\Services\PaymentProviders\PaymentProviderInterface;
use Exception;
use Illuminate\Support\Facades\URL;

/**
 * Cardknox is a card gateway, not a subscription platform: it redirects to our
 * own iFields pay page, and the app bills via CardknoxBillingService.
 */
class CardknoxProvider implements PaymentProviderInterface
{
    public function __construct(
        private CardknoxBillingService $billing,
        private CurrencyService $currencyService,
    ) {}

    public function getSlug(): string
    {
        return PaymentProviderConstants::CARDKNOX_SLUG;
    }

    public function getName(): string
    {
        return PaymentProvider::where('slug', $this->getSlug())->firstOrFail()->name;
    }

    public function createSubscriptionCheckoutRedirectLink(Plan $plan, Subscription $subscription, ?Discount $discount = null, int $quantity = 1): string
    {
        $this->assertProviderIsActive();

        return URL::temporarySignedRoute('payments-providers.cardknox.pay.subscription', now()->addHours(2), array_filter([
            'subscriptionUuid' => $subscription->uuid,
            'quantity' => $quantity,
            'discount' => $discount?->id,
        ], fn ($value) => $value !== null));
    }

    public function createProductCheckoutRedirectLink(Order $order, ?Discount $discount = null): string
    {
        $this->assertProviderIsActive();

        // The order totals already include the discount.
        return URL::temporarySignedRoute('payments-providers.cardknox.pay.order', now()->addHours(2), ['orderUuid' => $order->uuid]);
    }

    public function initSubscriptionCheckout(Plan $plan, Subscription $subscription, ?Discount $discount = null, int $quantity = 1): array
    {
        return [];
    }

    public function initProductCheckout(Order $order, ?Discount $discount = null): array
    {
        return [];
    }

    public function isRedirectProvider(): bool
    {
        return true;
    }

    public function isOverlayProvider(): bool
    {
        return false;
    }

    public function changePlan(Subscription $subscription, Plan $newPlan, bool $withProration = false): bool
    {
        $this->assertProviderIsActive();

        return $this->billing->changePlan($subscription, $newPlan, $withProration);
    }

    // SubscriptionService sets is_canceled_at_end_of_cycle once this returns
    // true; the renewal command then cancels at ends_at instead of charging.
    public function cancelSubscription(Subscription $subscription): bool
    {
        return true;
    }

    public function discardSubscriptionCancellation(Subscription $subscription): bool
    {
        return true;
    }

    public function getChangePaymentMethodLink(Subscription $subscription): string
    {
        return URL::temporarySignedRoute('payments-providers.cardknox.card', now()->addHours(2), ['subscriptionUuid' => $subscription->uuid]);
    }

    // DiscountService::redeemCodeForSubscription() writes the SubscriptionDiscount
    // row that renewals read; there is nothing to create on the gateway.
    public function addDiscountToSubscription(Subscription $subscription, Discount $discount): bool
    {
        return true;
    }

    public function updateSubscriptionQuantity(Subscription $subscription, int $quantity, bool $isProrated = true): bool
    {
        $this->assertProviderIsActive();

        return $this->billing->changeQuantity($subscription, $quantity, $isProrated);
    }

    public function supportsPlan(Plan $plan): bool
    {
        if (! in_array($plan->type, [PlanType::FLAT_RATE->value, PlanType::USAGE_BASED->value, PlanType::SEAT_BASED->value], true)) {
            return false;
        }

        $currency = $this->currencyService->getCurrency();

        return $currency->code === 'USD' && $plan->prices()->where('currency_id', $currency->id)->exists();
    }

    // SubscriptionUsageService records a SubscriptionUsage row when this
    // returns true; renewals sum those rows for the period.
    public function reportUsage(Subscription $subscription, int $unitCount): bool
    {
        return true;
    }

    public function supportsSkippingTrial(): bool
    {
        return true;
    }

    public function supportsSeatBasedWithIncludedSeats(): bool
    {
        return true;
    }

    public function supportsOneTimePurchaseProductQuantity(): bool
    {
        return true;
    }

    public function supportsSetupFees(): bool
    {
        return true;
    }

    private function assertProviderIsActive(): PaymentProvider
    {
        $paymentProvider = PaymentProvider::where('slug', $this->getSlug())->firstOrFail();

        if ($paymentProvider->is_active === false) {
            throw new Exception('Payment provider is not active: '.$this->getSlug());
        }

        return $paymentProvider;
    }
}
