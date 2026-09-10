# Production deploy: `partner-reselling` → `growth-retention`

**Date prepared:** 2026-09-10
**From:** production on `growth-retention` (`origin` tip `373d9b4`)
**To:** `partner-reselling` (`144db77` + the two review fixes below), fast-forwarded onto `growth-retention`

## Branch state

- `growth-retention` is a strict ancestor of `partner-reselling` — the merge is a pure fast-forward.
- 149 commits, 253 files (+31,915 / −1,558). All under `backend/` except four frontend files
  (`src/data/pricing.json`, `pages/index.astro`, `pages/terms.astro`, `components/widgets/ContactModal.astro`).
- `deploy.php` clones from `origin` (`serhii-f8/flexpick.net`) and pins `branch => main` for production;
  the `prod` remote (`flexpick/flexpick.net`) no longer exists. Deploy with `--branch=growth-retention`.

## Pre-deploy gates (run in Docker on the branch)

| Gate | Result |
|---|---|
| `vendor/bin/pint --test` | PASS, 1139 files |
| `php artisan test --compact` | 1575 passed, 4090 assertions |
| `vendor/bin/phpstan analyse` | 1 error in `AuditGroupDeltaService.php:112` — byte-identical on `growth-retention`, pre-existing |
| `php artisan app:export-pricing --check` | pricing data current |
| `frontend: npm run build` | 10 pages built |
| `php artisan app:smoke` | 9/9 PASS |

## Review findings

