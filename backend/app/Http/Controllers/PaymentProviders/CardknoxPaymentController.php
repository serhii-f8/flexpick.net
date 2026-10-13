<?php

namespace App\Http\Controllers\PaymentProviders;

use App\Constants\OrderStatus;
use App\Constants\SubscriptionStatus;
use App\Constants\SubscriptionType;
use App\Filament\Dashboard\Resources\Subscriptions\SubscriptionResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\CardknoxCardRequest;
use App\Models\Currency;
use App\Models\Discount;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\PaymentProviders\Cardknox\CardknoxBillingService;
use App\Services\PaymentProviders\Cardknox\CardknoxCardVault;
use App\Services\PaymentProviders\Cardknox\CardknoxConfiguration;
use App\Services\PaymentProviders\Cardknox\CardknoxOutcome;
use App\Services\PaymentProviders\Cardknox\CardknoxResult;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Our own hosted card form. Cardknox has no hosted subscription checkout, so
 * CardknoxProvider redirects here instead; card data only ever enters
 * Cardknox's iFields iframes.
 */
class CardknoxPaymentController extends Controller
{
    public function __construct(
        private CardknoxBillingService $billing,
        private CardknoxCardVault $vault,
        private CardknoxConfiguration $configuration,
    ) {}

    public function showSubscription(Request $request, string $subscriptionUuid): View
    {
        $subscription = $this->ownedSubscription($subscriptionUuid);
        $amount = $this->billing->quoteFirstCharge($subscription, $this->quantity($request), $this->discount($request));
        $price = (string) money($amount, Currency::query()->findOrFail($subscription->currency_id)->code);

        return $this->page(
            title: Plan::query()->findOrFail($subscription->plan_id)->name,
            summary: $amount === 0
                ? __('Nothing is charged today. Your card is saved for future renewals.')
                : __('You will be charged :amount today.', ['amount' => $price]),
            action: $this->signedAction('payments-providers.cardknox.pay.subscription.submit', $request, ['subscriptionUuid' => $subscription->uuid]),
            buttonLabel: $amount === 0 ? __('Save card') : __('Pay :amount', ['amount' => $price]),
            cancelUrl: $subscription->type === SubscriptionType::LOCALLY_MANAGED
                ? route('checkout.convert-local-subscription', ['subscriptionUuid' => $subscription->uuid])
                : route('checkout.subscription', ['planSlug' => Plan::query()->findOrFail($subscription->plan_id)->slug]),
            offerSaveCard: false,
        );
    }

    public function paySubscription(CardknoxCardRequest $request, string $subscriptionUuid): RedirectResponse
    {
        $subscription = $this->ownedSubscription($subscriptionUuid);

        // Decided before startSubscription() flips the type.
        $successUrl = $subscription->type === SubscriptionType::LOCALLY_MANAGED
            ? route('checkout.convert-local-subscription.success')
            : route('checkout.subscription.success');

        $payable = $subscription->type === SubscriptionType::LOCALLY_MANAGED
            || in_array($subscription->status, [SubscriptionStatus::NEW->value, SubscriptionStatus::PENDING->value], true);

        if (! $payable) {
            return redirect()->to($successUrl);
        }

        $tokenized = $this->vault->tokenize($request->string('xCardNum')->toString(), $request->string('xCVV')->toString(), $request->exp());

        if (! $tokenized->isApproved()) {
            return $this->failed($tokenized, __('Your card could not be verified.'));
        }

        $this->vault->remember($request->user(), $subscription->tenant, $tokenized, $request->exp());

        $result = $this->billing->startSubscription($subscription, $this->quantity($request), $this->discount($request));

        return $this->isFailure($result) ? $this->failed($result, __('Your card was declined.')) : redirect()->to($successUrl);
    }

    public function showOrder(string $orderUuid): View
    {
        $order = $this->ownedOrder($orderUuid);
        $price = (string) money((int) $order->total_amount_after_discount, Currency::query()->findOrFail($order->currency_id)->code);

        return $this->page(
            title: __('Complete your purchase'),
            summary: __('You will be charged :amount today.', ['amount' => $price]),
            action: URL::temporarySignedRoute('payments-providers.cardknox.pay.order.submit', now()->addHours(2), ['orderUuid' => $order->uuid]),
            buttonLabel: __('Pay :amount', ['amount' => $price]),
            cancelUrl: route('checkout.product'),
            offerSaveCard: true,
        );
    }

