# Cardknox sandbox checklist

Run once sandbox credentials exist, before enabling Cardknox in production.
Record each answer next to its item; items marked **[contract]** confirm an
assumption baked into `app/Client/CardknoxClient.php`.

## Setup
1. Admin → Payment Providers → Cardknox settings: save sandbox API key, iFields key, PIN (≥15 alphanumerics).
2. `docker compose --profile ngrok up -d`; set the portal webhook URL to `<ngrok>/api/payments-providers/cardknox/webhook` with the same PIN; select all fields.
3. Enable Cardknox (the toggles unlock only once settings are complete).

## Checkout
4. **[contract]** The pay page loads iFields from `cdn.cardknox.com/ifields/<CARDKNOX_IFIELDS_VERSION>/`. Update the version in `config/services.php` if the portal lists a newer one.
5. Subscribe to a flat plan with an approval test card → subscription ACTIVE, transaction SUCCESS, `ends_at` one interval out.
6. Repeat with a decline test card → error shown on the form, subscription stays NEW.
7. Subscribe to a trial plan → no charge, card saved, ACTIVE with `trial_ends_at`.
8. Buy an audit tier (one-time) with "save card" unchecked → order SUCCESS, existing card unchanged.

## Renewals
9. Set a subscription's `ends_at` to the past, run `php artisan app:charge-cardknox-renewals --subscription=<uuid>` → charged once, period advanced.
10. **[contract]** In the portal transaction detail, confirm `xRecurringIndicator=Recurring` was accepted (no error). If the gateway rejects it, change `CardknoxClient::RECURRING`.

## Webhooks and reconciliation
11. **[contract]** A webhook arrives for step 5's sale with `ck-signature` that our handler accepts (check Horizon / `jobs` for `ProcessCardknoxWebhook`).
12. Refund step 8's sale from the portal → order becomes REFUNDED via webhook. **[contract]** Note whether the refund webhook carries the original `xInvoice`; if not, `CardknoxChargeLedger::applyWebhook()` must match refunds by another field.
13. **[contract]** Run `report:all` manually (tinker: `app(App\Client\CardknoxClient::class)->findByInvoice('<invoice>', now()->subDay())`) and confirm rows expose `xInvoice`, `xCommand` and `xResponseResult` as the client expects.
14. **[contract]** Chargebacks: ask Sola support or trigger a test dispute. If no webhook is delivered, the admin "Mark disputed" action is the process — document it for the operator.

## Go-live
15. Replace `public/images/payment-providers/cardknox.png` with the real logo.
16. Swap to production keys and PIN; set the production webhook URL in the production portal.

## Added after review
17. **[contract]** Report API: confirm the timezone `xBeginDate`/`xEndDate` are read in and whether `xMaxRecords` above 1000 is honoured. `CardknoxChargeLedger::reconcilePending()` looks back a full day to tolerate an offset, but a busy account could still exceed the 1000-row cap, and a miss fails (and later retries) a charge that may have been approved.

## Sandbox findings (2026-10-20)
- 4, 10: iFields 2.15.2401.3101 works; `xRecurringIndicator=Recurring` is accepted on renewals.
- 12, 13: reports are a separate endpoint (`/reportjson`), and their default columns omit `xInvoice` —
  `findByInvoice()` now requests it via `xFields`. Refund report rows carry the original `xInvoice`;
  still unconfirmed for real webhooks, because the sandbox delivered none (check the portal's Gateway Settings > Webhook).
- 17: report dates (`xEnteredDate`) are US Eastern; the one-day lookback covers the offset.
- Webhooks and report rows carry the outcome as `xResponseResult` ("Approved"), not `xResult`;
  `CardknoxResult::fromNotification()` maps it. Sola sends one webhook per transaction, single attempt.
- Sandbox decline trigger: amount $9.91 (we never send `xName`). Amounts over $10 need a listed test card (4444333322221111).
- Partner-priced plans and products are now payable by Cardknox as well as Offline
  (`PaymentProviderConstants::PARTNER_PRICE_SLUGS`); Cardknox bills the subscription's frozen partner price.