Fixed on the branch before deploy (both TDD'd, tests in the suite):

1. **HIGH — plan change kept the old plan's credits.** Every subscription created after this deploy
   carries a `quota_snapshot` that wins over live plan metadata, and gateway `changePlan()` only rewrote
   `plan_id`/`price`. `SubscriptionService::changePlan()` now re-freezes the snapshot from the new plan
   (`refreshPurchaseSnapshot()`).
2. **HIGH — attributed buyers could never unlock a report.** `ProductCheckoutController` gated the
   not-visible `audit-report-unlock` SKU, for which no partner offering can exist. The gate now exempts
   `is_visible = false` products, mirroring `CheckoutService::assertProductPurchasable()`.

Still open (schedule follow-ups):

3. **HIGH — existing cash subscription cancelled at checkout *submit*, before approval.**
   `CheckoutService::resolveSubscriptionTenant()` → `supersedeCashSubscriptions()`. A repeat cash buyer
   has zero entitlement until the partner approves (≤72 h) and loses the paid cycle if the order is
   rejected. The predicate (`type = LOCALLY_MANAGED` only) also matches the partner's own
   `audit-partner` plan, so a partner buying a package via "Buy More / Upgrade" cancels their own
   partner status. Fix: supersede on approval, and restrict the predicate to Offline + `price > 0`.
4. MEDIUM — `SubscriptionCheckoutForm`/`ProductCheckoutForm` never validate `$this->paymentProvider`
   against `restrictToPartnerProviders()`; a partner-priced buyer can submit `stripe` and pay base price.
5. MEDIUM — a *direct* (non-partner) cash renewal order notifies nobody; the customer's first email is
   `CustomerOrderExpired`.
6. LOW — approving a PURCHASE order whose subscription was cancelled out of band resurrects it.
7. LOW — purchased-credit grant/spend is an unlocked read-modify-write on `UserParameter`.
8. LOW — purchased credit ignores order-item quantity (moot while `max_quantity = 1`).

Behaviour changes by design, worth knowing:

- Referral URL parameter renamed `?referralCode=` → `?rc=`. Previously shared links stop attributing.
- Partner attribution is set-once and also fires at **login**: an existing customer who follows a partner
  link and signs in is permanently attributed (restricted catalog, partner pricing, cash-only).
- `ReportPackagesSeeder` deactivates `audit-starter/growth/agency/enterprise-monthly`. Existing
  subscribers keep resolving (entitlements read product metadata, not `is_active`).
- Queue `after_commit => true` on both Redis connections — needs the Horizon restart the deploy performs.

## Deployment-relevant surface

- **11 migrations**, all additive (new columns on `users`, `products`, `one_time_products`, `orders`,
  `subscriptions`; new tables `partner_plan_offerings`, `partner_product_offerings`, `order_approvals`,
  `notifications`; `partner_referral_links` is created then dropped).
- **Seeders run on every deploy** (`after('artisan:migrate', 'artisan:db:seed')`): 9 package
  products/plans, 2 new tenancy permissions on the tenant admin role, `enables_reseller_program` on
  `audit-partner`, retirement of the 5 old plans. Idempotent on slug.
- **New env keys**: `CASH_SWEEP_FROM` (critical), `REFERRAL_ENABLED=true` (required for `?rc=` links —
  `TrackReferralCode` bails when referrals are disabled), `SUPPORT_EMAIL`, `LEGAL_*` ×5,
  `PARTNER_COOKIE_*` ×2. Optional: `CASH_PENDING_TTL_HOURS`, `CASH_RENEWAL_LEAD_HOURS` (default 72).
- **New scheduled commands**: `app:issue-cash-renewal-orders`, `app:expire-pending-cash-orders`
  (hourly), `horizon:snapshot` (every 5 min). Existing cron picks them up.
- `CASH_SWEEP_FROM` defaults to `2026-09-05`. Any pending Offline order / paid Offline subscription
  created since then is swept unless the value is set to the real deploy time.

## Runbook

### Step 0 — local: commit the fixes, fast-forward, push

From `/var/www/html/flexpick.net`:

```bash
git checkout partner-reselling
git add backend/app/Http/Controllers/ProductCheckoutController.php \
        backend/app/Services/SubscriptionService.php \
        backend/tests/Feature/Http/Controllers/ProductCheckoutControllerTest.php \
        backend/tests/Feature/Services/SubscriptionServiceTest.php
git commit -m "fix(partner): refresh quota snapshot on plan change; exempt not-visible products from the cart gate"

git checkout growth-retention
git merge --ff-only partner-reselling        # guaranteed fast-forward
git push origin growth-retention             # fast-forward over origin's 373d9b4
git remote remove prod                       # dead remote; optional cleanup
```

### Step 1 — server: edit the shared `.env` BEFORE deploying

`artisan:optimize` caches config *before* `migrate`/seed, so this must come first.

```bash
ssh deployer@app.flexpick.net
nano ~/app/shared/.env
```

Add / verify:

```dotenv
# Set to the actual UTC deploy moment. Rows older than this are ignored by the cash sweeps.
CASH_SWEEP_FROM=2026-09-10T12:00:00Z

# Required for ?rc= partner links to attribute at all.
REFERRAL_ENABLED=true

SUPPORT_EMAIL=info@flexpick.net
PARTNER_COOKIE_NAME=fp_rc
PARTNER_COOKIE_LIFETIME_DAYS=365

# /privacy-policy and /terms-of-service. Blank values are omitted from the page,
# but a Polish JDG must publish its address and NIP.
LEGAL_OPERATOR_NAME=
LEGAL_OPERATOR_ADDRESS=
LEGAL_OPERATOR_NIP=
LEGAL_OPERATOR_REGON=
LEGAL_CONTACT_EMAIL=info@flexpick.net
```

### Step 2 — server: preflight + backup

```bash
cd ~/app/current

# Expect the 11 2026_08_30_* / 2026_09_05_* / 2026_09_07_* migrations as "Pending".
# If they show "Ran", prod already has this schema — stop and investigate before continuing.
php artisan migrate:status | tail -15

# What the seeder and sweeps will touch
php artisan tinker --execute='
echo "live subs on plans being retired: ", \App\Models\Subscription::whereIn("status",["active","past_due","pending"])->whereHas("plan", fn($q)=>$q->whereIn("slug",["audit-scale-monthly","audit-starter-monthly","audit-growth-monthly","audit-agency-monthly","audit-enterprise-monthly"]))->count(), PHP_EOL;
echo "pending local (offline) orders since 2026-09-05: ", \App\Models\Order::where("status","pending")->where("is_local",true)->where("created_at",">=","2026-09-05")->count(), PHP_EOL;
echo "offline provider active: ", var_export((bool) \App\Models\PaymentProvider::where("slug","offline")->value("is_active"), true), PHP_EOL;
'

# DB backup
DB_HOST=$(grep -E '^DB_HOST=' .env | cut -d= -f2-); DB_NAME=$(grep -E '^DB_DATABASE=' .env | cut -d= -f2-)
DB_USER=$(grep -E '^DB_USERNAME=' .env | cut -d= -f2-); DB_PASS=$(grep -E '^DB_PASSWORD=' .env | cut -d= -f2- | tr -d '"')
mysqldump -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" --single-transaction --routines "$DB_NAME" \
  | gzip > ~/backup-pre-partner-reselling-$(date -u +%Y%m%dT%H%M%SZ).sql.gz
ls -lh ~/backup-pre-partner-reselling-*.sql.gz
```

### Step 3 — local: deploy

From `/var/www/html/flexpick.net/backend` (`DEPLOY_HOST` defaults to `app.flexpick.net`):

```bash
php vendor/bin/dep deploy production --branch=growth-retention
```

Task order: update code → `composer install` → `storage:link` → Sentry release + `optimize` →
`migrate --force` (11 migrations) → `db:seed` → `npm ci && npm run build` → symlink →
`app:smoke` (a non-zero exit fails the deploy and rolls back) → `horizon:terminate` →
`crontab:sync` → sitemap → export-configs.

### Step 4 — server: verify

```bash
cd ~/app/current
php artisan migrate:status | tail -12                 # all "Ran"
php artisan app:smoke                                  # 9/9 PASS
php artisan schedule:list | grep -E 'cash|horizon:snapshot'
php artisan horizon:status                             # "Horizon is running" (fresh process, after_commit in effect)
php artisan tinker --execute='echo \App\Models\Plan::where("slug","like","audit-%-monthly")->where("is_active",true)->count()," active package plans (expect 10: 9 packages + audit-partner)",PHP_EOL;'

# Dry-run the sweeps — with CASH_SWEEP_FROM set to now, both must report 0:
php artisan app:expire-pending-cash-orders
php artisan app:issue-cash-renewal-orders
```

Browser checks: `/pricing` shows the 9 packages; `/privacy-policy` and `/terms-of-service` render the
legal block; dashboard sidebar shows the Billing group with "Buy More / Upgrade".

### Step 5 — Admin panel (operator actions)

- Settings → Payment Providers → **Offline**: active + enabled for new payments. Partner cash checkout
  and `PartnerPricingResolver` both require it.
- Assign the `Partner Monthly` plan to each partner tenant — `tenantIsActivePartner()` keys on it.

### Step 6 — frontend

Trigger the Ploi deploy for the `flexpick.net` static site on the same `growth-retention` commit
(pull, `npm ci`, `npm run build`, publish `dist/`). Picks up the new `pricing.json`, the price-free
landing copy, and `terms.astro`.

## Rollback

```bash
cd /var/www/html/flexpick.net/backend && php vendor/bin/dep rollback production
```

Migrations are additive, so the previous release runs on the new schema. The seeder's plan retirement is
**not** reversed by a code rollback — re-activate the old plans in Admin → Plans, or restore the dump.
