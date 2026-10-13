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
