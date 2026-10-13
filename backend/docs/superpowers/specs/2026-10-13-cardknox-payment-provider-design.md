# Cardknox Payment Provider — Design

Date: 2026-10-13
Status: Approved in conversation, pending written-spec review

## 1. Intent

Add Cardknox (rebranded **Sola**, docs at docs.solapayments.com) as a selectable payment
provider with **full parity with Stripe**: subscriptions (flat-rate, seat-based,
usage-based), trials, setup fees, discounts, plan changes with proration, seat changes,
cancellation/resume, change payment method, and one-time product orders (audit tiers,
credit packs).

Cardknox is a card **gateway**, not a subscription platform: it has no hosted
subscription checkout and no notion of plans, trials, discounts, proration, or seats.
Therefore the app owns billing (**app-driven billing**, chosen over Cardknox recurring
schedules and over PaymentSITE). Cardknox only tokenizes cards (iFields), stores
payment methods, and executes charges/refunds.

### Success criteria

- A customer can buy any plan or one-time product with Cardknox and end up in exactly the
  same local state (subscription/order/transaction rows, events dispatched) as a Stripe
  buyer.
- Renewals, retries, plan/seat changes and cancellation run without operator involvement.
- No billing period is ever charged twice, even under command overlap, retries or
  gateway timeouts.
- Existing providers, the shared Livewire checkout, and the cash/offline flow are
  unaffected.

### Out of scope (v1)

3-D Secure, ACH and wallets (Apple Pay / Google Pay), migration of existing subscriptions
between providers, non-USD currencies.

## 2. Key decision: subscription type

Cardknox subscriptions are stored as **`SubscriptionType::PAYMENT_PROVIDER_MANAGED`**, even
though the app performs the billing. `LOCALLY_MANAGED` is treated as "provider-less" in
~15 places (`cleanupLocalSubscriptionStatuses()` sweeps them to `INACTIVE`, admin
end/update actions, `isIncompleteSubscription()`, `shouldSkipTrial()`, expiring-soon
reminders, cash renewal commands). Keeping the billing engine inside the Cardknox
provider namespace makes Cardknox indistinguishable from Stripe to the rest of the app.

## 3. Components

All under `app/Services/PaymentProviders/Cardknox/` unless noted.