    public function payOrder(CardknoxCardRequest $request, string $orderUuid): RedirectResponse
    {
        $order = $this->ownedOrder($orderUuid);

        if (! in_array($order->status, [OrderStatus::NEW->value, OrderStatus::PENDING->value], true)) {
            return redirect()->route('checkout.product.success');
        }

        $tokenized = $this->vault->tokenize($request->string('xCardNum')->toString(), $request->string('xCVV')->toString(), $request->exp());

        if (! $tokenized->isApproved()) {
            return $this->failed($tokenized, __('Your card could not be verified.'));
        }

        // Only an explicit opt-in may replace the card a subscription renews on.
        if ($request->boolean('save_card')) {
            $this->vault->remember($request->user(), $order->tenant, $tokenized, $request->exp());
        }

        $result = $this->billing->payOrder($order, (string) $tokenized->token);

        return $this->isFailure($result) ? $this->failed($result, __('Your card was declined.')) : redirect()->route('checkout.product.success');
    }

    public function showUpdateCard(string $subscriptionUuid): View
    {
        $subscription = $this->ownedSubscription($subscriptionUuid);
        $card = $this->vault->cardFor($subscription->tenant_id, $subscription->user_id);

        return $this->page(
            title: __('Update payment method'),
            summary: $card === null
                ? __('Add a card for future renewals.')
                : __('Current card: :brand ending in :last4.', ['brand' => $card->card_brand, 'last4' => $card->card_last4]),
            action: URL::temporarySignedRoute('payments-providers.cardknox.card.submit', now()->addHours(2), ['subscriptionUuid' => $subscription->uuid]),
            buttonLabel: __('Save card'),
            cancelUrl: SubscriptionResource::getUrl(tenant: $subscription->tenant),
            offerSaveCard: false,
        );
    }

    public function updateCard(CardknoxCardRequest $request, string $subscriptionUuid): RedirectResponse
    {
        $subscription = $this->ownedSubscription($subscriptionUuid);
        $tokenized = $this->vault->tokenize($request->string('xCardNum')->toString(), $request->string('xCVV')->toString(), $request->exp());

        if (! $tokenized->isApproved()) {
            return $this->failed($tokenized, __('Your card could not be verified.'));
        }

        $this->vault->remember($request->user(), $subscription->tenant, $tokenized, $request->exp());

        $result = $this->billing->retryOutstanding($subscription);

        if ($result !== null && $this->isFailure($result)) {
            return back()->withErrors(['card' => __('Your card was saved, but the overdue payment was declined: :reason', ['reason' => $result->error ?? __('declined')])]);
        }

        return redirect()->to(SubscriptionResource::getUrl(tenant: $subscription->tenant))->with('success', __('Your card has been updated.'));
    }

    private function ownedSubscription(string $uuid): Subscription
    {
        $subscription = Subscription::query()->where('uuid', $uuid)->firstOrFail();
        abort_unless((int) $subscription->user_id === (int) auth()->id(), 403);

        return $subscription;
    }

    private function ownedOrder(string $uuid): Order
    {
        $order = Order::query()->where('uuid', $uuid)->firstOrFail();
        abort_unless((int) $order->user_id === (int) auth()->id(), 403);

        return $order;
    }

    private function quantity(Request $request): int
    {
        return max(1, (int) $request->query('quantity', '1'));
    }

    /** The id rides in the signed URL, so it cannot be tampered with. */
    private function discount(Request $request): ?Discount
    {
        $id = $request->query('discount');

        return $id === null ? null : Discount::query()->find((int) $id);
    }

    private function signedAction(string $route, Request $request, array $parameters): string
    {
        $query = array_filter([
            'quantity' => $request->query('quantity'),
            'discount' => $request->query('discount'),
        ], fn ($value) => $value !== null);

        return URL::temporarySignedRoute($route, now()->addHours(2), $parameters + $query);
    }

    private function isFailure(CardknoxResult $result): bool
    {
        return in_array($result->outcome, [CardknoxOutcome::Declined, CardknoxOutcome::Error], true);
    }

    private function failed(CardknoxResult $result, string $fallback): RedirectResponse
    {
        return back()->withErrors(['card' => $result->error ?? $fallback]);
    }

    private function page(string $title, string $summary, string $action, string $buttonLabel, string $cancelUrl, bool $offerSaveCard): View
    {
        return view('payment-providers.cardknox.pay', [
            'title' => $title,
            'summary' => $summary,
            'action' => $action,
            'buttonLabel' => $buttonLabel,
            'cancelUrl' => $cancelUrl,
            'offerSaveCard' => $offerSaveCard,
            'ifieldsKey' => $this->configuration->ifieldsKey(),
            'ifieldsVersion' => $this->configuration->ifieldsVersion(),
        ]);
    }
}