| Unit | Responsibility |
|---|---|
| `app/Client/CardknoxClient.php` | Thin HTTP wrapper: Transaction API (`cc:sale`, `cc:save`, `cc:refund`, `cc:void`, report lookups) and Customer/PaymentMethod endpoints of the Recurring API v2. Auth, `xKey`, `xVersion`, error mapping to a result object with outcome `Approved`, `Declined(reason)`, `Error(retryable)`, or `Unknown` (timeouts/network). No business logic. |
| `CardknoxProvider` | Implements `PaymentProviderInterface`. `isRedirectProvider() = true`, `isOverlayProvider() = false`. Checkout links point at our hosted pay page. Lifecycle methods delegate to `CardknoxBillingService`. |
| `CardknoxBillingService` | The billing engine: first charge, renewal charge, proration, discount application/expiry, usage totals, retry policy. Writes only through `SubscriptionService`, `TransactionService`, `OrderService` (the same calls Stripe's webhook handler makes). |
| `CardknoxWebhookHandler` | Signature verification, dedupe, dispatch of a queued processing job. |
| `app/Http/Controllers/PaymentProviders/CardknoxController.php` + views under `resources/views/payment-providers/cardknox/` | Webhook entry point and the iFields pay page (`/payments-providers/cardknox/pay/{subscription\|order}/{uuid}`, signed). Same page in "update card" mode. |
| `app:charge-cardknox-renewals` | Hourly, `withoutOverlapping()->onOneServer()`. Optional `--subscription=uuid` for manual runs. |
| `app:reconcile-cardknox-transactions` | Hourly, `withoutOverlapping()->onOneServer()`. |
| Admin settings page | `app/Filament/Admin/Resources/PaymentProviders/Pages/CardknoxSettings.php` + Livewire settings component + how-to partial, mirroring Lemon Squeezy/Paddle. |
| Wiring | `PaymentProviderConstants::CARDKNOX_SLUG = 'cardknox'`, `config/services.php` keys, `.env.example`, `PaymentProvidersSeeder` row (`is_active = false`), `AppServiceProvider` tag `payment-providers`, route in `routes/api.php`. |

No plan/product/price mapping exists on the Cardknox side, so Cardknox does not appear in
`PaymentProviderDataRelationManager` and needs no product validator.

The shared checkout (`SubscriptionCheckoutForm`, `ProductCheckoutForm`,
`ConvertLocalSubscriptionCheckoutForm`, `payment.blade.php`) is not modified: Cardknox
behaves as a redirect provider whose redirect target is our own pay page.

## 4. Data model

**New table `user_cardknox_data`** (mirrors `user_stripe_data`): `id`, `tenant_id`,
`user_id`, `cardknox_customer_id`, `cardknox_payment_method_id`, `card_brand`,
`card_last4`, `card_exp`, timestamps. Card fields are display-only. No card data or raw
tokens beyond Cardknox's stored payment-method reference.

**New table `cardknox_usage_records`**: `id`, `subscription_id`, `units`, `period_end`,
timestamps. Indexed on (`subscription_id`, `period_end`).

**Subscription `extra_payment_provider_data` JSON** (existing column): `failed_attempts`,
`next_retry_at`, `pending_credit` (cents), `discount_started_cycle`, `cycle` (count of
successful period charges).

Discounts use the existing `subscription_discounts` table (`SubscriptionDiscount`); no new
discount storage.

`payment_provider_subscription_id` = `ckx_sub_{uuid}` so code expecting a non-null value
keeps working.

## 5. Flows

### A. New subscription

1. Livewire checkout creates the subscription in `NEW` status (unchanged) and redirects
   to the pay page.
2. iFields tokenizes the card in Cardknox's iframe; the page posts the single-use token
   (SUT) to `CardknoxController`.
3. Find or create the Cardknox customer for (tenant, user); save the SUT as a payment
   method; upsert `user_cardknox_data`.
4. **Trial**: no charge; `updateSubscription(status ACTIVE, trial_ends_at, ends_at = trial_ends_at)`.
   **No trial / trial skipped**: charge first period (plan price via `CalculationService`
   × quantity − discount + setup fee) with `cc:sale`. Approved → `createForSubscription`
   (SUCCESS) + `updateSubscription(ACTIVE, ends_at = now + interval)`. Declined → error
   shown on the page, subscription stays `NEW`, user may retry. Unknown → transaction
   stays pending, page shows "processing", reconciliation settles it.
5. Existing events (`Subscribed`, `SubscriptionRenewed`) fire from `updateSubscription`;
   downstream entitlement logic is untouched.

### B. One-time order

Same pay page; one `cc:sale`; `createForOrder` + `updateOrder(SUCCESS)` so the `Ordered`
event drives fulfilment as with Stripe. An optional "save card" checkbox stores the
payment method for later.

### C. Renewal (hourly command)

Selects Cardknox subscriptions in `ACTIVE`/`PAST_DUE` with `ends_at <= now` or
`next_retry_at <= now`. Per subscription:

1. `lockForUpdate` inside a DB transaction.
2. If `is_canceled_at_end_of_cycle` → `updateSubscription(CANCELED)`; stop.
3. Compute amount: plan price × quantity (tiers/per-unit/included seats via
   `CalculationService`) − discount (if within `duration_in_months` /
   `maximum_recurring_intervals`) + usage for the ended period (usage-based plans) −
   `pending_credit` (floored at 0, remainder carried).
4. Invoice key `{subscription_uuid}-{period_end ISO date}`. If a pending or successful
   transaction already exists for that key, skip.
5. Create a **pending** transaction with that key, then `cc:sale` with the stored payment
   method and `xInvoice = key`.
6. Approved → transaction SUCCESS, `ends_at += interval`, reset retry state.
   Declined → transaction FAILED, `handleInvoicePaymentFailed()`, status `PAST_DUE`,
   `next_retry_at` per schedule (+1, +3, +5 days from the original due date). After the
   final failed attempt → `CANCELED`.
   Unknown → leave pending; reconciliation resolves it by `xInvoice`.
7. Exceptions are caught per subscription, reported to Sentry, and the loop continues.

**Idempotency rule**: one invoice key per period, row lock, `onOneServer()`, and the
pre-charge pending transaction together guarantee no double charge.

## 6. Lifecycle methods

All capability flags return true (`supportsSkippingTrial`, `supportsSeatBasedWithIncludedSeats`,
`supportsOneTimePurchaseProductQuantity`, `supportsSetupFees`). `supportsPlan()` accepts
FLAT_RATE, USAGE_BASED, SEAT_BASED with a USD price.

- **`changePlan($withProration)`**: prorated → credit unused portion of old plan, charge
  remainder of new plan for the current period, net amount charged now; negative net is
  stored as `pending_credit` (no card refund on downgrade). `ends_at` unchanged.
  Non-prorated → swap `plan_id`/`price`; new price applies at next renewal. Declined
  proration charge → return false, plan unchanged.
- **`updateSubscriptionQuantity`**: same proration logic on the seat delta.
- **`cancelSubscription`**: set `is_canceled_at_end_of_cycle = true`.
- **`discardSubscriptionCancellation`**: clear it.
- **`addDiscountToSubscription`**: create a `SubscriptionDiscount` row (existing table:
  `type`, `amount`, `is_recurring`, `valid_until`), with `valid_until` derived from
  `duration_in_months`, and store `discount_started_cycle` in
  `extra_payment_provider_data` so `maximum_recurring_intervals` can be enforced. A
  non-recurring discount applies to one charge only. The same row is written when a
  discount code is used at checkout (Flow A).
- **`reportUsage`**: insert a `cardknox_usage_records` row for the current period; summed
  and priced at renewal (charged in arrears).
- **`getChangePaymentMethodLink`**: signed pay-page URL in update-card mode; saves the new
  payment method and, if `PAST_DUE`, immediately retries the outstanding charge.
- **Setup fees**: added to the first charge.

**Admin actions** on Cardknox transactions: "Charge now" (runs the renewal path for one
subscription), "Refund" (`cc:refund`, or `cc:void` if unsettled), "Mark disputed".
Refund/dispute updates mirror Stripe's `charge.refunded` / `charge.dispute.*` handling.

## 7. Webhooks and reconciliation

- `POST /payments-providers/cardknox/webhook` in `routes/api.php`.
- Verify `ck-signature`: parse the form body, lowercase keys, sort, concatenate values,
  append PIN, replace non-ASCII with `?`, MD5, case-insensitive compare. Missing or bad
  → 403. A PIN is mandatory; the settings page refuses to enable Cardknox without one.
- Respond 200 immediately; process in a queued job (Cardknox times out at 10 s and never
  retries).
- Job: dedupe on `xRefNum`; locate the transaction by `xRefNum` or `xInvoice`; settle
  pending transactions; record refunds and voids against transaction and order. Ignore
  unknown fields.
- Webhooks confirm; they do not drive state. Synchronous API responses already update
  state.
- Reconciliation command: settle transactions pending > 15 min via report lookup by
  `xInvoice`; import refunds/voids from the last 48 h made in the Cardknox portal.
- Chargeback delivery via webhook is unverified; the sandbox pass determines whether a
  webhook path is needed or the admin "Mark disputed" action is the mechanism.

## 8. Errors, security, config

- Timeouts map to `Unknown`, never `Declined`.
- `.env`: `CARDKNOX_TRANSACTION_KEY` (server-only), `CARDKNOX_IFIELDS_KEY` (public),
  `CARDKNOX_WEBHOOK_PIN`. Sandbox vs production is determined by which keys are set.
- `TokenScrubber` strips `xKey`, `xToken`, `xCardNum`, `xCVV`, and the webhook PIN.
- Pay page loads iFields from Cardknox's CDN; card data reaches only Cardknox's iframe.
  Pay URLs are signed and authorized against the subscription/order owner.
- Non-USD plans and products are rejected.

## 9. Observability

New checks in `app/Health/Checks/`, each with a band in the `flexpick` block of
`config/health.php`:

- `CardknoxRenewalBacklogCheck` — **high**: any Cardknox subscription with `ends_at`
  more than 2 h in the past and no charge attempt.
- `CardknoxDeclineRateCheck` — **medium**.
- `CardknoxPendingTransactionsCheck` — **medium**: transactions pending > 1 h.

## 10. Testing

PHPUnit, TDD per task, gateway faked with `Http::fake`. Run in Docker.

- `CardknoxClientTest`: request shape; outcome mapping including timeouts.
- `CardknoxBillingServiceTest`: first charge (trial, no trial, setup fee); seat/tier
  pricing equals `CalculationService` output; discount expiry; prorated upgrade charge
  and downgrade credit; usage totals.
- `ChargeCardknoxRenewalsTest`: renewal advances `ends_at` and fires
  `SubscriptionRenewed`; decline retry ladder to `CANCELED`; cancel-at-end-of-cycle;
  double/concurrent runs never double-charge; Unknown stays pending and reconciliation
  settles it.
- `CardknoxControllerTest` (mirrors `StripeControllerTest`): signature accept/reject,
  missing PIN, duplicate `xRefNum`, refund updates order.
- Pay page: signed URL required, wrong owner 403, decline keeps subscription `NEW`.
- Regression: `cleanupLocalSubscriptionStatuses()` and cash commands never touch
  Cardknox subscriptions.
- Gates: full suite, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`.

## 11. Rollout

- Seeder adds Cardknox inactive. Admin enables it after saving keys and PIN.
- Manual sandbox pass before enabling (requires a Cardknox sandbox account): iFields
  checkout with test cards (approve/decline; subscription, trial, one-time); forced
  renewal via `--subscription`; webhook via the ngrok profile; portal refund picked up by
  reconciliation; chargeback-delivery check.
- Existing Stripe subscriptions are untouched; Cardknox is used for new checkouts only.
