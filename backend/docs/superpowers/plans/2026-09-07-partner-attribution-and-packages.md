# Partner Attribution Cookie, Partner Navigation, and Report Packages — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One `rc` referral parameter backed by an encrypted year-long cookie and by `users.partner_tenant_id`; a Partner navigation group (Referrals, Orders, Pricing Settings) visible only to active partner tenants; a production-safe seeder for nine report packages; partner tenants locked out of change-plan; every referred customer's cash order routed to the partner for approval with email and in-app notification.

**Architecture:** The boilerplate's personal `ReferralCode` (`REF-…`) becomes the only referral code; `PartnerAttributionService` resolves it to the referrer's active partner tenant and owns the `fp_rc` cookie. `PartnerPricingResolver` reads the database for signed-in users and the cookie for anonymous visitors. New packages are new product/plan slugs seeded from `config/pricing.php`. `PurchaseSnapshotService` stamps `partner_tenant_id` for every attributed buyer so the partner's Orders resource, approval service and notifications see all of that customer's orders.

**Tech Stack:** Laravel 13, PHP 8.4, Filament 5, Livewire 4, PHPUnit 11, Larastan 3, Pint. Frontend touch: Astro 6 (`frontend/src/pages/index.astro`).

**Spec:** `backend/docs/superpowers/specs/2026-09-07-partner-attribution-and-packages-design.md` (this plan argues from it; read it first). Base spec: `backend/docs/superpowers/specs/2026-08-30-partner-reselling-cash-payments-design.md`.

## Global Constraints

- All backend commands run from `backend/`. Frontend commands from `frontend/`.
- Tests: `php artisan test --compact --filter=<Name>`; the suite shares one database across test classes (`FeatureTest` runs `migrate:fresh` once per process), so never rely on a clean table and never assume a slug is free — use `updateOrCreate`/`firstOrCreate` where a fixture needs a fixed slug.
- Another session may run tests concurrently; on a `QueryException` storm, re-run before debugging.
- Formatting gate: `vendor/bin/pint` then `vendor/bin/pint --test` (plain, never `--dirty`). Static analysis: `vendor/bin/phpstan analyse` (one pre-existing error is acceptable; no new ones).
- `git add` explicit paths only. Commit messages end with:
  ```
  Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01P2o8RvCgW2DdHAyGpQ9zm6
  ```
- Money is stored in cents. Referral codes are `REF-` + 12 uppercase chars, max cookie/param length 64.
- Cookie name `fp_rc`, lifetime 365 days (config `partner.cookie_name`, `partner.cookie_lifetime_days`). Cookie is written through Laravel's cookie queue (encrypted + MAC'd by `EncryptCookies`), `httpOnly`, `SameSite=Lax`.
- `users.partner_tenant_id` is set-once; a signed-in user's pricing reads only the database.
- Navigation group label `Partner`; items in order: `Referrals`, `Orders`, `Pricing Settings`.
- Package slugs: `audit-diagnostic-5/15/30`, `audit-deep-ai-5/15/30`, `audit-expert-5/15/30`; plan slug = product slug + `-monthly`.
- Copy: Pricing page tier headings `Diagnostic Report`, `Deep AI Code Review`, `Expert Audit`.

## File structure

**Created**
- `config/partner.php` — cookie name and lifetime.
- `database/migrations/2026_09_07_000001_drop_partner_referral_links_table.php`
- `database/migrations/2026_09_07_000002_create_notifications_table.php`
- `database/seeders/ReportPackagesSeeder.php` — nine packages + retirement + reseller flag.
- `app/Filament/Dashboard/Pages/PartnerPricingSettings.php` + `resources/views/filament/dashboard/pages/partner-pricing-settings.blade.php`
- `app/Livewire/Filament/Dashboard/PartnerPlanPricingTable.php`, `PartnerProductPricingTable.php`
- `app/Filament/Dashboard/Resources/PartnerOrders/PartnerOrderResource.php` + `Pages/ListPartnerOrders.php`, `Pages/ViewPartnerOrder.php`
- `app/Notifications/PartnerNewOrder.php`
- Tests listed per task.

**Modified**
- `app/Constants/ReferralConstants.php` (`rc`), `app/Constants/SessionConstants.php`
- `app/Http/Middleware/TrackReferralCode.php`, `bootstrap/app.php`
- `app/Services/PartnerAttributionService.php`, `PartnerPricingResolver.php`, `PartnerCapabilityService.php`, `PartnerCatalogService.php`, `ReferralService.php`, `UserService.php`, `SubscriptionService.php`, `CashPayments/PurchaseSnapshotService.php`
- `app/Listeners/User/AttributePartnerOnLogin.php`, `app/Listeners/Order/NotifyPartnerOfPendingCashOrder.php`
- `app/Providers/Filament/DashboardPanelProvider.php`
- `app/Filament/Dashboard/Resources/Referrals/ReferralResource.php`, `ReferralRewards/ReferralRewardResource.php`, `Subscriptions/Pages/ChangeSubscriptionPlan.php`, `app/Http/Controllers/SubscriptionController.php`
- `app/Filament/Dashboard/Widgets/ReferralLinkWidget.php`, `ReferralStatsWidget.php`, `resources/views/filament/dashboard/widgets/referral-link-widget.blade.php`
- `app/View/Components/Plans/All.php`, `resources/views/components/plans/all.blade.php`, `resources/views/components/filament/plans/all.blade.php`, `resources/css/flexpick-brand.css`
- `config/pricing.php`, `database/seeders/AuditMonetizationSeeder.php`, `database/seeders/DatabaseSeeder.php`, `app/Console/Commands/ExportPricingCommand.php`, `frontend/src/data/pricing.json`, `frontend/src/pages/index.astro`

**Deleted**
- `app/Http/Middleware/TrackPartnerReferralCode.php`, `app/Constants/PartnerConstants.php`
- `app/Models/PartnerReferralLink.php`, `database/factories/PartnerReferralLinkFactory.php`
- `app/Filament/Dashboard/Resources/PartnerPlanCatalog/`, `PartnerProductCatalog/`, `PartnerOrderApprovals/` (the last is renamed to `PartnerOrders/`)
- Tests: `TrackPartnerReferralCodeTest`, `PartnerReferralLinkTest`, `PartnerPlanCatalogResourceTest`, `PartnerProductCatalogResourceTest`, `PartnerOrderApprovalResourceTest` (renamed).

---

### Task 1: The `rc` parameter and `config/partner.php`

**Files:**
- Modify: `app/Constants/ReferralConstants.php:7`
- Create: `config/partner.php`
- Modify: `.env.example` (append two lines)
- Modify: `tests/Feature/Referral/ReferralFlowTest.php:33,47,302`

**Interfaces:**
- Produces: `ReferralConstants::HTTP_PARAM_REFERRAL_CODE === 'rc'`; `config('partner.cookie_name')` (string, default `fp_rc`), `config('partner.cookie_lifetime_days')` (int, default 365).

- [ ] **Step 1: Update the referral flow tests to expect `rc`**

In `tests/Feature/Referral/ReferralFlowTest.php` change line 33 and line 47 from `'/login?referralCode='.$referralCode->code` to `'/login?rc='.$referralCode->code`, and line 302 from `assertStringContainsString('referralCode=', $link)` to `assertStringContainsString('rc=', $link)`. Add one assertion right after line 302:

```php
        $this->assertStringNotContainsString('referralCode', $link);
```

- [ ] **Step 2: Run them to see the failures**

Run: `php artisan test --compact --filter=ReferralFlowTest`
Expected: FAIL on `test_referral_code_is_stored_in_session_from_url`, `test_referral_code_persists_in_session_across_requests`, `test_referral_link_contains_correct_format`.

- [ ] **Step 3: Change the constant and add the config**

`app/Constants/ReferralConstants.php` line 7:

```php
    public const HTTP_PARAM_REFERRAL_CODE = 'rc';
```

Create `config/partner.php`:

```php
<?php

return [
    // The encrypted, httpOnly cookie that remembers which partner referred an
    // anonymous visitor (spec §3.3). Registered users are attributed in the
    // database; the cookie is only re-issued from that record after login.
    'cookie_name' => env('PARTNER_COOKIE_NAME', 'fp_rc'),
    'cookie_lifetime_days' => (int) env('PARTNER_COOKIE_LIFETIME_DAYS', 365),
];
```

Append to `.env.example`:

```
PARTNER_COOKIE_NAME=fp_rc
PARTNER_COOKIE_LIFETIME_DAYS=365
```

- [ ] **Step 4: Run the referral tests and the middleware test**

Run: `php artisan test --compact --filter="ReferralFlowTest|TrackReferralCodeTest"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Constants/ReferralConstants.php config/partner.php .env.example tests/Feature/Referral/ReferralFlowTest.php
git commit -m "feat(partner): rename the referral parameter to rc and add partner cookie config"
```

---

### Task 2: `PartnerAttributionService` resolves personal codes and owns the cookie

**Files:**
- Modify: `app/Services/PartnerAttributionService.php` (rewrite)
- Rewrite: `tests/Feature/Services/PartnerAttributionServiceTest.php`

**Interfaces:**
- Consumes: `PartnerCapabilityService::tenantIsActivePartner(Tenant): bool`; `ReferralService::getOrCreateReferralCode(User): ReferralCode` (resolved lazily via `app()` — `ReferralService` will depend on nothing partner-related, and Task 6 must keep it that way to avoid a container cycle).
- Produces (used by Tasks 3, 4, 6, 7):
  - `resolveTenantForCode(string $code): ?Tenant` — the referrer's lowest-id tenant that is an active partner, else null.
  - `cookieCode(?Request $request = null): ?string` — the decrypted `fp_rc` value, validated (string, non-empty, ≤64 chars).
  - `hasPartnerCookie(?Request $request = null): bool` — cookie present AND resolves to a partner.
  - `queueCookie(string $code): void` — queues the encrypted cookie for the response.
  - `attribute(User $user, PartnerAttributionSource $source): void` — set-once DB attribution from the cookie.
  - `refreshCookieFromDatabase(User $user): void` — re-issues the cookie for the user's DB partner, if any.
  - `codeForTenant(Tenant $tenant): ?string` — a personal code that resolves to this tenant.
  - `cookieName(): string`.

- [ ] **Step 1: Rewrite the test file**

Replace `tests/Feature/Services/PartnerAttributionServiceTest.php` with:

```php
<?php

namespace Tests\Feature\Services;

use App\Constants\PartnerAttributionSource;
use App\Constants\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PartnerAttributionService;
use App\Services\ReferralService;
use Illuminate\Support\Facades\Cookie;
use Tests\Feature\FeatureTest;

class PartnerAttributionServiceTest extends FeatureTest
{
    private function service(): PartnerAttributionService
    {
        return app(PartnerAttributionService::class);
    }

    private function activePartnerTenant(): Tenant
    {
        $tenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);

        return $tenant;
    }

    /** @return array{0: Tenant, 1: User, 2: string} tenant, member, member's personal code */
    private function partnerWithCode(): array
    {
        $tenant = $this->activePartnerTenant();
        $member = $this->createUser($tenant);
        $code = app(ReferralService::class)->getOrCreateReferralCode($member)->code;

        return [$tenant, $member, $code];
    }

    private function setRequestCookie(?string $value): void
    {
        $this->app['request']->cookies->set(config('partner.cookie_name'), $value);
    }

    public function test_a_members_personal_code_resolves_to_their_active_partner_tenant(): void
    {
        [$tenant, , $code] = $this->partnerWithCode();

        $this->assertTrue($this->service()->resolveTenantForCode($code)->is($tenant));
    }

    public function test_a_code_of_a_user_with_no_partner_tenant_resolves_to_null(): void
    {
        $tenant = $this->createTenant();
        $member = $this->createUser($tenant);
        $code = app(ReferralService::class)->getOrCreateReferralCode($member)->code;

        $this->assertNull($this->service()->resolveTenantForCode($code));
    }

    public function test_an_unknown_code_resolves_to_null(): void
    {
        $this->assertNull($this->service()->resolveTenantForCode('REF-DOESNOTEXIST'));
    }

    public function test_a_lapsed_partner_tenant_does_not_resolve(): void
    {
        $tenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->subDay(),
        ]);
        $member = $this->createUser($tenant);
        $code = app(ReferralService::class)->getOrCreateReferralCode($member)->code;

        $this->assertNull($this->service()->resolveTenantForCode($code));
    }

    public function test_a_member_of_two_partner_tenants_resolves_to_the_lowest_id(): void
    {
        $first = $this->activePartnerTenant();
        $second = $this->activePartnerTenant();
        $member = $this->createUser($first);
        $second->users()->attach($member);
        $code = app(ReferralService::class)->getOrCreateReferralCode($member)->code;

        $this->assertTrue($this->service()->resolveTenantForCode($code)->is($first));
    }

    public function test_cookie_code_reads_a_valid_value_and_rejects_junk(): void
    {
        $this->setRequestCookie('REF-ABCDEFGHIJKL');
        $this->assertSame('REF-ABCDEFGHIJKL', $this->service()->cookieCode());

        $this->setRequestCookie('');
        $this->assertNull($this->service()->cookieCode());

        $this->setRequestCookie(str_repeat('x', 65));
        $this->assertNull($this->service()->cookieCode());

        $this->app['request']->cookies->remove(config('partner.cookie_name'));
        $this->assertNull($this->service()->cookieCode());
    }

    public function test_has_partner_cookie_requires_the_code_to_resolve(): void
    {
        [, , $code] = $this->partnerWithCode();

        $this->setRequestCookie('REF-NOTAPARTNER0');
        $this->assertFalse($this->service()->hasPartnerCookie());

        $this->setRequestCookie($code);
        $this->assertTrue($this->service()->hasPartnerCookie());
    }

    public function test_queue_cookie_queues_an_http_only_year_long_cookie(): void
    {
        $this->service()->queueCookie('REF-QUEUEDCODE1');

        $cookie = Cookie::queued(config('partner.cookie_name'));

        $this->assertNotNull($cookie);
        $this->assertSame('REF-QUEUEDCODE1', $cookie->getValue());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertEqualsWithDelta(now()->addDays(365)->getTimestamp(), $cookie->getExpiresTime(), 120);
    }

    public function test_attribute_sets_the_partner_from_the_cookie_once(): void
    {
        [$tenant, , $code] = $this->partnerWithCode();
        $this->setRequestCookie($code);
        $user = User::factory()->create();

        $this->service()->attribute($user, PartnerAttributionSource::REGISTRATION);

        $user->refresh();
        $this->assertTrue($user->partnerTenant->is($tenant));
        $this->assertSame(PartnerAttributionSource::REGISTRATION->value, $user->partner_attribution_source);
        $this->assertNotNull($user->partner_attributed_at);
    }

    public function test_attribute_does_not_overwrite_an_existing_attribution(): void
    {
        $original = $this->createTenant();
        [, , $otherCode] = $this->partnerWithCode();
        $this->setRequestCookie($otherCode);
        $user = User::factory()->create(['partner_tenant_id' => $original->id]);

        $this->service()->attribute($user, PartnerAttributionSource::LOGIN);

        $this->assertSame($original->id, $user->fresh()->partner_tenant_id);
    }

    public function test_attribute_ignores_a_cookie_that_does_not_resolve(): void
    {
        $this->setRequestCookie('REF-NOTAPARTNER0');
        $user = User::factory()->create();

        $this->service()->attribute($user, PartnerAttributionSource::REGISTRATION);

        $this->assertNull($user->fresh()->partner_tenant_id);
    }

    public function test_attribute_is_a_no_op_without_a_cookie(): void
    {
        $this->app['request']->cookies->remove(config('partner.cookie_name'));
        $user = User::factory()->create();

        $this->service()->attribute($user, PartnerAttributionSource::LOGIN);

        $this->assertNull($user->fresh()->partner_tenant_id);
    }

    public function test_the_first_concurrent_attribution_wins(): void
    {
        $winner = $this->activePartnerTenant();
        [, , $loserCode] = $this->partnerWithCode();
        $user = User::factory()->create();

        User::whereKey($user->id)->update([
            'partner_tenant_id' => $winner->id,
            'partner_attributed_at' => now(),
            'partner_attribution_source' => PartnerAttributionSource::REGISTRATION->value,
        ]);

        // $user is stale in memory (partner_tenant_id null), like a second request
        // that read the row before the first write landed.
        $this->setRequestCookie($loserCode);
        $this->service()->attribute($user, PartnerAttributionSource::LOGIN);

        $this->assertSame($winner->id, $user->fresh()->partner_tenant_id);
    }

    public function test_code_for_tenant_returns_a_code_that_resolves_back_to_it(): void
    {
        $tenant = $this->activePartnerTenant();
        $this->createUser($tenant); // no personal code yet — it must be created on demand

        $code = $this->service()->codeForTenant($tenant);

        $this->assertNotNull($code);
        $this->assertTrue($this->service()->resolveTenantForCode($code)->is($tenant));
    }

    public function test_code_for_tenant_is_null_for_a_non_partner_or_memberless_tenant(): void
    {
        $plain = $this->createTenant();
        $this->createUser($plain);
        $this->assertNull($this->service()->codeForTenant($plain));

        $empty = $this->activePartnerTenant();
        $this->assertNull($this->service()->codeForTenant($empty));
    }

    public function test_refresh_cookie_rewrites_the_cookie_from_the_database(): void
    {
        [$tenant] = $this->partnerWithCode();
        [, , $strayCode] = $this->partnerWithCode();
        $user = User::factory()->create(['partner_tenant_id' => $tenant->id]);
        $this->setRequestCookie($strayCode);

        $this->service()->refreshCookieFromDatabase($user);

        $queued = Cookie::queued(config('partner.cookie_name'));
        $this->assertNotNull($queued);
        $this->assertTrue($this->service()->resolveTenantForCode($queued->getValue())->is($tenant));
        $this->assertNotSame($strayCode, $queued->getValue());
    }

    public function test_refresh_cookie_leaves_an_unattributed_user_alone(): void
    {
        $user = User::factory()->create();

        $this->service()->refreshCookieFromDatabase($user);

        $this->assertNull(Cookie::queued(config('partner.cookie_name')));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=PartnerAttributionServiceTest`
Expected: FAIL — `cookieCode`, `hasPartnerCookie`, `queueCookie`, `codeForTenant`, `refreshCookieFromDatabase` undefined; `resolveTenantForCode` returns null for personal codes.

- [ ] **Step 3: Rewrite the service**

Replace `app/Services/PartnerAttributionService.php` with:

```php
<?php

namespace App\Services;

use App\Constants\PartnerAttributionSource;
use App\Models\ReferralCode;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Who referred this visitor, and how that answer is remembered (spec §3).
 *
 * The code in a partner link is the referrer's personal ReferralCode. It
 * resolves to a partner *tenant* through the referrer's memberships. Before
 * registration the answer lives only in the fp_rc cookie; from registration
 * on it lives in users.partner_tenant_id, and the cookie is merely re-issued
 * from that row so a logged-out browser keeps the partner's prices.
 */
class PartnerAttributionService
{
    private const MAX_CODE_LENGTH = 64;

    public function __construct(
        private PartnerCapabilityService $partnerCapabilityService,
    ) {}

    public function cookieName(): string
    {
        return (string) config('partner.cookie_name', 'fp_rc');
    }

    /**
     * The referrer's active partner tenant. When the referrer belongs to
     * several active partner tenants the lowest id wins — a deterministic
     * choice for a configuration that is not supported (spec §3.2).
     */
    public function resolveTenantForCode(string $code): ?Tenant
    {
        /** @var User|null $referrer */
        $referrer = ReferralCode::where('code', $code)->first()?->user;

        if ($referrer === null) {
            return null;
        }

        /** @var Tenant|null $tenant */
        $tenant = $referrer->tenants()
            ->orderBy('tenants.id')
            ->get()
            ->first(fn (Tenant $candidate): bool => $this->partnerCapabilityService->tenantIsActivePartner($candidate));

        return $tenant;
    }

    /**
     * EncryptCookies has already decrypted and MAC-checked the value by the
     * time any service reads it; a forged or edited cookie never gets here.
     */
    public function cookieCode(?Request $request = null): ?string
    {
        $code = ($request ?? request())->cookie($this->cookieName());

        return $this->isPlausibleCode($code) ? $code : null;
    }

    public function hasPartnerCookie(?Request $request = null): bool
    {
        $code = $this->cookieCode($request);

        return $code !== null && $this->resolveTenantForCode($code) !== null;
    }

    public function queueCookie(string $code): void
    {
        Cookie::queue(cookie(
            name: $this->cookieName(),
            value: $code,
            minutes: (int) config('partner.cookie_lifetime_days', 365) * 24 * 60,
            path: '/',
            domain: null,
            secure: request()->isSecure() ? true : null,
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        ));
    }

    /**
     * Set-once. The conditional UPDATE is the race guard: two requests can
     * both read partner_tenant_id as null, but only one WHERE-null update
     * lands, and the loser leaves the row alone.
     */
    public function attribute(User $user, PartnerAttributionSource $source): void
    {
        if ($user->partner_tenant_id !== null) {
            return;
        }

        $code = $this->cookieCode();

        if ($code === null) {
            return;
        }

        $tenant = $this->resolveTenantForCode($code);

        if ($tenant === null) {
            return;
        }

        $attributes = [
            'partner_tenant_id' => $tenant->id,
            'partner_attributed_at' => now(),
            'partner_attribution_source' => $source->value,
        ];

        $updated = User::whereKey($user->id)
            ->whereNull('partner_tenant_id')
            ->update($attributes);

        if ($updated === 0) {
            return;
        }

        $user->forceFill($attributes);
    }

    /**
     * After registration and every login: the database is the truth, so the
     * cookie is rewritten from it (spec §3.4). A user with no partner keeps
     * whatever cookie they have — there is nothing better to say.
     */
    public function refreshCookieFromDatabase(User $user): void
    {
        /** @var Tenant|null $tenant */
        $tenant = $user->partnerTenant;

        if ($tenant === null) {
            return;
        }

        $code = $this->codeForTenant($tenant);

        if ($code !== null) {
            $this->queueCookie($code);
        }
    }

    /**
     * A personal code that resolves back to this tenant: the lowest-id
     * member whose code does. ReferralService is resolved lazily because it
     * will itself consult attribution state (Task 6) and must not be a
     * constructor dependency in both directions.
     */
    public function codeForTenant(Tenant $tenant): ?string
    {
        if (! $this->partnerCapabilityService->tenantIsActivePartner($tenant)) {
            return null;
        }

        $referralService = app(ReferralService::class);

        foreach ($tenant->users()->orderBy('users.id')->get() as $member) {
            /** @var User $member */
            $code = $referralService->getOrCreateReferralCode($member)->code;

            if ($this->resolveTenantForCode($code)?->is($tenant)) {
                return $code;
            }
        }

        return null;
    }

    private function isPlausibleCode(mixed $code): bool
    {
        return is_string($code) && $code !== '' && mb_strlen($code) <= self::MAX_CODE_LENGTH;
    }
}
```

- [ ] **Step 4: Run the test**

Run: `php artisan test --compact --filter=PartnerAttributionServiceTest`
Expected: PASS (16 tests). If `test_queue_cookie_queues_an_http_only_year_long_cookie` fails on `getSameSite()`, Symfony returns `'lax'` lowercase — keep the assertion; fix the service, not the test.

- [ ] **Step 5: Confirm nothing else still calls the removed methods**

Run: `grep -rn "rememberPendingCode\|pendingCode()\|clearPendingCode\|pendingCodeConflictsWithExisting" app`
Expected: hits only in `app/Services/PartnerPricingResolver.php` (fixed in Task 4). Any other hit is a bug in this task.

- [ ] **Step 6: Commit**

```bash
git add app/Services/PartnerAttributionService.php tests/Feature/Services/PartnerAttributionServiceTest.php
git commit -m "feat(partner): resolve personal referral codes to partner tenants and own the fp_rc cookie"
```

Note: the suite is temporarily red between Tasks 2 and 4 (`PartnerPricingResolver`, `UserServiceTest`, `AttributePartnerOnLoginTest`, `PartnerPricingResolverTest` reference removed APIs). That is expected; do not "fix" them here.

---
### Task 3: `TrackReferralCode` sets the partner cookie; the old partner middleware goes away

**Files:**
- Modify: `app/Http/Middleware/TrackReferralCode.php` (rewrite)
- Delete: `app/Http/Middleware/TrackPartnerReferralCode.php`, `app/Constants/PartnerConstants.php`, `tests/Feature/Http/Middleware/TrackPartnerReferralCodeTest.php`
- Modify: `app/Constants/SessionConstants.php:23` (remove `PARTNER_REFERRAL_CODE`), `bootstrap/app.php:8,38`
- Modify: `tests/Feature/Http/Middleware/TrackReferralCodeTest.php` (add tests)
- Modify: spec §3.3 (one sentence, see Step 6)

**Interfaces:**
- Consumes: `PartnerAttributionService::hasPartnerCookie(Request)`, `resolveTenantForCode(string)`, `queueCookie(string)`; `ReferralService::isEnabled()`.
- Produces: on `GET …?rc=<code>` with referrals enabled: session `SessionConstants::REFERRAL_CODE` = code (unchanged), plus an `fp_rc` cookie when the visitor is a guest, the code resolves to a partner, and no partner cookie exists yet.

- [ ] **Step 1: Add the failing tests**

Append to `tests/Feature/Http/Middleware/TrackReferralCodeTest.php` (inside the class; add the imports `App\Constants\SubscriptionStatus`, `App\Models\Plan`, `App\Models\Product`, `App\Models\Subscription`, `App\Models\Tenant`, `App\Services\ReferralService` at the top):

```php
    private function partnerCode(): string
    {
        $tenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);
        $member = $this->createUser($tenant);

        return app(ReferralService::class)->getOrCreateReferralCode($member)->code;
    }

    public function test_a_partner_code_sets_the_attribution_cookie_for_a_guest(): void
    {
        $this->withExceptionHandling();
        config(['app.referral.enabled' => true]);
        $code = $this->partnerCode();

        $response = $this->get('/login?rc='.$code);

        $response->assertSessionHas(SessionConstants::REFERRAL_CODE, $code);
        $response->assertCookie(config('partner.cookie_name'), $code);
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === config('partner.cookie_name'));
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertEqualsWithDelta(now()->addDays(365)->getTimestamp(), $cookie->getExpiresTime(), 120);
    }

    public function test_a_non_partner_code_sets_no_cookie(): void
    {
        $this->withExceptionHandling();
        config(['app.referral.enabled' => true]);
        $referrer = User::factory()->create();
        $code = app(ReferralService::class)->getOrCreateReferralCode($referrer)->code;

        $response = $this->get('/login?rc='.$code);

        $response->assertSessionHas(SessionConstants::REFERRAL_CODE, $code);
        $response->assertCookieMissing(config('partner.cookie_name'));
    }

    public function test_an_existing_partner_cookie_is_never_overwritten(): void
    {
        $this->withExceptionHandling();
        config(['app.referral.enabled' => true]);
        $first = $this->partnerCode();
        $second = $this->partnerCode();

        $response = $this->withCookie(config('partner.cookie_name'), $first)->get('/login?rc='.$second);

        // Nothing queued: the browser keeps the first partner's cookie.
        $response->assertCookieMissing(config('partner.cookie_name'));
        $response->assertSessionHas(SessionConstants::REFERRAL_CODE, $second);
    }

    public function test_a_cookie_that_no_longer_resolves_can_be_replaced(): void
    {
        $this->withExceptionHandling();
        config(['app.referral.enabled' => true]);
        $code = $this->partnerCode();

        $response = $this->withCookie(config('partner.cookie_name'), 'REF-LAPSEDPARTNER')->get('/login?rc='.$code);

        $response->assertCookie(config('partner.cookie_name'), $code);
    }

    public function test_a_signed_in_user_never_receives_the_cookie(): void
    {
        $this->withExceptionHandling();
        config(['app.referral.enabled' => true]);
        $code = $this->partnerCode();
        $user = $this->createUser($this->createTenant());

        $response = $this->actingAs($user)->get('/?rc='.$code);

        $response->assertCookieMissing(config('partner.cookie_name'));
    }

    public function test_the_old_parameter_names_are_ignored(): void
    {
        $this->withExceptionHandling();
        config(['app.referral.enabled' => true]);
        $code = $this->partnerCode();

        $response = $this->get('/login?referralCode='.$code.'&partnerCode='.$code);

        $response->assertSessionMissing(SessionConstants::REFERRAL_CODE);
        $response->assertCookieMissing(config('partner.cookie_name'));
    }

    public function test_an_array_or_overlong_value_is_ignored(): void
    {
        $this->withExceptionHandling();
        config(['app.referral.enabled' => true]);

        $this->get('/login?rc[]=x')->assertSessionMissing(SessionConstants::REFERRAL_CODE);
        $this->get('/login?rc='.str_repeat('a', 65))->assertSessionMissing(SessionConstants::REFERRAL_CODE);
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --compact --filter=TrackReferralCodeTest`
Expected: the cookie tests FAIL (no cookie set); `test_an_array_or_overlong_value_is_ignored` may error on `session([... => ['x']])` — also a failure to fix.

- [ ] **Step 3: Rewrite the middleware and remove the old one**

`app/Http/Middleware/TrackReferralCode.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Constants\ReferralConstants;
use App\Constants\SessionConstants;
use App\Services\PartnerAttributionService;
use App\Services\ReferralService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One `rc` parameter drives both referral systems (spec §3.1, §3.3): the
 * session copy feeds the personal Referral row at registration; the cookie
 * remembers a partner for an anonymous visitor. First touch wins for the
 * cookie, and a signed-in user is attributed by the database, never here.
 */
class TrackReferralCode
{
    private const MAX_CODE_LENGTH = 64;

    public function __construct(
        private ReferralService $referralService,
        private PartnerAttributionService $partnerAttributionService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->referralService->isEnabled() || ! $request->has(ReferralConstants::HTTP_PARAM_REFERRAL_CODE)) {
            return $next($request);
        }

        $code = $request->input(ReferralConstants::HTTP_PARAM_REFERRAL_CODE);

        if (! is_string($code) || $code === '' || mb_strlen($code) > self::MAX_CODE_LENGTH) {
            return $next($request);
        }

        session([SessionConstants::REFERRAL_CODE => $code]);

        if (
            auth()->guest()
            && ! $this->partnerAttributionService->hasPartnerCookie($request)
            && $this->partnerAttributionService->resolveTenantForCode($code) !== null
        ) {
            $this->partnerAttributionService->queueCookie($code);
        }

        return $next($request);
    }
}
```

Delete `app/Http/Middleware/TrackPartnerReferralCode.php`, `app/Constants/PartnerConstants.php`, `tests/Feature/Http/Middleware/TrackPartnerReferralCodeTest.php`. In `bootstrap/app.php` remove the `use App\Http\Middleware\TrackPartnerReferralCode;` line and the `TrackPartnerReferralCode::class,` entry. In `app/Constants/SessionConstants.php` delete the `PARTNER_REFERRAL_CODE` constant (and the blank line before it).

- [ ] **Step 4: Run the middleware tests**

Run: `php artisan test --compact --filter=TrackReferralCodeTest`
Expected: PASS (13 tests).

- [ ] **Step 5: Grep for leftovers**

Run: `grep -rn "PartnerConstants\|TrackPartnerReferralCode\|PARTNER_REFERRAL_CODE" app bootstrap config routes resources`
Expected: no output. (Tests still referencing `PARTNER_REFERRAL_CODE` are rewritten in Task 4.)

- [ ] **Step 6: Record the referral-enabled gate in the spec**

In the spec's §3.3, after the numbered list, add: "All three steps are skipped while the admin Referral Settings switch (`app.referral.enabled`) is off; that switch is the single on/off for partner links too."

- [ ] **Step 7: Commit**

```bash
git add app/Http/Middleware/TrackReferralCode.php bootstrap/app.php app/Constants/SessionConstants.php tests/Feature/Http/Middleware/TrackReferralCodeTest.php docs/superpowers/specs/2026-09-07-partner-attribution-and-packages-design.md
git rm -q app/Http/Middleware/TrackPartnerReferralCode.php app/Constants/PartnerConstants.php tests/Feature/Http/Middleware/TrackPartnerReferralCodeTest.php
git commit -m "feat(partner): set the fp_rc cookie from rc and retire the partnerCode middleware"
```

---

### Task 4: Attribution at registration and login refreshes the cookie; the resolver reads the cookie

**Files:**
- Modify: `app/Services/UserService.php:32`
- Modify: `app/Listeners/User/AttributePartnerOnLogin.php`
- Modify: `app/Services/PartnerPricingResolver.php:183-207` (`computePartnerTenant` docblock, `tenantFromPendingCode`)
- Modify: `tests/Feature/Listeners/AttributePartnerOnLoginTest.php` (rewrite), `tests/Feature/Services/UserServiceTest.php:52-79`, `tests/Feature/Services/PartnerPricingResolverTest.php:13,140-186`

**Interfaces:**
- Consumes: `PartnerAttributionService::attribute()`, `refreshCookieFromDatabase()`, `cookieCode()`, `resolveTenantForCode()`.
- Produces: `PartnerPricingResolver::resolvePartnerTenant(null)` reads the cookie for anonymous visitors.

- [ ] **Step 1: Rewrite the login listener test**

Replace `tests/Feature/Listeners/AttributePartnerOnLoginTest.php` with:

```php
<?php

namespace Tests\Feature\Listeners;

use App\Constants\PartnerAttributionSource;
use App\Constants\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PartnerAttributionService;
use App\Services\ReferralService;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Cookie;
use Tests\Feature\FeatureTest;

class AttributePartnerOnLoginTest extends FeatureTest
{
    /** @return array{0: Tenant, 1: string} */
    private function partnerWithCode(): array
    {
        $tenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);
        $member = $this->createUser($tenant);

        return [$tenant, app(ReferralService::class)->getOrCreateReferralCode($member)->code];
    }

    public function test_login_attributes_an_unattributed_user_from_the_cookie(): void
    {
        [$tenant, $code] = $this->partnerWithCode();
        $this->app['request']->cookies->set(config('partner.cookie_name'), $code);
        $user = User::factory()->create();

        event(new Login('web', $user, false));

        $this->assertTrue($user->fresh()->partnerTenant->is($tenant));
        $this->assertSame(PartnerAttributionSource::LOGIN->value, $user->fresh()->partner_attribution_source);
    }

    public function test_login_reissues_the_cookie_from_the_database_over_a_stray_cookie(): void
    {
        [$tenant] = $this->partnerWithCode();
        [, $strayCode] = $this->partnerWithCode();
        $this->app['request']->cookies->set(config('partner.cookie_name'), $strayCode);
        $user = User::factory()->create(['partner_tenant_id' => $tenant->id]);

        event(new Login('web', $user, false));

        $this->assertSame($tenant->id, $user->fresh()->partner_tenant_id);
        $queued = Cookie::queued(config('partner.cookie_name'));
        $this->assertNotNull($queued);
        $this->assertTrue(app(PartnerAttributionService::class)->resolveTenantForCode($queued->getValue())->is($tenant));
    }

    public function test_login_of_a_direct_customer_queues_no_cookie(): void
    {
        $user = User::factory()->create();

        event(new Login('web', $user, false));

        $this->assertNull($user->fresh()->partner_tenant_id);
        $this->assertNull(Cookie::queued(config('partner.cookie_name')));
    }
}
```

- [ ] **Step 2: Update the registration test**

In `tests/Feature/Services/UserServiceTest.php`: remove the `use App\Models\PartnerReferralLink;` import and the `App\Constants\SessionConstants` import if it becomes unused; add `use App\Services\ReferralService;` and `use Illuminate\Support\Facades\Cookie;`. Replace the body of `test_creating_a_user_attributes_a_pending_partner_code` (rename it `test_creating_a_user_attributes_the_cookie_partner_and_reissues_the_cookie`) with:

```php
        $partnerTenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        Subscription::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);
        $member = $this->createUser($partnerTenant);
        $code = app(ReferralService::class)->getOrCreateReferralCode($member)->code;
        $this->app['request']->cookies->set(config('partner.cookie_name'), $code);

        $user = app(UserService::class)->createUser([
            'name' => 'Jane Doe',
            'email' => 'jane-'.uniqid().'@example.com',
            'password' => 'password',
        ]);

        $this->assertTrue($user->partnerTenant->is($partnerTenant));
        $this->assertSame(PartnerAttributionSource::REGISTRATION->value, $user->partner_attribution_source);
        $this->assertSame($code, Cookie::queued(config('partner.cookie_name'))?->getValue());
```

- [ ] **Step 3: Update the two resolver tests**

In `tests/Feature/Services/PartnerPricingResolverTest.php`: remove the `App\Models\PartnerReferralLink` and `App\Constants\SessionConstants` imports; add `use App\Services\ReferralService;`. Add a helper next to `attributedUser()`:

```php
    /** A personal code, held by a member of $partnerTenant, planted in the request cookie. */
    private function plantPartnerCookie(Tenant $partnerTenant): string
    {
        $member = $this->createUser($partnerTenant);
        $code = app(ReferralService::class)->getOrCreateReferralCode($member)->code;
        $this->app['request']->cookies->set(config('partner.cookie_name'), $code);

        return $code;
    }
```

Rewrite `test_an_anonymous_visitor_with_a_session_code_gets_the_partner_price` as `test_an_anonymous_visitor_with_a_partner_cookie_gets_the_partner_price`:

```php
    public function test_an_anonymous_visitor_with_a_partner_cookie_gets_the_partner_price(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        [$plan] = $this->sellablePlan($partnerTenant);

        $this->plantPartnerCookie($partnerTenant);
        $this->resolver()->flush();

        $this->assertSame(7900, $this->resolver()->planPrice(null, $plan));
    }
```

Rewrite `test_a_logged_in_unattributed_user_ignores_a_session_referral_code` as `test_a_logged_in_unattributed_user_ignores_the_partner_cookie` — same body but replace the `PartnerReferralLink::factory()…` and `session([...])` lines with `$this->plantPartnerCookie($partnerTenant);`, and replace the trailing `session()->forget(...)` with `$this->app['request']->cookies->remove(config('partner.cookie_name'));`. Update its docblock's last sentence to: "users.partner_tenant_id is the whole answer for an authenticated buyer; the cookie prices anonymous visitors only."

- [ ] **Step 4: Run the three files to see them fail**

Run: `php artisan test --compact --filter="AttributePartnerOnLoginTest|UserServiceTest|PartnerPricingResolverTest"`
Expected: FAIL — `pendingCode()` undefined in the resolver, no cookie queued after login/registration.

- [ ] **Step 5: Implement**

`app/Services/UserService.php` — replace line 32 with:

```php
        $this->partnerAttributionService->attribute($user, PartnerAttributionSource::REGISTRATION);
        $this->partnerAttributionService->refreshCookieFromDatabase($user);
```

`app/Listeners/User/AttributePartnerOnLogin.php` — `handle()` becomes:

```php
    public function handle(Login $event): void
    {
        /** @var \App\Models\User $user */
        $user = $event->user;

        $this->partnerAttributionService->attribute($user, PartnerAttributionSource::LOGIN);
        $this->partnerAttributionService->refreshCookieFromDatabase($user);
    }
```

`app/Services/PartnerPricingResolver.php` — rename `tenantFromPendingCode()` to `tenantFromCookie()` (and its one call site in `computePartnerTenant`):

```php
    private function tenantFromCookie(): ?Tenant
    {
        $code = $this->attributionService->cookieCode();

        return $code === null ? null : $this->attributionService->resolveTenantForCode($code);
    }
```

Replace the `computePartnerTenant` docblock with:

```php
    /**
     * The cookie is a fallback for anonymous visitors only.
     *
     * An authenticated user's answer is users.partner_tenant_id and nothing
     * else, even when it is null. Attribution happens at exactly two places —
     * UserService (registration) and AttributePartnerOnLogin (login) — and both
     * re-issue the cookie from that row afterwards, so for a signed-in user the
     * cookie can never say anything the database does not (spec §3.4, §3.5).
     */
```

Also update the class docblock bullet "an anonymous visitor through an active referral code in session" to "an anonymous visitor through the fp_rc cookie".

- [ ] **Step 6: Run the three files plus the whole partner/referral slice**

Run: `php artisan test --compact --filter="AttributePartnerOnLoginTest|UserServiceTest|PartnerPricingResolverTest|PartnerAttributionServiceTest|TrackReferralCodeTest|ReferralFlowTest"`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Services/UserService.php app/Listeners/User/AttributePartnerOnLogin.php app/Services/PartnerPricingResolver.php tests/Feature/Listeners/AttributePartnerOnLoginTest.php tests/Feature/Services/UserServiceTest.php tests/Feature/Services/PartnerPricingResolverTest.php
git commit -m "feat(partner): attribute from the cookie at registration and login, then reissue it from the database"
```

---

### Task 5: Drop `PartnerReferralLink`

**Files:**
- Delete: `app/Models/PartnerReferralLink.php`, `database/factories/PartnerReferralLinkFactory.php`, `tests/Feature/Models/PartnerReferralLinkTest.php`
- Create: `database/migrations/2026_09_07_000001_drop_partner_referral_links_table.php`
- Modify: `app/Models/Tenant.php` only if it declares a `partnerReferralLinks()` relation (check with grep; the read in exploration showed none).

- [ ] **Step 1: Grep for every remaining reference**

Run: `grep -rn "PartnerReferralLink\|partner_referral_links" app database tests config --include=*.php`
Expected: only the model, factory, its test, and the 2026_08_30 create migration. Anything else must be removed in this step.

- [ ] **Step 2: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partner links are the referrer's personal ReferralCode now (spec §3.1);
 * this table never had a producer outside tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('partner_referral_links');
    }

    public function down(): void
    {
        Schema::create('partner_referral_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }
};
```

- [ ] **Step 3: Delete the files and run the partner slice**

```bash
git rm -q app/Models/PartnerReferralLink.php database/factories/PartnerReferralLinkFactory.php tests/Feature/Models/PartnerReferralLinkTest.php
php artisan test --compact --filter="Partner|Referral|UserServiceTest"
```
Expected: PASS. Then `vendor/bin/phpstan analyse` — no new errors.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/2026_09_07_000001_drop_partner_referral_links_table.php
git commit -m "refactor(partner): drop the unused partner_referral_links table and model"
```

---
### Task 6: Partner referrals earn no coupon reward

**Files:**
- Modify: `app/Services/ReferralService.php:190-204` (`processReward`)
- Modify: `tests/Feature/Referral/ReferralFlowTest.php` (add two tests)

**Interfaces:**
- Consumes: `Referral::referredUser` relation, `users.partner_tenant_id`.
- Produces: `processReward()` returns early when the referred user is partner-attributed; the `Referral` keeps its `verified`/`paid` status and no `ReferralReward` row is created.

- [ ] **Step 1: Add the failing tests**

Append to `tests/Feature/Referral/ReferralFlowTest.php` (add imports `App\Models\ReferralReward`, `App\Models\Plan`, `App\Models\Product`, `App\Constants\SubscriptionStatus` if missing):

```php
    public function test_a_partner_attributed_referral_is_recorded_but_never_rewarded(): void
    {
        Mail::fake();
        config([
            'app.referral.enabled' => true,
            'app.referral.trigger' => ReferralConstants::TRIGGER_VERIFIED_REGISTRATION,
            'app.referral.reward_type' => ReferralConstants::REWARD_TYPE_CUSTOM_EVENT,
        ]);
        Event::fake([ReferralSucceeded::class]);

        $partnerTenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        Subscription::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => Plan::factory()->create(['product_id' => $product->id])->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);
        $referrer = $this->createUser($partnerTenant);
        $referralService = app(ReferralService::class);
        $code = $referralService->getOrCreateReferralCode($referrer)->code;

        $referred = User::factory()->create(['partner_tenant_id' => $partnerTenant->id, 'partner_attributed_at' => now()]);
        $referral = $referralService->trackReferral($referred, $code);
        $this->assertNotNull($referral, 'the partner still sees the referral in My Referrals');

        $referralService->processVerifiedRegistration($referred);

        $this->assertSame(ReferralConstants::STATUS_VERIFIED, $referral->fresh()->status);
        $this->assertNull($referral->fresh()->rewarded_at);
        $this->assertSame(0, ReferralReward::where('referral_id', $referral->id)->count());
        Event::assertNotDispatched(ReferralSucceeded::class);
    }

    public function test_a_direct_referral_is_still_rewarded(): void
    {
        Mail::fake();
        config([
            'app.referral.enabled' => true,
            'app.referral.trigger' => ReferralConstants::TRIGGER_VERIFIED_REGISTRATION,
            'app.referral.reward_type' => ReferralConstants::REWARD_TYPE_CUSTOM_EVENT,
        ]);
        Event::fake([ReferralSucceeded::class]);

        $referrer = User::factory()->create();
        $referralService = app(ReferralService::class);
        $code = $referralService->getOrCreateReferralCode($referrer)->code;
        $referred = User::factory()->create();
        $referral = $referralService->trackReferral($referred, $code);

        $referralService->processVerifiedRegistration($referred);

        $this->assertSame(ReferralConstants::STATUS_REWARDED, $referral->fresh()->status);
        Event::assertDispatched(ReferralSucceeded::class);
    }
```

- [ ] **Step 2: Run to verify the first fails**

Run: `php artisan test --compact --filter="ReferralFlowTest::test_a_partner_attributed_referral_is_recorded_but_never_rewarded|ReferralFlowTest::test_a_direct_referral_is_still_rewarded"`
Expected: the partner test FAILS (status `rewarded`, event dispatched); the direct one PASSES.

- [ ] **Step 3: Implement**

In `app/Services/ReferralService.php`, at the top of `processReward()`:

```php
    private function processReward(Referral $referral): void
    {
        // A partner's referral is paid through their margin, never a coupon
        // (spec §3.6). The Referral row stays so My Referrals lists the
        // customer; it simply never reaches `rewarded`.
        if ($referral->referredUser?->partner_tenant_id !== null) {
            return;
        }

        $rewardType = config('app.referral.reward_type');
        // … unchanged …
```

- [ ] **Step 4: Run the referral tests**

Run: `php artisan test --compact --filter=ReferralFlowTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/ReferralService.php tests/Feature/Referral/ReferralFlowTest.php
git commit -m "feat(partner): record partner referrals without granting coupon rewards"
```

---

### Task 7: The Partner navigation group and the Referrals gate

**Files:**
- Modify: `app/Services/PartnerCapabilityService.php` (add `userCanAccessPartnerArea`)
- Modify: `app/Providers/Filament/DashboardPanelProvider.php:158-174` (navigation groups)
- Modify: `app/Filament/Dashboard/Resources/Referrals/ReferralResource.php` (group, label, sort, canAccess)
- Modify: `app/Filament/Dashboard/Resources/ReferralRewards/ReferralRewardResource.php` (group, canAccess)
- Modify: `app/Filament/Dashboard/Widgets/ReferralLinkWidget.php:42`, `ReferralStatsWidget.php:37`, `resources/views/filament/dashboard/widgets/referral-link-widget.blade.php:35`
- Modify: `tests/Feature/Filament/Dashboard/DashboardMenuItemsTest.php`
- Create: `tests/Feature/Services/PartnerAreaAccessTest.php`

**Interfaces:**
- Produces: `PartnerCapabilityService::userCanAccessPartnerArea(?Tenant $tenant, ?User $user): bool` — true iff both non-null and `tenantIsActivePartner($tenant)`. Tasks 11 and 13 call it from `canAccess()`.
- Navigation groups after this task: `Audits`, `Billing`, `Partner`, `Team Management`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Services/PartnerAreaAccessTest.php`:

```php
<?php

namespace Tests\Feature\Services;

use App\Constants\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Services\PartnerCapabilityService;
use Tests\Feature\FeatureTest;

class PartnerAreaAccessTest extends FeatureTest
{
    public function test_a_member_of_an_active_partner_tenant_can_access_the_partner_area(): void
    {
        $tenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => Plan::factory()->create(['product_id' => $product->id])->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);
        $user = $this->createUser($tenant);

        $this->assertTrue(app(PartnerCapabilityService::class)->userCanAccessPartnerArea($tenant, $user));
    }

    public function test_a_plain_tenant_or_a_missing_tenant_or_user_cannot(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $service = app(PartnerCapabilityService::class);

        $this->assertFalse($service->userCanAccessPartnerArea($tenant, $user));
        $this->assertFalse($service->userCanAccessPartnerArea(null, $user));
        $this->assertFalse($service->userCanAccessPartnerArea($tenant, null));
    }
}
```

Update `tests/Feature/Filament/Dashboard/DashboardMenuItemsTest.php`:

1. In `test_the_sidebar_shows_every_expected_group_and_item`, replace the Billing and Referrals assertions with:

```php
        $this->assertSame(['Orders', 'Subscriptions', 'Payments', 'My Rewards'], $navigation['Billing'] ?? []);
        $this->assertArrayNotHasKey('Referrals', $navigation);
        $this->assertArrayNotHasKey('Partner', $navigation);
```

2. Remove `'My Referrals' => [ReferralResource::class],` from `menuTargetProvider()` (a non-partner can no longer load it).

3. Add a partner fixture and two tests (imports: `App\Constants\SubscriptionStatus`, `App\Models\Plan`, `App\Models\Product`, `App\Models\Subscription`):

```php
    /** Same permissions as fullyPermittedUserAndTenant(), on a tenant with an active Partner plan. */
    private function partnerUserAndTenant(): array
    {
        [$user, $tenant] = $this->fullyPermittedUserAndTenant();

        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => Plan::factory()->create(['product_id' => $product->id])->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);

        foreach ([
            TenancyPermissionConstants::PERMISSION_MANAGE_PARTNER_ORDERS,
            TenancyPermissionConstants::PERMISSION_MANAGE_RESELLER_CATALOG,
        ] as $permission) {
            $user->tenants()->where('tenant_id', $tenant->id)->first()->pivot->givePermissionTo($permission);
        }

        return [$user, $tenant];
    }

    public function test_a_partner_sees_the_partner_group_and_no_rewards(): void
    {
        $this->partnerUserAndTenant();

        $navigation = $this->renderedNavigation();

        $this->assertSame(['Referrals'], $navigation['Partner'] ?? []);
        $this->assertSame(['Orders', 'Subscriptions', 'Payments'], $navigation['Billing'] ?? []);
    }

    public function test_the_referrals_page_loads_for_a_partner(): void
    {
        [, $tenant] = $this->partnerUserAndTenant();

        $this->get(ReferralResource::getUrl(tenant: $tenant))->assertSuccessful();
        $this->get(ReferralResource::getUrl(tenant: $tenant))->assertSee(__('Customers who sign up through this link buy at your prices.'));
    }
```

(Tasks 11 and 13 extend the `['Referrals']` expectation to `['Referrals', 'Orders', 'Pricing Settings']`.)

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --compact --filter="PartnerAreaAccessTest|DashboardMenuItemsTest"`
Expected: FAIL — method undefined; navigation still has a `Referrals` group.

- [ ] **Step 3: Implement**

`app/Services/PartnerCapabilityService.php` — add (after `tenantIsActivePartner`, with `use App\Models\User;`):

```php
    /**
     * The one gate every item in the dashboard's Partner group shares
     * (spec §4). Resources add their own tenancy permission on top.
     */
    public function userCanAccessPartnerArea(?Tenant $tenant, ?User $user): bool
    {
        return $tenant !== null && $user !== null && $this->tenantIsActivePartner($tenant);
    }
```

`app/Providers/Filament/DashboardPanelProvider.php` — replace the `'Referrals' => …` group with a `'Partner'` group placed between `Billing` and `Team Management`:

```php
                'Billing' => NavigationGroup::make()
                    ->label(__('Billing'))
                    ->collapsed(),
                'Partner' => NavigationGroup::make()
                    ->label(__('Partner')),
                'Team Management' => NavigationGroup::make()
                    ->label(__('Team Management'))
                    ->collapsed(),
```

`ReferralResource.php`:

```php
    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return config('app.referral.enabled', false)
            && app(PartnerCapabilityService::class)->userCanAccessPartnerArea(Filament::getTenant(), auth()->user());
    }

    public static function getNavigationLabel(): string
    {
        return __('Referrals');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Partner');
    }
```

(add `use App\Services\PartnerCapabilityService;` and `use Filament\Facades\Filament;`). Keep `getPluralModelLabel()` as `Referrals` and the `ListReferrals::getTitle()` as `My Referrals` → change that title to `__('Referrals')` too.

`ReferralRewardResource.php`:

```php
    public static function canAccess(): bool
    {
        return config('app.referral.enabled', false)
            && ! app(PartnerCapabilityService::class)->userCanAccessPartnerArea(Filament::getTenant(), auth()->user());
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Billing');
    }
```

`ReferralLinkWidget::canView()` and `ReferralStatsWidget::canView()` both become `return ReferralResource::canAccess();` (import `App\Filament\Dashboard\Resources\Referrals\ReferralResource`). In `referral-link-widget.blade.php` change the sentence `Share it and earn rewards when someone signs up.` to `Customers who sign up through this link buy at your prices.`

- [ ] **Step 4: Run**

Run: `php artisan test --compact --filter="PartnerAreaAccessTest|DashboardMenuItemsTest|Referral"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/PartnerCapabilityService.php app/Providers/Filament/DashboardPanelProvider.php app/Filament/Dashboard/Resources/Referrals app/Filament/Dashboard/Resources/ReferralRewards app/Filament/Dashboard/Widgets/ReferralLinkWidget.php app/Filament/Dashboard/Widgets/ReferralStatsWidget.php resources/views/filament/dashboard/widgets/referral-link-widget.blade.php tests/Feature/Filament/Dashboard/DashboardMenuItemsTest.php tests/Feature/Services/PartnerAreaAccessTest.php
git commit -m "feat(partner): a Partner navigation group; Referrals only for active partner tenants"
```

---

### Task 8: Partner tenants cannot change plan

**Files:**
- Modify: `app/Services/SubscriptionService.php:681-686`
- Modify: `app/Filament/Dashboard/Resources/Subscriptions/Pages/ChangeSubscriptionPlan.php`
- Modify: `app/Http/Controllers/SubscriptionController.php:36-40`
- Create: `tests/Feature/Services/PartnerChangePlanGateTest.php`

**Interfaces:**
- Consumes: `PartnerCapabilityService::tenantIsActivePartner(Tenant)`.
- Produces: `SubscriptionService::canChangeSubscriptionPlan()` is false for any subscription whose tenant is an active partner; the change-plan page returns 403 and the controller redirects back with an error under the same rule.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Services;

use App\Constants\PlanType;
use App\Constants\SubscriptionStatus;
use App\Constants\SubscriptionType;
use App\Constants\TenancyPermissionConstants;
use App\Filament\Dashboard\Resources\Subscriptions\SubscriptionResource;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use Filament\Facades\Filament;
use Tests\Feature\FeatureTest;

class PartnerChangePlanGateTest extends FeatureTest
{
    private function activeGatewaySubscription(Tenant $tenant): Subscription
    {
        $plan = Plan::factory()->create([
            'product_id' => Product::factory()->create()->id,
            'type' => PlanType::FLAT_RATE->value,
            'is_active' => true,
        ]);

        return Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'type' => SubscriptionType::PAYMENT_PROVIDER_MANAGED,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);
    }

    private function makePartner(Tenant $tenant): void
    {
        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => Plan::factory()->create(['product_id' => $product->id])->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);
    }

    public function test_a_direct_tenant_can_change_plan(): void
    {
        $tenant = $this->createTenant();
        $subscription = $this->activeGatewaySubscription($tenant);

        $this->assertTrue(app(SubscriptionService::class)->canChangeSubscriptionPlan($subscription));
    }

    public function test_a_partner_tenant_cannot_change_plan(): void
    {
        $tenant = $this->createTenant();
        $subscription = $this->activeGatewaySubscription($tenant);
        $this->makePartner($tenant);

        $this->assertFalse(app(SubscriptionService::class)->canChangeSubscriptionPlan($subscription));
    }

    public function test_the_change_plan_page_is_forbidden_for_a_partner_tenant(): void
    {
        $this->withExceptionHandling();
        config()->set('app.customer_dashboard.show_subscriptions', true);
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, [
            TenancyPermissionConstants::PERMISSION_VIEW_SUBSCRIPTIONS,
            TenancyPermissionConstants::PERMISSION_UPDATE_SUBSCRIPTIONS,
        ]);
        $subscription = $this->activeGatewaySubscription($tenant);
        $this->makePartner($tenant);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        $this->get(SubscriptionResource::getUrl('change-plan', ['record' => $subscription->uuid], tenant: $tenant))
            ->assertForbidden();
    }

    public function test_the_change_plan_page_loads_for_a_direct_tenant(): void
    {
        config()->set('app.customer_dashboard.show_subscriptions', true);
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, [
            TenancyPermissionConstants::PERMISSION_VIEW_SUBSCRIPTIONS,
            TenancyPermissionConstants::PERMISSION_UPDATE_SUBSCRIPTIONS,
        ]);
        $subscription = $this->activeGatewaySubscription($tenant);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        $this->get(SubscriptionResource::getUrl('change-plan', ['record' => $subscription->uuid], tenant: $tenant))
            ->assertSuccessful();
    }

    public function test_the_change_plan_route_refuses_a_partner_tenant(): void
    {
        $this->withExceptionHandling();
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, [TenancyPermissionConstants::PERMISSION_UPDATE_SUBSCRIPTIONS]);
        $subscription = $this->activeGatewaySubscription($tenant);
        $otherPlan = Plan::factory()->create(['product_id' => Product::factory()->create()->id, 'is_active' => true]);
        $this->makePartner($tenant);

        $response = $this->actingAs($user)->from('/dashboard')->get(route('subscription.change-plan', [
            'subscriptionUuid' => $subscription->uuid,
            'planSlug' => $otherPlan->slug,
            'tenantUuid' => $tenant->uuid,
        ]));

        $response->assertRedirect('/dashboard');
        $response->assertSessionHas('error', __('Plan changes are not available for this subscription.'));
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `php artisan test --compact --filter=PartnerChangePlanGateTest`
Expected: `test_a_partner_tenant_cannot_change_plan`, `…page_is_forbidden…`, `…route_refuses…` FAIL.

- [ ] **Step 3: Implement**

`app/Services/SubscriptionService.php` — replace `canChangeSubscriptionPlan`:

```php
    public function canChangeSubscriptionPlan(Subscription $subscription)
    {
        return $subscription->type === SubscriptionType::PAYMENT_PROVIDER_MANAGED &&
            $this->planService->isPlanChangeable($subscription->plan) &&
            $subscription->status === SubscriptionStatus::ACTIVE->value &&
            ! $this->tenantIsPartner($subscription);
    }

    /**
     * A partner tenant's plans are administered by the operator, not
     * self-served (spec §5). Resolved lazily: PartnerCapabilityService
     * constructor-injects this service, so the dependency cannot run both ways.
     */
    private function tenantIsPartner(Subscription $subscription): bool
    {
        /** @var Tenant|null $tenant */
        $tenant = $subscription->tenant;

        return $tenant !== null && app(PartnerCapabilityService::class)->tenantIsActivePartner($tenant);
    }
```

`ChangeSubscriptionPlan.php` — add:

```php
    public function mount(): void
    {
        $subscriptionService = app(SubscriptionService::class);
        $subscription = $subscriptionService->findActiveByTenantAndSubscriptionUuid(
            Filament::getTenant(),
            (string) request()->route('record'),
        );

        abort_if($subscription === null, 404);
        abort_unless($subscriptionService->canChangeSubscriptionPlan($subscription), 403);
    }
```

(imports: `App\Services\SubscriptionService`, `Filament\Facades\Filament`.)

`SubscriptionController::changePlan()` — right after the `if (! $subscription)` block:

```php
        if (! $this->subscriptionService->canChangeSubscriptionPlan($subscription)) {
            return redirect()->back()->with('error', __('Plan changes are not available for this subscription.'));
        }
```

- [ ] **Step 4: Run the gate tests and the subscription slice**

Run: `php artisan test --compact --filter="PartnerChangePlanGateTest|Subscription"`
Expected: PASS. If an existing subscription test now 404s on the change-plan page, its fixture subscription is not `ACTIVE`; make it active rather than weakening the guard.

- [ ] **Step 5: Commit**

```bash
git add app/Services/SubscriptionService.php app/Filament/Dashboard/Resources/Subscriptions/Pages/ChangeSubscriptionPlan.php app/Http/Controllers/SubscriptionController.php tests/Feature/Services/PartnerChangePlanGateTest.php
git commit -m "feat(partner): deny the change-plan section to partner tenants"
```

---
### Task 9: The nine report packages — config, seeder, export, landing page

**Files:**
- Modify: `config/pricing.php` (add `package_tiers` + `packages`; empty `subscriptions`; extend `retired.plans`)
- Create: `database/seeders/ReportPackagesSeeder.php`
- Modify: `database/seeders/AuditMonetizationSeeder.php:141-146` (reseller flag on `audit-partner`), `database/seeders/DatabaseSeeder.php`
- Modify: `app/Console/Commands/ExportPricingCommand.php:65-84`
- Create: `tests/Feature/Seeders/ReportPackagesSeederTest.php`
- Modify: `tests/Feature/Seeders/AuditMonetizationSeederTest.php`, `tests/Feature/Console/ExportPricingCommandTest.php`
- Modify: `frontend/src/data/pricing.json` (regenerated), `frontend/src/pages/index.astro:9-10,855-858`

**Interfaces:**
- Produces: `config('pricing.package_tiers')` keyed `diagnostic|deep_ai|expert` with `name`, `plural`, `credit_key`, `headline`; `config('pricing.packages')` keyed by slug with `tier`, `name`, `actions`, `unit_price`, `price`, `discount_percent`, `partner_unit_price`, `is_popular`. Seeded product metadata keys: the three `audit_*_credits`, `audit_tier_group`, `package_actions`, `partner_suggested_price`. Task 10 reads `audit_tier_group`; Task 11 reads `partner_suggested_price`.

- [ ] **Step 1: Write the seeder tests**

Create `tests/Feature/Seeders/ReportPackagesSeederTest.php`:

```php
<?php

namespace Tests\Feature\Seeders;

use App\Models\Currency;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\PlanPricePaymentProviderData;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\Subscription;
use Database\Seeders\AuditMonetizationSeeder;
use Database\Seeders\ReportPackagesSeeder;
use Tests\Feature\FeatureTest;

class ReportPackagesSeederTest extends FeatureTest
{
    private function seed(): void
    {
        $this->seed(ReportPackagesSeeder::class);
    }

    public function test_seeds_nine_packages_at_the_table_prices(): void
    {
        $this->seed();

        $expected = [
            'audit-diagnostic-5' => 23275, 'audit-diagnostic-15' => 66150, 'audit-diagnostic-30' => 117600,
            'audit-deep-ai-5' => 56525, 'audit-deep-ai-15' => 160650, 'audit-deep-ai-30' => 285600,
            'audit-expert-5' => 474525, 'audit-expert-15' => 1348650, 'audit-expert-30' => 2397600,
        ];

        foreach ($expected as $slug => $cents) {
            $plan = Plan::where('slug', $slug.'-monthly')->first();

            $this->assertNotNull($plan, "Missing plan [{$slug}-monthly].");
            $this->assertTrue((bool) $plan->is_active);
            $this->assertTrue((bool) $plan->is_visible);
            $this->assertSame('month', $plan->interval->slug);
            $this->assertSame($cents, (int) $plan->prices()->first()->price);
            $this->assertSame($slug, $plan->product->slug);
        }
    }

    public function test_every_package_price_matches_unit_times_actions_less_discount(): void
    {
        foreach (config('pricing.packages') as $slug => $package) {
            $computed = (int) round($package['unit_price'] * $package['actions'] * (100 - $package['discount_percent']) / 100);

            $this->assertSame($computed, $package['price'], "Package [{$slug}] price drifted from its arithmetic.");
        }
    }

    public function test_a_package_grants_its_actions_on_its_own_tier_only(): void
    {
        $this->seed();

        $metadata = Product::where('slug', 'audit-deep-ai-15')->firstOrFail()->metadata;

        $this->assertSame(0, (int) $metadata['audit_diagnostic_credits']);
        $this->assertSame(15, (int) $metadata['audit_deep_ai_credits']);
        $this->assertSame(0, (int) $metadata['audit_expert_credits']);
        $this->assertSame('deep_ai', $metadata['audit_tier_group']);
        $this->assertSame(15, (int) $metadata['package_actions']);
    }

    public function test_a_package_carries_the_suggested_partner_package_price(): void
    {
        $this->seed();

        $this->assertSame(47500, (int) Product::where('slug', 'audit-diagnostic-5')->firstOrFail()->metadata['partner_suggested_price']);
        $this->assertSame(4080000, (int) Product::where('slug', 'audit-expert-30')->firstOrFail()->metadata['partner_suggested_price']);
    }

    public function test_a_package_describes_its_best_use_case_and_features(): void
    {
        $this->seed();

        $product = Product::where('slug', 'audit-expert-5')->firstOrFail();

        $this->assertSame(config('pricing.package_tiers.expert.headline'), $product->description);
        $this->assertSame('5 Expert Audits per month', $product->features[0]['feature']);
        $this->assertSame([], $product->reseller_quota_keys);
    }

    public function test_is_idempotent_and_resets_a_manual_price_edit(): void
    {
        $this->seed();
        $before = Product::count() + Plan::count() + PlanPrice::count();
        $plan = Plan::where('slug', 'audit-diagnostic-5-monthly')->firstOrFail();
        $plan->prices()->first()->update(['price' => 1]);

        $this->seed();

        $this->assertSame($before, Product::count() + Plan::count() + PlanPrice::count());
        $this->assertSame(23275, (int) $plan->prices()->first()->fresh()->price);
    }

    public function test_a_provider_price_mapping_survives_a_re_run(): void
    {
        $this->seed();
        $price = Plan::where('slug', 'audit-diagnostic-5-monthly')->firstOrFail()->prices()->first();
        $provider = PaymentProvider::where('slug', 'stripe')->firstOrFail();
        PlanPricePaymentProviderData::updateOrCreate(
            ['plan_price_id' => $price->id, 'payment_provider_id' => $provider->id, 'type' => 'main_price'],
            ['payment_provider_price_id' => 'price_keepme'],
        );

        $this->seed();

        $this->assertSame('price_keepme', PlanPricePaymentProviderData::where('plan_price_id', $price->id)->value('payment_provider_price_id'));
    }

    public function test_legacy_plans_are_retired_and_their_subscriptions_kept(): void
    {
        $legacy = Plan::updateOrCreate(['slug' => 'audit-growth-monthly'], [
            'name' => 'Growth Monthly',
            'product_id' => Product::updateOrCreate(['slug' => 'audit-growth'], ['name' => 'Growth', 'is_popular' => true])->id,
            'interval_id' => \App\Models\Interval::where('slug', 'month')->firstOrFail()->id,
            'interval_count' => 1,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $subscription = Subscription::factory()->create(['plan_id' => $legacy->id, 'tenant_id' => $this->createTenant()->id]);

        $this->seed();

        $legacy->refresh();
        $this->assertFalse((bool) $legacy->is_active);
        $this->assertFalse((bool) $legacy->is_visible);
        $this->assertFalse((bool) $legacy->product->is_popular);
        $this->assertSame($legacy->id, $subscription->fresh()->plan_id);
    }

    public function test_the_partner_plan_gains_the_reseller_flag_and_keeps_its_credits(): void
    {
        $this->seed(AuditMonetizationSeeder::class);

        $this->seed();

        $metadata = Product::where('slug', 'audit-partner')->firstOrFail()->metadata;
        $this->assertTrue($metadata['enables_reseller_program']);
        $this->assertSame(100, (int) $metadata['audit_diagnostic_credits']);
    }

    public function test_the_seeder_holds_no_literal_money_figure(): void
    {
        $source = (string) file_get_contents(database_path('seeders/ReportPackagesSeeder.php'));

        foreach (['23275', '66150', '117600', '56525', '160650', '285600', '474525', '1348650', '2397600', '4900', '11900', '99900'] as $literal) {
            $this->assertStringNotContainsString($literal, $source);
        }
        $this->assertStringContainsString("config('pricing", $source);
    }
}
```

`PlanPricePaymentProviderData` is the model (fillable: `plan_price_id`, `payment_provider_id`, `payment_provider_price_id`, `type`); write `'type' => \App\Constants\PaymentProviderPlanPriceType::MAIN_PRICE->value` instead of the literal.

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact --filter=ReportPackagesSeederTest`
Expected: FAIL — class `ReportPackagesSeeder` not found; `pricing.packages` null.

- [ ] **Step 3: Add the config**

In `config/pricing.php`, replace the entire `'subscriptions' => [ … ]` block with:

```php
    // Starter/Growth/Agency/Enterprise were replaced by `packages` on
    // 2026-09-07; their slugs are in `retired.plans`. Kept as an empty block
    // so AuditMonetizationSeeder and app:export-pricing need no branch.
    'subscriptions' => [],

    // The per-report tiers the packages below are built from. `credit_key`
    // is the plan-metadata key AuditEntitlementService meters that tier on.
    'package_tiers' => [
        'diagnostic' => [
            'name' => 'Diagnostic Report',
            'plural' => 'Diagnostic Reports',
            'credit_key' => 'audit_diagnostic_credits',
            'headline' => 'CTOs, product owners, and business leaders who want to evaluate team efficiency and basic code health.',
        ],
        'deep_ai' => [
            'name' => 'Deep AI Code Review',
            'plural' => 'Deep AI Code Reviews',
            'credit_key' => 'audit_deep_ai_credits',
            'headline' => 'Small, medium-sized, and large development teams that want to verify code health without paying for a full manual QA review.',
        ],
        'expert' => [
            'name' => 'Expert Audit',
            'plural' => 'Expert Audits',
            'credit_key' => 'audit_expert_credits',
            'headline' => 'Product companies that outsource development and need both a code-health assessment and support from a human expert.',
        ],
    ],

    // Monthly packages: N reports of one tier. `price` is the FlexPick package
    // price (unit × actions × (1 − discount)); `partner_unit_price` is the
    // suggested partner selling price per report, which the Pricing Settings
    // page multiplies by `actions` as the partner's default. A test asserts
    // the arithmetic so the table cannot drift.
    'packages' => [
        'audit-diagnostic-5' => ['tier' => 'diagnostic', 'name' => 'Diagnostic Report × 5', 'actions' => 5, 'unit_price' => 4900, 'price' => 23275, 'discount_percent' => 5, 'partner_unit_price' => 10000, 'is_popular' => false],
        'audit-diagnostic-15' => ['tier' => 'diagnostic', 'name' => 'Diagnostic Report × 15', 'actions' => 15, 'unit_price' => 4900, 'price' => 66150, 'discount_percent' => 10, 'partner_unit_price' => 10000, 'is_popular' => false],
        'audit-diagnostic-30' => ['tier' => 'diagnostic', 'name' => 'Diagnostic Report × 30', 'actions' => 30, 'unit_price' => 4900, 'price' => 117600, 'discount_percent' => 20, 'partner_unit_price' => 10000, 'is_popular' => false],
        'audit-deep-ai-5' => ['tier' => 'deep_ai', 'name' => 'Deep AI Code Review × 5', 'actions' => 5, 'unit_price' => 11900, 'price' => 56525, 'discount_percent' => 5, 'partner_unit_price' => 25000, 'is_popular' => false],
        'audit-deep-ai-15' => ['tier' => 'deep_ai', 'name' => 'Deep AI Code Review × 15', 'actions' => 15, 'unit_price' => 11900, 'price' => 160650, 'discount_percent' => 10, 'partner_unit_price' => 25000, 'is_popular' => false],
        'audit-deep-ai-30' => ['tier' => 'deep_ai', 'name' => 'Deep AI Code Review × 30', 'actions' => 30, 'unit_price' => 11900, 'price' => 285600, 'discount_percent' => 20, 'partner_unit_price' => 25000, 'is_popular' => false],
        'audit-expert-5' => ['tier' => 'expert', 'name' => 'Expert Audit × 5', 'actions' => 5, 'unit_price' => 99900, 'price' => 474525, 'discount_percent' => 5, 'partner_unit_price' => 170000, 'is_popular' => false],
        'audit-expert-15' => ['tier' => 'expert', 'name' => 'Expert Audit × 15', 'actions' => 15, 'unit_price' => 99900, 'price' => 1348650, 'discount_percent' => 10, 'partner_unit_price' => 170000, 'is_popular' => false],
        'audit-expert-30' => ['tier' => 'expert', 'name' => 'Expert Audit × 30', 'actions' => 30, 'unit_price' => 99900, 'price' => 2397600, 'discount_percent' => 20, 'partner_unit_price' => 170000, 'is_popular' => false],
    ],
```

And extend `retired.plans`:

```php
        'plans' => [
            'audit-scale-monthly',
            'audit-starter-monthly',
            'audit-growth-monthly',
            'audit-agency-monthly',
            'audit-enterprise-monthly',
        ],
```

- [ ] **Step 4: Write the seeder**

Create `database/seeders/ReportPackagesSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Constants\PlanType;
use App\Models\Currency;
use App\Models\Interval;
use App\Models\Plan;
use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * The nine monthly report packages from config('pricing.packages'), safe to
 * run on a live database at any time:
 *
 *   php artisan db:seed --class=ReportPackagesSeeder
 *
 * Idempotent on slug. Every package is a NEW slug on purpose: the Stripe
 * provider reuses a stored price id whenever one exists, so repricing an
 * existing plan in place would keep charging the old amount. Retired plans
 * are deactivated, never deleted — their subscriptions keep resolving.
 * Provider price mappings (plan_price_payment_provider_data) are never
 * touched. Nothing here holds a literal money figure.
 */
class ReportPackagesSeeder extends Seeder
{
    public function run(): void
    {
        $currency = Currency::where('code', config('pricing.currency'))->firstOrFail();
        $month = Interval::where('slug', 'month')->firstOrFail();
        $tiers = config('pricing.package_tiers');

        foreach (config('pricing.packages') as $slug => $package) {
            $tier = $tiers[$package['tier']];

            $credits = array_fill_keys(array_column($tiers, 'credit_key'), 0);
            $credits[$tier['credit_key']] = $package['actions'];

            $product = Product::updateOrCreate(['slug' => $slug], [
                'name' => $package['name'],
                'description' => $tier['headline'],
                'features' => [
                    ['feature' => "{$package['actions']} {$tier['plural']} per month"],
                    ['feature' => 'Full detailed reports'],
                    ['feature' => 'PDF export'],
                ],
                'is_popular' => $package['is_popular'],
                'is_default' => false,
                'metadata' => $credits + [
                    'audit_tier_group' => $package['tier'],
                    'package_actions' => $package['actions'],
                    'partner_suggested_price' => $package['partner_unit_price'] * $package['actions'],
                ],
                'reseller_quota_keys' => [],
            ]);

            $plan = Plan::updateOrCreate(['slug' => $slug.'-monthly'], [
                'name' => $package['name'].' Monthly',
                'product_id' => $product->id,
                'interval_id' => $month->id,
                'interval_count' => 1,
                'has_trial' => false,
                'is_active' => true,
                'is_visible' => true,
                'type' => PlanType::FLAT_RATE->value,
            ]);

            $plan->prices()->updateOrCreate(['currency_id' => $currency->id], ['price' => $package['price']]);
        }

        $this->flagResellerPlan();
        $this->retire();
    }

    /**
     * The hidden, admin-assigned audit-partner plan is the Partner plan
     * (spec §6.2). Merged, not replaced, so its credit allowances survive.
     * AuditMonetizationSeeder writes the same flag, so a full reseed keeps it.
     */
    private function flagResellerPlan(): void
    {
        $product = Product::where('slug', 'audit-partner')->first();

        if ($product === null) {
            return;
        }

        $product->metadata = ['enables_reseller_program' => true] + (array) $product->metadata;
        $product->save();
    }

    private function retire(): void
    {
        $plans = Plan::whereIn('slug', config('pricing.retired.plans'))->get();

        foreach ($plans as $plan) {
            $plan->update(['is_active' => false, 'is_visible' => false]);
            $plan->product?->update(['is_popular' => false]);
        }
    }
}
```

In `AuditMonetizationSeeder::seedPartnerPlan()` add `'enables_reseller_program' => true,` as the first entry of the `audit-partner` product's `metadata` array. In `DatabaseSeeder` add `ReportPackagesSeeder::class,` after `AuditMonetizationSeeder::class,`.

- [ ] **Step 5: Run the seeder tests**

Run: `php artisan test --compact --filter=ReportPackagesSeederTest`
Expected: PASS.

- [ ] **Step 6: Repair the monetization seeder tests**

In `tests/Feature/Seeders/AuditMonetizationSeederTest.php`:
- Delete `test_seeds_the_pitch_subscription_grid` and `test_subscription_products_carry_allowance_metadata` (the grid is gone).
- In `test_seeds_a_hidden_free_partner_plan` add `$this->assertTrue($metadata['enables_reseller_program']);`.
- In `test_legacy_subscription_plans_are_deactivated_not_deleted`, replace `Plan::factory()->create([...])` with `Plan::updateOrCreate(['slug' => $slug], ['name' => $slug, 'product_id' => Product::factory()->create()->id, 'interval_id' => Interval::where('slug', 'month')->firstOrFail()->id, 'interval_count' => 1, 'is_active' => true, 'is_visible' => true])` (import `App\Models\Interval`) — the slugs now collide with rows other tests seed.
- In `test_every_plan_carries_an_expert_credits_metadata_key`, iterate `array_keys(config('pricing.packages'))` after calling `$this->seed(ReportPackagesSeeder::class)` instead of `pricing.subscriptions`.

Run: `php artisan test --compact --filter="AuditMonetizationSeederTest|ReportPackagesSeederTest|AuditDemoSeederTest"`
Expected: PASS. (`AuditDemoSeeder` uses `self::PLAN_SLUG` — if it names a retired slug, change it to `audit-diagnostic-5-monthly` and re-run.)

- [ ] **Step 7: Export the packages**

In `ExportPricingCommand::payload()`, after the `$subscriptions` loop add:

```php
        $packages = [];

        foreach (config('pricing.packages') as $slug => $package) {
            $tier = config('pricing.package_tiers.'.$package['tier']);

            $packages[$slug] = [
                'name' => $package['name'],
                'tier' => $package['tier'],
                'tier_name' => $tier['name'],
                'actions' => $package['actions'],
                'unit_price_cents' => $package['unit_price'],
                'price_cents' => $package['price'],
                'price_display' => $this->display($package['price']),
                'discount_percent' => $package['discount_percent'],
                'headline' => $tier['headline'],
                'is_popular' => $package['is_popular'],
            ];
        }
```

and add `'packages' => $packages,` to the returned array after `'subscriptions'`.

Update `tests/Feature/Console/ExportPricingCommandTest.php`: in `test_writes_every_tier_and_subscription` replace the `audit-enterprise` assertion with `$this->assertArrayHasKey('audit-diagnostic-5', $exported['packages']);`; in `test_exports_prices_as_display_strings_and_cents` replace the `'$1,500'` line with `$this->assertSame('$23,976', $exported['packages']['audit-expert-30']['price_display']);` and add `$this->assertSame('$232.75', $exported['packages']['audit-diagnostic-5']['price_display']);`.

Run: `php artisan app:export-pricing && php artisan test --compact --filter=ExportPricingCommandTest`
Expected: PASS; `frontend/src/data/pricing.json` regenerated with a `packages` block and empty `subscriptions`.

- [ ] **Step 8: Point the landing page at packages**

In `frontend/src/pages/index.astro` replace lines 9–10 with:

```ts
const packages = Object.values(pricing.packages);
const cheapestPackage = packages.reduce((a, b) => (a.price_cents <= b.price_cents ? a : b));
const fewestActions = Math.min(...packages.map((p) => p.actions));
const mostActions = Math.max(...packages.map((p) => p.actions));
```

and lines 855–858 with:

```astro
            <h3 class="fp-card-title">Track it — from {cheapestPackage.price_display}/mo</h3>
            <p class="fp-card-body">
              Re-audit on a schedule, watch your health score trend as you ship, and catch new risks before they
              compound. Packages include {fewestActions} to {mostActions} reports a month.
            </p>
```

Run from `frontend/`: `npm run check`
Expected: clean. (If `astro check` complains about the JSON type of `packages`, the regenerated file is stale — re-run the export.)

- [ ] **Step 9: Commit**

```bash
cd /var/www/html/flexpick.net
git add backend/config/pricing.php backend/database/seeders/ReportPackagesSeeder.php backend/database/seeders/AuditMonetizationSeeder.php backend/database/seeders/DatabaseSeeder.php backend/app/Console/Commands/ExportPricingCommand.php backend/tests/Feature/Seeders/ReportPackagesSeederTest.php backend/tests/Feature/Seeders/AuditMonetizationSeederTest.php backend/tests/Feature/Console/ExportPricingCommandTest.php frontend/src/data/pricing.json frontend/src/pages/index.astro
git commit -m "feat(pricing): seed the nine report packages and retire the old subscription grid"
```

---

### Task 10: Pricing page groups packages by tier

**Files:**
- Modify: `app/View/Components/Plans/All.php` (add `tierSections`)
- Modify: `resources/views/components/plans/all.blade.php:44-52`, `resources/views/components/filament/plans/all.blade.php:44-50`
- Modify: `resources/css/flexpick-brand.css` (after `.fp-pricing-section-title`)
- Modify: `tests/Feature/Http/Controllers/PricingPageTest.php` (add two tests)

**Interfaces:**
- Consumes: `product.metadata.audit_tier_group`, `config('pricing.package_tiers')`.
- Produces: view data `$tierSections` — `array<string interval, list<array{key: ?string, title: ?string, headline: ?string, plans: list<Plan>}>>`, parallel to `$groupedPlans`.

- [ ] **Step 1: Add the failing tests**

Append to `PricingPageTest` (imports `App\Models\Plan`, `App\Models\Product`, `App\Services\CurrencyService`, `App\Constants\PlanType` already exist there):

```php
    private function visiblePlan(array $metadata, string $name): Plan
    {
        $plan = Plan::factory()->create([
            'product_id' => Product::factory()->create(['name' => $name, 'metadata' => $metadata])->id,
            'type' => PlanType::FLAT_RATE->value,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $plan->prices()->create(['currency_id' => app(CurrencyService::class)->getCurrency()->id, 'price' => 1000]);

        return $plan;
    }

    public function test_packages_are_grouped_under_their_tier_headings_in_tier_order(): void
    {
        $this->visiblePlan(['audit_tier_group' => 'expert'], 'Zed Expert Pack');
        $this->visiblePlan(['audit_tier_group' => 'diagnostic'], 'Alpha Diagnostic Pack');
        $user = $this->createUser($this->createTenant());

        $response = $this->actingAs($user)->get(route('pricing'))->assertOk();

        $response->assertSeeInOrder([
            config('pricing.package_tiers.diagnostic.headline'),
            'Alpha Diagnostic Pack',
            config('pricing.package_tiers.expert.headline'),
            'Zed Expert Pack',
        ]);
    }

    public function test_plans_without_a_tier_render_in_a_trailing_unlabelled_grid(): void
    {
        $this->visiblePlan([], 'Loose Legacy Plan');
        $user = $this->createUser($this->createTenant());

        $response = $this->actingAs($user)->get(route('pricing'))->assertOk();

        $response->assertSee('Loose Legacy Plan');
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact --filter="PricingPageTest::test_packages_are_grouped"`
Expected: FAIL — headline text not on the page.

- [ ] **Step 3: Implement the component**

In `app/View/Components/Plans/All.php::enrichViewData()`, after `$viewData['groupedPlans'] = $groupedPlans;` add:

```php
        $viewData['tierSections'] = array_map(fn (array $intervalPlans): array => $this->tierSections($intervalPlans), $groupedPlans);
```

and add the method:

```php
    /**
     * Split one interval's cards into tier sections (spec §6.3): packages
     * carry product.metadata.audit_tier_group; anything without it lands in
     * one trailing unlabelled section, so plans outside the package catalog
     * keep rendering exactly as before.
     *
     * @param  list<\App\Models\Plan>  $plans
     * @return list<array{key: ?string, title: ?string, headline: ?string, plans: list<\App\Models\Plan>}>
     */
    protected function tierSections(array $plans): array
    {
        $tiers = (array) config('pricing.package_tiers', []);
        $byTier = [];
        $ungrouped = [];

        foreach ($plans as $plan) {
            $key = data_get($plan->product?->metadata, 'audit_tier_group');

            if (is_string($key) && array_key_exists($key, $tiers)) {
                $byTier[$key][] = $plan;
            } else {
                $ungrouped[] = $plan;
            }
        }

        $sections = [];

        foreach ($tiers as $key => $tier) {
            if (isset($byTier[$key])) {
                $sections[] = ['key' => $key, 'title' => $tier['name'], 'headline' => $tier['headline'], 'plans' => $byTier[$key]];
            }
        }

        if ($ungrouped !== []) {
            $sections[] = ['key' => null, 'title' => null, 'headline' => null, 'plans' => $ungrouped];
        }

        return $sections;
    }
```

- [ ] **Step 4: Update both blades**

`resources/views/components/plans/all.blade.php` — replace the `@foreach($groupedPlans as $interval => $intervalPlans)` panel block with:

```blade
        @foreach($groupedPlans as $interval => $intervalPlans)
            <div id="pricing-{{ $interval }}" role="tabpanel" x-show="interval === @js($interval)" @if ($interval !== $activeInterval) x-cloak @endif>
                @foreach($tierSections[$interval] as $section)
                    @if($section['title'] !== null)
                        <div class="fp-tier-heading">
                            <h3 class="fp-pricing-section-title">{{ __($section['title']) }}</h3>
                            <p class="fp-tier-headline">{{ __($section['headline']) }}</p>
                        </div>
                    @endif
                    <div class="fp-plan-grid">
                        @foreach($section['plans'] as $plan)
                            <x-plans.one :plan="$plan" />
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endforeach
```

`resources/views/components/filament/plans/all.blade.php` — same shape inside its `@foreach($groupedPlans as $interval => $plans)`, keeping `<x-filament.plans.one :plan="$plan" :subscription="$subscription" :buyRoute="$buyRoute" />` as the card and `id="plans-{{ $interval }}"`.

`resources/css/flexpick-brand.css` — after the `.fp-pricing-section-title` rule:

```css
.fp-tier-heading {
    margin: 2.5rem 0 1rem;
}

.fp-tier-heading:first-child {
    margin-top: 0;
}

.fp-tier-headline {
    margin: 0.35rem 0 0;
    max-width: 48rem;
    font-size: 0.95rem;
    color: var(--fp-muted);
}
```

Then `npm run build` in `backend/` (or `npm run dev` if HMR is running) so the Vite bundle picks up the CSS.

- [ ] **Step 5: Run**

Run: `php artisan test --compact --filter="PricingPageTest|FpPlanCardComponentTest|SubscriptionCheckoutFormTest|DashboardMenuItemsTest"`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/View/Components/Plans/All.php resources/views/components/plans/all.blade.php resources/views/components/filament/plans/all.blade.php resources/css/flexpick-brand.css tests/Feature/Http/Controllers/PricingPageTest.php
git commit -m "feat(pricing): group package cards under their report tier"
```

---
### Task 11: The Pricing Settings page replaces the two catalog resources

**Files:**
- Modify: `app/Services/PartnerCatalogService.php` (add `suggestedPlanPrice`, `suggestedProductPrice`, `planOfferingFor`, `productOfferingFor`)
- Create: `app/Filament/Dashboard/Pages/PartnerPricingSettings.php`, `resources/views/filament/dashboard/pages/partner-pricing-settings.blade.php`
- Create: `app/Livewire/Filament/Dashboard/PartnerPlanPricingTable.php`, `app/Livewire/Filament/Dashboard/PartnerProductPricingTable.php`
- Delete: `app/Filament/Dashboard/Resources/PartnerPlanCatalog/`, `app/Filament/Dashboard/Resources/PartnerProductCatalog/`, `tests/Feature/Filament/Dashboard/PartnerPlanCatalogResourceTest.php`, `tests/Feature/Filament/Dashboard/PartnerProductCatalogResourceTest.php`
- Create: `tests/Feature/Filament/Dashboard/PartnerPricingSettingsTest.php`
- Modify: `tests/Feature/Services/PartnerCatalogServiceTest.php` (add suggested-price tests), `tests/Feature/Filament/Dashboard/DashboardMenuItemsTest.php`

**Interfaces:**
- Consumes: `PartnerCapabilityService::userCanAccessPartnerArea()`, `TenantPermissionService::tenantUserHasPermissionTo()`, `PartnerCatalogService::setPlanOffering()/setProductOffering()/planBasePrice()/productBasePrice()/isPlanOfferingBelowMinimum()/isProductOfferingBelowMinimum()`.
- Produces: `PartnerCatalogService::suggestedPlanPrice(Tenant, Plan): int` (cents; existing offering → `partner_suggested_price` → base), `suggestedProductPrice(Tenant, OneTimeProduct): int`, `planOfferingFor(Tenant, Plan): ?PartnerPlanOffering`, `productOfferingFor(Tenant, OneTimeProduct): ?PartnerProductOffering`. Page slug `pricing-settings`, nav label `Pricing Settings`, group `Partner`, sort 3. Table action name `set-price` with form fields `price` (dollars, float) and `is_enabled`.

- [ ] **Step 1: Service tests**

Append to `tests/Feature/Services/PartnerCatalogServiceTest.php` (reuse whatever plan/product fixture helpers it already has; if none, build them inline as in `PartnerPricingResolverTest::sellablePlan`):

```php
    public function test_suggested_plan_price_prefers_offering_then_metadata_then_base(): void
    {
        $tenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['partner_suggested_price' => 47500]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        $plan->prices()->create(['currency_id' => app(CurrencyService::class)->getCurrency()->id, 'price' => 23275]);
        $service = app(PartnerCatalogService::class);

        $this->assertSame(47500, $service->suggestedPlanPrice($tenant, $plan));

        $product->update(['metadata' => []]);
        $this->assertSame(23275, $service->suggestedPlanPrice($tenant, $plan->fresh()));

        $service->setPlanOffering($tenant, $plan->fresh(), 30000, [], true);
        $this->assertSame(30000, $service->suggestedPlanPrice($tenant, $plan->fresh()));
    }

    public function test_suggested_product_price_falls_back_to_base(): void
    {
        $tenant = $this->createTenant();
        $product = OneTimeProduct::factory()->create(['metadata' => []]);
        $product->prices()->create(['currency_id' => app(CurrencyService::class)->getCurrency()->id, 'price' => 4900]);

        $this->assertSame(4900, app(PartnerCatalogService::class)->suggestedProductPrice($tenant, $product));
    }
```

Run: `php artisan test --compact --filter=PartnerCatalogServiceTest` — expected FAIL (methods undefined).

- [ ] **Step 2: Add the service methods**

In `app/Services/PartnerCatalogService.php`:

```php
    public function planOfferingFor(Tenant $tenant, Plan $plan): ?PartnerPlanOffering
    {
        return PartnerPlanOffering::where('tenant_id', $tenant->id)->where('plan_id', $plan->id)->first();
    }

    public function productOfferingFor(Tenant $tenant, OneTimeProduct $product): ?PartnerProductOffering
    {
        return PartnerProductOffering::where('tenant_id', $tenant->id)->where('one_time_product_id', $product->id)->first();
    }

    /**
     * The price the Pricing Settings form opens with (spec §7): what the
     * partner already set, else the catalog's suggested selling price, else
     * the platform price itself.
     */
    public function suggestedPlanPrice(Tenant $tenant, Plan $plan): int
    {
        $offering = $this->planOfferingFor($tenant, $plan);

        if ($offering !== null) {
            return (int) $offering->price;
        }

        $suggested = data_get($plan->product?->metadata, 'partner_suggested_price');

        return is_numeric($suggested) ? (int) $suggested : $this->planBasePrice($plan);
    }

    public function suggestedProductPrice(Tenant $tenant, OneTimeProduct $product): int
    {
        $offering = $this->productOfferingFor($tenant, $product);

        if ($offering !== null) {
            return (int) $offering->price;
        }

        $suggested = data_get($product->metadata, 'partner_suggested_price');

        return is_numeric($suggested) ? (int) $suggested : $this->productBasePrice($product);
    }
```

Run the service test — expected PASS.

- [ ] **Step 3: Page test**

Create `tests/Feature/Filament/Dashboard/PartnerPricingSettingsTest.php`:

```php
<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\PlanType;
use App\Constants\SubscriptionStatus;
use App\Constants\TenancyPermissionConstants;
use App\Filament\Dashboard\Pages\PartnerPricingSettings;
use App\Livewire\Filament\Dashboard\PartnerPlanPricingTable;
use App\Livewire\Filament\Dashboard\PartnerProductPricingTable;
use App\Models\OneTimeProduct;
use App\Models\PartnerPlanOffering;
use App\Models\PartnerProductOffering;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrencyService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class PartnerPricingSettingsTest extends FeatureTest
{
    private function activePartnerTenant(): Tenant
    {
        $tenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => Plan::factory()->create(['product_id' => $product->id])->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);

        return $tenant;
    }

    private function actAsPartner(Tenant $tenant, array $permissions = [TenancyPermissionConstants::PERMISSION_MANAGE_RESELLER_CATALOG]): User
    {
        $user = $this->createUser($tenant, $permissions);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        return $user;
    }

    private function visiblePlan(int $basePrice, array $metadata = []): Plan
    {
        $plan = Plan::factory()->create([
            'product_id' => Product::factory()->create(['metadata' => $metadata])->id,
            'type' => PlanType::FLAT_RATE->value,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $plan->prices()->create(['currency_id' => app(CurrencyService::class)->getCurrency()->id, 'price' => $basePrice]);

        return $plan;
    }

    private function visibleProduct(int $basePrice): OneTimeProduct
    {
        $product = OneTimeProduct::factory()->create(['is_active' => true, 'is_visible' => true]);
        $product->prices()->create(['currency_id' => app(CurrencyService::class)->getCurrency()->id, 'price' => $basePrice]);

        return $product;
    }

    public function test_access_requires_an_active_partner_and_the_catalog_permission(): void
    {
        $plain = $this->createTenant();
        $this->actAsPartner($plain);
        $this->assertFalse(PartnerPricingSettings::canAccess());

        $partner = $this->activePartnerTenant();
        $this->actAsPartner($partner, []);
        $this->assertFalse(PartnerPricingSettings::canAccess());

        $this->actAsPartner($partner);
        $this->assertTrue(PartnerPricingSettings::canAccess());
    }

    public function test_the_page_loads_and_names_both_tables(): void
    {
        $tenant = $this->activePartnerTenant();
        $this->actAsPartner($tenant);

        $this->get(PartnerPricingSettings::getUrl(tenant: $tenant))
            ->assertSuccessful()
            ->assertSee(__('Subscription packages'))
            ->assertSee(__('One-time reports'));
    }

    public function test_the_plan_table_shows_platform_price_your_price_and_margin(): void
    {
        $tenant = $this->activePartnerTenant();
        $this->actAsPartner($tenant);
        $plan = $this->visiblePlan(23275);
        PartnerPlanOffering::factory()->create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'price' => 47500, 'quota_overrides' => [], 'is_enabled' => true]);
        Plan::factory()->create(['is_visible' => false, 'product_id' => Product::factory()->create()->id]);

        Livewire::test(PartnerPlanPricingTable::class)
            ->assertCanSeeTableRecords([$plan])
            ->assertSee((string) money(23275, 'USD'))
            ->assertSee((string) money(47500, 'USD'))
            ->assertSee((string) money(24225, 'USD'))
            ->assertSee(__('Enabled'));
    }

    public function test_set_price_saves_an_offering_in_cents(): void
    {
        $tenant = $this->activePartnerTenant();
        $this->actAsPartner($tenant);
        $plan = $this->visiblePlan(23275, ['partner_suggested_price' => 47500]);

        Livewire::test(PartnerPlanPricingTable::class)
            ->callTableAction('set-price', $plan, data: ['price' => 500.5, 'is_enabled' => true])
            ->assertHasNoTableActionErrors();

        $offering = PartnerPlanOffering::where('tenant_id', $tenant->id)->where('plan_id', $plan->id)->firstOrFail();
        $this->assertSame(50050, $offering->price);
        $this->assertSame([], $offering->quota_overrides);
        $this->assertTrue($offering->is_enabled);
    }

    public function test_a_price_below_the_platform_price_is_refused(): void
    {
        $tenant = $this->activePartnerTenant();
        $this->actAsPartner($tenant);
        $plan = $this->visiblePlan(23275);

        Livewire::test(PartnerPlanPricingTable::class)
            ->callTableAction('set-price', $plan, data: ['price' => 100, 'is_enabled' => true])
            ->assertNotified(__('Could not save offering'));

        $this->assertNull(PartnerPlanOffering::where('tenant_id', $tenant->id)->where('plan_id', $plan->id)->first());
    }

    public function test_the_product_table_saves_a_product_offering(): void
    {
        $tenant = $this->activePartnerTenant();
        $this->actAsPartner($tenant);
        $product = $this->visibleProduct(4900);

        Livewire::test(PartnerProductPricingTable::class)
            ->assertCanSeeTableRecords([$product])
            ->callTableAction('set-price', $product, data: ['price' => 100, 'is_enabled' => false])
            ->assertHasNoTableActionErrors();

        $offering = PartnerProductOffering::where('tenant_id', $tenant->id)->where('one_time_product_id', $product->id)->firstOrFail();
        $this->assertSame(10000, $offering->price);
        $this->assertFalse($offering->is_enabled);
    }

    public function test_an_offering_never_leaks_across_tenants(): void
    {
        $tenantA = $this->activePartnerTenant();
        $tenantB = $this->activePartnerTenant();
        $plan = $this->visiblePlan(23275);
        $this->actAsPartner($tenantA);

        Livewire::test(PartnerPlanPricingTable::class)
            ->callTableAction('set-price', $plan, data: ['price' => 300, 'is_enabled' => true])
            ->assertHasNoTableActionErrors();

        $this->assertNotNull(PartnerPlanOffering::where('tenant_id', $tenantA->id)->where('plan_id', $plan->id)->first());
        $this->assertNull(PartnerPlanOffering::where('tenant_id', $tenantB->id)->where('plan_id', $plan->id)->first());
    }
}
```

`assertNotified` exists on Filament's Livewire test helpers; if this Filament version names it differently, check `vendor/filament/notifications/src/Testing/TestsNotifications.php` and use that name.

- [ ] **Step 4: Run to verify failure**

Run: `php artisan test --compact --filter=PartnerPricingSettingsTest` — expected FAIL (classes missing).

- [ ] **Step 5: Implement the page and the two tables**

`app/Filament/Dashboard/Pages/PartnerPricingSettings.php`:

```php
<?php

namespace App\Filament\Dashboard\Pages;

use App\Constants\TenancyPermissionConstants;
use App\Services\PartnerCapabilityService;
use App\Services\TenantPermissionService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Where a partner sets their selling prices against the platform's (spec §7).
 */
class PartnerPricingSettings extends Page
{
    protected string $view = 'filament.dashboard.pages.partner-pricing-settings';

    protected static string|null|BackedEnum $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $slug = 'pricing-settings';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return __('Partner');
    }

    public static function getNavigationLabel(): string
    {
        return __('Pricing Settings');
    }

    public function getTitle(): string
    {
        return __('Pricing Settings');
    }

    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();
        $user = auth()->user();

        if (! app(PartnerCapabilityService::class)->userCanAccessPartnerArea($tenant, $user)) {
            return false;
        }

        return app(TenantPermissionService::class)->tenantUserHasPermissionTo(
            $tenant,
            $user,
            TenancyPermissionConstants::PERMISSION_MANAGE_RESELLER_CATALOG,
        );
    }
}
```

`resources/views/filament/dashboard/pages/partner-pricing-settings.blade.php`:

```blade
<x-filament-panels::page>
    <p class="text-sm" style="color: var(--fp-muted)">
        {{ __('Your customers pay the prices below and settle in cash with you. A price can never go below the platform price; the difference is your margin.') }}
    </p>

    <x-filament::section :heading="__('Subscription packages')">
        @livewire('filament.dashboard.partner-plan-pricing-table')
    </x-filament::section>

    <x-filament::section :heading="__('One-time reports')">
        @livewire('filament.dashboard.partner-product-pricing-table')
    </x-filament::section>
</x-filament-panels::page>
```

`app/Livewire/Filament/Dashboard/PartnerPlanPricingTable.php`:

```php
<?php

namespace App\Livewire\Filament\Dashboard;

use App\Exceptions\PartnerOfferingValidationException;
use App\Models\Plan;
use App\Services\CurrencyService;
use App\Services\PartnerCatalogService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

class PartnerPlanPricingTable extends Component implements HasActions, HasForms, HasTable
{
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithTable;

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Plan::query()->where('is_visible', true)->where('is_active', true)->with('product'))
            ->columns([
                TextColumn::make('product.name')->label(__('Package')),
                TextColumn::make('platform_price')
                    ->label(__('Platform price'))
                    ->getStateUsing(fn (Plan $record): string => $this->money($this->catalog()->planBasePrice($record))),
                TextColumn::make('your_price')
                    ->label(__('Your price'))
                    ->getStateUsing(function (Plan $record): string {
                        $offering = $this->catalog()->planOfferingFor(Filament::getTenant(), $record);

                        return $offering === null ? __('Not set') : $this->money((int) $offering->price);
                    }),
                TextColumn::make('margin')
                    ->label(__('Margin'))
                    ->getStateUsing(function (Plan $record): string {
                        $offering = $this->catalog()->planOfferingFor(Filament::getTenant(), $record);

                        return $offering === null ? '—' : $this->money((int) $offering->price - $this->catalog()->planBasePrice($record));
                    }),
                TextColumn::make('offering_status')
                    ->label(__('Status'))
                    ->badge()
                    ->getStateUsing(function (Plan $record): string {
                        $offering = $this->catalog()->planOfferingFor(Filament::getTenant(), $record);

                        return match (true) {
                            $offering === null, ! $offering->is_enabled => __('Disabled'),
                            $this->catalog()->isPlanOfferingBelowMinimum($offering) => __('Below minimum'),
                            default => __('Enabled'),
                        };
                    })
                    ->color(fn (string $state): string => match ($state) {
                        __('Enabled') => 'success',
                        __('Below minimum') => 'danger',
                        default => 'gray',
                    }),
            ])
            ->recordActions([
                Action::make('set-price')
                    ->label(__('Set price'))
                    ->icon('heroicon-m-pencil-square')
                    ->schema(fn (Plan $record): array => [
                        TextInput::make('price')
                            ->label(__('Your price'))
                            ->prefix('$')
                            ->numeric()
                            ->step(0.01)
                            ->minValue(0)
                            ->required()
                            ->default($this->catalog()->suggestedPlanPrice(Filament::getTenant(), $record) / 100)
                            ->helperText(__('Platform price: :price. Your price cannot go below it.', ['price' => $this->money($this->catalog()->planBasePrice($record))])),
                        Toggle::make('is_enabled')
                            ->label(__('Resell this package'))
                            ->default($this->catalog()->planOfferingFor(Filament::getTenant(), $record)?->is_enabled ?? true),
                    ])
                    ->action(function (array $data, Plan $record): void {
                        try {
                            $this->catalog()->setPlanOffering(
                                Filament::getTenant(),
                                $record,
                                (int) round(((float) $data['price']) * 100),
                                [],
                                (bool) $data['is_enabled'],
                            );
                        } catch (PartnerOfferingValidationException $e) {
                            Notification::make()->danger()->title(__('Could not save offering'))->body($e->getMessage())->persistent()->send();

                            return;
                        }

                        Notification::make()->success()->title(__('Price saved'))->send();
                    }),
            ]);
    }

    public function render(): View
    {
        return view('livewire.filament.dashboard.partner-pricing-table');
    }

    private function catalog(): PartnerCatalogService
    {
        return app(PartnerCatalogService::class);
    }

    private function money(int $cents): string
    {
        return (string) money($cents, app(CurrencyService::class)->getCurrency()->code);
    }
}
```

`PartnerProductPricingTable.php` — identical shape over `OneTimeProduct::query()->where('is_visible', true)->where('is_active', true)`, column `name` labelled `__('Report')`, and the `product*` service methods (`productBasePrice`, `productOfferingFor`, `suggestedProductPrice`, `isProductOfferingBelowMinimum`, `setProductOffering`), toggle label `__('Resell this report')`.

Create `resources/views/livewire/filament/dashboard/partner-pricing-table.blade.php`:

```blade
<div>
    {{ $this->table }}
</div>
```

Delete the two old resource directories and their tests:

```bash
git rm -rq app/Filament/Dashboard/Resources/PartnerPlanCatalog app/Filament/Dashboard/Resources/PartnerProductCatalog tests/Feature/Filament/Dashboard/PartnerPlanCatalogResourceTest.php tests/Feature/Filament/Dashboard/PartnerProductCatalogResourceTest.php
```

Update `DashboardMenuItemsTest::test_a_partner_sees_the_partner_group_and_no_rewards` to expect `['Referrals', 'Pricing Settings']` for now, and add `'Pricing Settings' => [PartnerPricingSettings::class]` to a new partner-only page-load test:

```php
    public function test_each_partner_menu_item_page_loads(): void
    {
        [, $tenant] = $this->partnerUserAndTenant();

        $this->get(PartnerPricingSettings::getUrl(tenant: $tenant))->assertSuccessful();
    }
```

- [ ] **Step 6: Run**

Run: `php artisan test --compact --filter="PartnerPricingSettingsTest|PartnerCatalogServiceTest|DashboardMenuItemsTest"`
Expected: PASS. If `callTableAction` cannot find `set-price` because the action lives on a Livewire component rather than a resource page, that is the same API the deleted `ListPartnerPlanCatalog` test used; the difference is only the component class — keep going.

Then `grep -rn "PartnerPlanCatalog\|PartnerProductCatalog" app tests resources` — expected empty.

- [ ] **Step 7: Commit**

```bash
git add app/Services/PartnerCatalogService.php app/Filament/Dashboard/Pages/PartnerPricingSettings.php resources/views/filament/dashboard/pages/partner-pricing-settings.blade.php app/Livewire/Filament/Dashboard/PartnerPlanPricingTable.php app/Livewire/Filament/Dashboard/PartnerProductPricingTable.php resources/views/livewire/filament/dashboard/partner-pricing-table.blade.php tests/Feature/Filament/Dashboard/PartnerPricingSettingsTest.php tests/Feature/Services/PartnerCatalogServiceTest.php tests/Feature/Filament/Dashboard/DashboardMenuItemsTest.php
git commit -m "feat(partner): a Pricing Settings page in place of the two catalog resources"
```

---

### Task 12: Every attributed buyer's purchase is stamped with their partner

**Files:**
- Modify: `app/Services/CashPayments/PurchaseSnapshotService.php:28-52`
- Modify: `tests/Feature/Services/CashPayments/PurchaseSnapshotServiceTest.php:112-153,215-245`, `tests/Feature/Services/CashPayments/PartnerPlanCashCheckoutTest.php:225-252`
- Modify: spec §8.2 is already written for this; base spec untouched.

**Interfaces:**
- Consumes: `PartnerPricingResolver::resolvePartnerTenant(User): ?Tenant` (null when unattributed or the partner lapsed).
- Produces: `forPlan()`/`forProduct()` return `partner_tenant_id` = the buyer's active partner regardless of offering; `quota_snapshot`/`base_price_snapshot` unchanged.

- [ ] **Step 1: Flip the three snapshot tests and the reuse test**

In `PurchaseSnapshotServiceTest`:
- `test_a_disabled_offering_makes_it_a_direct_sale` → rename `test_a_disabled_offering_still_stamps_the_partner_at_base_quota`; replace `$this->assertNull($snapshot['partner_tenant_id']);` with `$this->assertSame($partnerTenant->id, $snapshot['partner_tenant_id']);` (keep the quota assertion).
- `test_an_offering_that_fell_below_the_admin_minimum_makes_it_a_direct_sale` → rename `…_still_stamps_the_partner`; same replacement for the null assertion at line 151.
- `test_no_partner_is_stamped_when_the_offline_provider_is_inactive` → rename `test_the_partner_is_stamped_even_when_the_offline_provider_is_inactive`; replace the null assertion with `assertSame($partnerTenant->id, …)` and update the comment to say the stamp is informational for a gateway order (spec §8.2).
- Leave `test_an_unattributed_buyer…` and `test_a_lapsed_partner_plan…` asserting null.

In `PartnerPlanCashCheckoutTest` line 250, replace `$this->assertNull($reused->fresh()->partner_tenant_id);` with `$this->assertSame($partnerTenant->id, $reused->fresh()->partner_tenant_id);` and add above it a comment: `// Base-priced, but still the partner's customer (spec §8.2).`

Run: `php artisan test --compact --filter="PurchaseSnapshotServiceTest|PartnerPlanCashCheckoutTest"` — expected FAIL on the four renamed/edited tests.

- [ ] **Step 2: Implement**

In `PurchaseSnapshotService`, both `forPlan()` and `forProduct()` set:

```php
            'partner_tenant_id' => $this->partnerTenantFor($user)?->id,
```

instead of `$offering?->tenant_id`, and replace the class-level comment (or add one) with:

```php
/**
 * partner_tenant_id names the partner responsible for this buyer (spec §8.2):
 * their active partner tenant whether or not a usable offering priced the
 * item. Price and quota still come from the offering only when one is usable,
 * so a base-priced sale stays base-priced — it is simply the partner, not an
 * admin, who confirms the cash for it.
 */
```

- [ ] **Step 3: Run the cash-payment and checkout slice**

Run: `php artisan test --compact --filter="CashPayments|Checkout|Snapshot|PartnerOrder|OrderPartnerReporting|CustomerOrderPartnerVisibility"`
Expected: PASS. Any other test that now sees a partner on a base-priced attributed order is asserting the old rule; update it the same way, citing spec §8.2.

- [ ] **Step 4: Commit**

```bash
git add app/Services/CashPayments/PurchaseSnapshotService.php tests/Feature/Services/CashPayments/PurchaseSnapshotServiceTest.php tests/Feature/Services/CashPayments/PartnerPlanCashCheckoutTest.php
git commit -m "feat(partner): stamp the responsible partner on every attributed buyer's purchase"
```

---
### Task 13: The partner's Orders section

**Files:**
- Rename: `app/Filament/Dashboard/Resources/PartnerOrderApprovals/` → `app/Filament/Dashboard/Resources/PartnerOrders/` (`PartnerOrderResource.php`, `Pages/ListPartnerOrders.php`, new `Pages/ViewPartnerOrder.php`)
- Rename: `tests/Feature/Filament/Dashboard/PartnerOrderApprovalResourceTest.php` → `PartnerOrderResourceTest.php` (rewrite)
- Modify: `tests/Feature/Filament/Dashboard/DashboardMenuItemsTest.php`

**Interfaces:**
- Consumes: `OrderApprovalService::isPendingCashOrder()/amountDue()/approveAsPartner()/rejectAsPartner()`, `PartnerCapabilityService::userCanAccessPartnerArea()`, `OrderStatusMapper`, `Dashboard\Resources\Orders\OrderResource::orderItems()`.
- Produces: `PartnerOrderResource` (slug `partner-orders`, label `Orders`, group `Partner`, sort 2) listing every order of the tenant's referred customers with tabs `pending` (default), `all`, `approved`, `rejected`; Task 14 links to `PartnerOrderResource::getUrl('index', ['activeTab' => 'pending'], tenant: $tenant)`.

- [ ] **Step 1: Move the files**

```bash
git mv app/Filament/Dashboard/Resources/PartnerOrderApprovals app/Filament/Dashboard/Resources/PartnerOrders
git mv app/Filament/Dashboard/Resources/PartnerOrders/PartnerOrderApprovalResource.php app/Filament/Dashboard/Resources/PartnerOrders/PartnerOrderResource.php
git mv app/Filament/Dashboard/Resources/PartnerOrders/Pages/ListPartnerOrderApprovals.php app/Filament/Dashboard/Resources/PartnerOrders/Pages/ListPartnerOrders.php
git mv tests/Feature/Filament/Dashboard/PartnerOrderApprovalResourceTest.php tests/Feature/Filament/Dashboard/PartnerOrderResourceTest.php
```

- [ ] **Step 2: Rewrite the test**

Replace `tests/Feature/Filament/Dashboard/PartnerOrderResourceTest.php` with:

```php
<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\OrderStatus;
use App\Constants\PaymentProviderConstants;
use App\Constants\SubscriptionStatus;
use App\Constants\TenancyPermissionConstants;
use App\Filament\Dashboard\Resources\PartnerOrders\Pages\ListPartnerOrders;
use App\Filament\Dashboard\Resources\PartnerOrders\PartnerOrderResource;
use App\Models\Order;
use App\Models\OrderApproval;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class PartnerOrderResourceTest extends FeatureTest
{
    private function activePartnerTenant(): Tenant
    {
        $tenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => Plan::factory()->create(['product_id' => $product->id])->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);

        return $tenant;
    }

    private function actAsPartner(Tenant $tenant, array $permissions = [TenancyPermissionConstants::PERMISSION_MANAGE_PARTNER_ORDERS]): User
    {
        $user = $this->createUser($tenant, $permissions);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        return $user;
    }

    private function referredCustomer(Tenant $partnerTenant): array
    {
        $customerTenant = $this->createTenant();
        $customer = $this->createUser($customerTenant, [], ['partner_tenant_id' => $partnerTenant->id, 'partner_attributed_at' => now()]);

        return [$customer, $customerTenant];
    }

    private function order(User $customer, Tenant $customerTenant, array $overrides = []): Order
    {
        return Order::factory()->create($overrides + [
            'user_id' => $customer->id,
            'tenant_id' => $customerTenant->id,
            'partner_tenant_id' => $customer->partner_tenant_id,
            'status' => OrderStatus::PENDING->value,
            'is_local' => true,
            'total_amount' => 6900,
            'base_price_snapshot' => 4900,
        ]);
    }

    public function test_access_requires_an_active_partner_and_the_orders_permission(): void
    {
        $this->actAsPartner($this->createTenant());
        $this->assertFalse(PartnerOrderResource::canAccess());

        $partner = $this->activePartnerTenant();
        $this->actAsPartner($partner, []);
        $this->assertFalse(PartnerOrderResource::canAccess());

        $this->actAsPartner($partner);
        $this->assertTrue(PartnerOrderResource::canAccess());
    }

    public function test_it_lists_cash_and_gateway_orders_of_referred_customers_only(): void
    {
        $partner = $this->activePartnerTenant();
        $this->actAsPartner($partner);
        [$customer, $customerTenant] = $this->referredCustomer($partner);
        $cash = $this->order($customer, $customerTenant);
        $stripe = PaymentProvider::where('slug', 'stripe')->firstOrFail();
        $gateway = $this->order($customer, $customerTenant, ['is_local' => false, 'status' => OrderStatus::SUCCESS->value, 'payment_provider_id' => $stripe->id]);
        $legacyStamped = Order::factory()->create(['user_id' => $this->createUser()->id, 'tenant_id' => $this->createTenant()->id, 'partner_tenant_id' => $partner->id, 'status' => OrderStatus::SUCCESS->value]);

        $otherPartner = $this->activePartnerTenant();
        [$otherCustomer, $otherTenant] = $this->referredCustomer($otherPartner);
        $foreign = $this->order($otherCustomer, $otherTenant);
        $direct = Order::factory()->create(['user_id' => $this->createUser()->id, 'tenant_id' => $this->createTenant()->id, 'status' => OrderStatus::PENDING->value, 'is_local' => true]);

        Livewire::test(ListPartnerOrders::class, ['activeTab' => 'all'])
            ->assertCanSeeTableRecords([$cash, $gateway, $legacyStamped])
            ->assertCanNotSeeTableRecords([$foreign, $direct]);
    }

    public function test_the_pending_tab_is_the_default_and_shows_only_pending_cash_orders(): void
    {
        $partner = $this->activePartnerTenant();
        $this->actAsPartner($partner);
        [$customer, $customerTenant] = $this->referredCustomer($partner);
        $pending = $this->order($customer, $customerTenant);
        $approved = $this->order($customer, $customerTenant, ['status' => OrderStatus::SUCCESS->value]);
        $gatewayPending = $this->order($customer, $customerTenant, ['is_local' => false]);

        Livewire::test(ListPartnerOrders::class)
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$approved, $gatewayPending]);
    }

    public function test_it_shows_base_price_your_price_and_margin_for_a_cash_order(): void
    {
        $partner = $this->activePartnerTenant();
        $this->actAsPartner($partner);
        [$customer, $customerTenant] = $this->referredCustomer($partner);
        $this->order($customer, $customerTenant);

        Livewire::test(ListPartnerOrders::class)
            ->assertSee($customer->email)
            ->assertSee((string) money(4900, 'USD'))
            ->assertSee((string) money(6900, 'USD'))
            ->assertSee((string) money(2000, 'USD'))
            ->assertSee(__('Cash'));
    }

    public function test_approve_confirms_the_cash_and_records_the_partner_decision(): void
    {
        $partner = $this->activePartnerTenant();
        $user = $this->actAsPartner($partner);
        [$customer, $customerTenant] = $this->referredCustomer($partner);
        $order = $this->order($customer, $customerTenant);

        Livewire::test(ListPartnerOrders::class)
            ->callTableAction('approve', $order, data: ['note' => 'Cash received'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(OrderStatus::SUCCESS->value, $order->fresh()->status);
        $approval = OrderApproval::where('order_id', $order->id)->firstOrFail();
        $this->assertSame('partner', $approval->actor_type);
        $this->assertSame($user->id, $approval->actor_user_id);
        $this->assertSame('Cash received', $approval->note);
    }

    public function test_reject_marks_the_order_rejected(): void
    {
        $partner = $this->activePartnerTenant();
        $this->actAsPartner($partner);
        [$customer, $customerTenant] = $this->referredCustomer($partner);
        $order = $this->order($customer, $customerTenant);

        Livewire::test(ListPartnerOrders::class)
            ->callTableAction('reject', $order, data: ['note' => null])
            ->assertHasNoTableActionErrors();

        $this->assertSame(OrderStatus::REJECTED->value, $order->fresh()->status);
    }

    public function test_approve_is_hidden_on_a_gateway_or_settled_order(): void
    {
        $partner = $this->activePartnerTenant();
        $this->actAsPartner($partner);
        [$customer, $customerTenant] = $this->referredCustomer($partner);
        $gateway = $this->order($customer, $customerTenant, ['is_local' => false]);
        $settled = $this->order($customer, $customerTenant, ['status' => OrderStatus::SUCCESS->value]);

        Livewire::test(ListPartnerOrders::class, ['activeTab' => 'all'])
            ->assertTableActionHidden('approve', $gateway)
            ->assertTableActionHidden('approve', $settled)
            ->assertTableActionHidden('reject', $gateway);
    }

    public function test_another_partners_order_cannot_be_approved_by_url(): void
    {
        $partner = $this->activePartnerTenant();
        $this->actAsPartner($partner);
        $otherPartner = $this->activePartnerTenant();
        [$otherCustomer, $otherTenant] = $this->referredCustomer($otherPartner);
        $foreign = $this->order($otherCustomer, $otherTenant);

        $this->withExceptionHandling();
        $this->get(PartnerOrderResource::getUrl('view', ['record' => $foreign], tenant: $partner))->assertNotFound();
        $this->assertSame(OrderStatus::PENDING->value, $foreign->fresh()->status);
    }

    public function test_the_view_page_loads_for_an_own_order(): void
    {
        $partner = $this->activePartnerTenant();
        $this->actAsPartner($partner);
        [$customer, $customerTenant] = $this->referredCustomer($partner);
        $order = $this->order($customer, $customerTenant);

        $this->get(PartnerOrderResource::getUrl('view', ['record' => $order], tenant: $partner))
            ->assertSuccessful()
            ->assertSee($order->uuid)
            ->assertSee($customer->email);
    }
}
```

`assertTableActionHidden` is the table-flavoured helper the existing tests' generation uses; if this Filament version lacks it, use `assertActionHidden(TestAction::make('approve')->table($gateway))` from `Filament\Actions\Testing\TestAction`.

- [ ] **Step 3: Run to verify failure**

Run: `php artisan test --compact --filter=PartnerOrderResourceTest` — expected FAIL (old class names, no `view` page, no tabs, gateway/base orders filtered out).

- [ ] **Step 4: Rewrite the resource**

`app/Filament/Dashboard/Resources/PartnerOrders/PartnerOrderResource.php`:

```php
<?php

namespace App\Filament\Dashboard\Resources\PartnerOrders;

use App\Constants\TenancyPermissionConstants;
use App\Filament\Dashboard\Resources\Orders\OrderResource;
use App\Filament\Dashboard\Resources\PartnerOrders\Pages\ListPartnerOrders;
use App\Filament\Dashboard\Resources\PartnerOrders\Pages\ViewPartnerOrder;
use App\Mapper\OrderStatusMapper;
use App\Models\Order;
use App\Services\CashPayments\OrderApprovalService;
use App\Services\CurrencyService;
use App\Services\PartnerCapabilityService;
use App\Services\TenantPermissionService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every order placed by the customers this partner referred (spec §8): the
 * partner is responsible for them, confirms their cash, and can see their
 * gateway purchases too. Approval itself stays in OrderApprovalService.
 */
class PartnerOrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    /** Distinct from the customer-facing OrderResource, which owns this model's default slug. */
    protected static ?string $slug = 'partner-orders';

    protected static ?int $navigationSort = 2;

    /** Scoped on who referred the buyer, not on tenant_id (who bought). */
    protected static bool $isScopedToTenant = false;

    public static function getNavigationGroup(): ?string
    {
        return __('Partner');
    }

    public static function getNavigationLabel(): string
    {
        return __('Orders');
    }

    public static function getModelLabel(): string
    {
        return __('Order');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Orders');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function getEloquentQuery(): Builder
    {
        $tenantId = Filament::getTenant()?->getKey() ?? 0;

        return parent::getEloquentQuery()
            ->where(function (Builder $query) use ($tenantId): void {
                $query->where('orders.partner_tenant_id', $tenantId)
                    ->orWhereHas('user', fn (Builder $user) => $user->where('users.partner_tenant_id', $tenantId));
            })
            ->with(['user', 'currency', 'paymentProvider', 'partnerTenant']);
    }

    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();
        $user = auth()->user();

        if (! app(PartnerCapabilityService::class)->userCanAccessPartnerArea($tenant, $user)) {
            return false;
        }

        return app(TenantPermissionService::class)->tenantUserHasPermissionTo(
            $tenant,
            $user,
            TenancyPermissionConstants::PERMISSION_MANAGE_PARTNER_ORDERS,
        );
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.email')->label(__('Customer'))->searchable(),
                TextColumn::make('type')->label(__('Type'))->badge(),
                TextColumn::make('item')
                    ->label(__('Item'))
                    ->getStateUsing(fn (Order $record): string => self::itemName($record)),
                TextColumn::make('payment')
                    ->label(__('Payment'))
                    ->getStateUsing(fn (Order $record): string => $record->is_local ? __('Cash') : ($record->paymentProvider?->name ?? '—')),
                TextColumn::make('base_price_snapshot')
                    ->label(__('Base price'))
                    ->getStateUsing(fn (Order $record): string => self::ownsCashOrder($record) && $record->base_price_snapshot !== null
                        ? self::formatMoney((int) $record->base_price_snapshot)
                        : '—'),
                TextColumn::make('total_amount')
                    ->label(__('Your price'))
                    ->getStateUsing(fn (Order $record): string => self::formatMoney(self::amountDue($record))),
                TextColumn::make('margin')
                    ->label(__('Margin'))
                    ->getStateUsing(fn (Order $record): string => self::ownsCashOrder($record) && $record->base_price_snapshot !== null
                        ? self::formatMoney(self::amountDue($record) - (int) $record->base_price_snapshot)
                        : '—'),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(fn (Order $record, OrderStatusMapper $mapper): string => $mapper->mapColor($record->status))
                    ->formatStateUsing(fn (string $state, OrderStatusMapper $mapper): string => $mapper->mapForDisplay($state)),
                TextColumn::make('created_at')->label(__('Created'))->dateTime(config('app.datetime_format'))->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('approve')
                    ->label(__('Approve'))
                    ->icon('heroicon-m-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(__('Confirm you have received the cash for this order. This activates the customer immediately.'))
                    ->schema([Textarea::make('note')->label(__('Internal note'))->maxLength(1000)])
                    ->visible(fn (Order $record): bool => self::canDecide($record))
                    ->action(fn (Order $record, array $data) => self::decide($record, $data, approve: true)),
                Action::make('reject')
                    ->label(__('Reject'))
                    ->icon('heroicon-m-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->schema([Textarea::make('note')->label(__('Internal note'))->maxLength(1000)])
                    ->visible(fn (Order $record): bool => self::canDecide($record))
                    ->action(fn (Order $record, array $data) => self::decide($record, $data, approve: false)),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Order'))
                ->schema([
                    TextEntry::make('uuid')->label('ID')->copyable(),
                    TextEntry::make('user.email')->label(__('Customer')),
                    TextEntry::make('type')->label(__('Type'))->badge(),
                    TextEntry::make('payment')->label(__('Payment'))
                        ->getStateUsing(fn (Order $record): string => $record->is_local ? __('Cash') : ($record->paymentProvider?->name ?? '—')),
                    TextEntry::make('base_price_snapshot')->label(__('Base price'))
                        ->getStateUsing(fn (Order $record): string => self::ownsCashOrder($record) && $record->base_price_snapshot !== null ? self::formatMoney((int) $record->base_price_snapshot) : '—'),
                    TextEntry::make('total_amount')->label(__('Your price'))
                        ->getStateUsing(fn (Order $record): string => self::formatMoney(self::amountDue($record))),
                    TextEntry::make('status')->label(__('Status'))->badge()
                        ->color(fn (Order $record, OrderStatusMapper $mapper): string => $mapper->mapColor($record->status))
                        ->formatStateUsing(fn (string $state, OrderStatusMapper $mapper): string => $mapper->mapForDisplay($state)),
                    TextEntry::make('created_at')->label(__('Created'))->dateTime(config('app.datetime_format')),
                ])->columns(3),
            Section::make(__('Items'))
                ->schema(fn (Order $record): array => OrderResource::orderItems($record))
                ->visible(fn (Order $record): bool => $record->items()->exists()),
        ]);
    }

    private static function itemName(Order $record): string
    {
        $product = $record->items()->first()?->oneTimeProduct?->name;

        return $product ?? $record->subscription?->plan?->product?->name ?? '—';
    }

    private static function ownsCashOrder(Order $record): bool
    {
        return (bool) $record->is_local && (int) $record->partner_tenant_id === (int) (Filament::getTenant()?->getKey() ?? 0);
    }

    private static function canDecide(Order $record): bool
    {
        return self::ownsCashOrder($record) && app(OrderApprovalService::class)->isPendingCashOrder($record);
    }

    private static function decide(Order $order, array $data, bool $approve): void
    {
        $service = app(OrderApprovalService::class);
        $note = $data['note'] ?? null;

        try {
            $changed = $approve
                ? $service->approveAsPartner($order, auth()->user(), Filament::getTenant(), $note)
                : $service->rejectAsPartner($order, auth()->user(), Filament::getTenant(), $note);
        } catch (AuthorizationException $e) {
            Notification::make()->danger()->title(__('Not allowed'))->body($e->getMessage())->persistent()->send();

            return;
        }

        if (! $changed) {
            Notification::make()->warning()->title(__('This order is no longer pending.'))->send();

            return;
        }

        Notification::make()->success()->title($approve ? __('Order approved') : __('Order rejected'))->send();
    }

    private static function amountDue(Order $order): int
    {
        return app(OrderApprovalService::class)->amountDue($order);
    }

    private static function formatMoney(int $amount): string
    {
        return money($amount, app(CurrencyService::class)->getCurrency()->code);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPartnerOrders::route('/'),
            'view' => ViewPartnerOrder::route('/{record}'),
        ];
    }
}
```

`Pages/ListPartnerOrders.php`:

```php
<?php

namespace App\Filament\Dashboard\Resources\PartnerOrders\Pages;

use App\Constants\OrderStatus;
use App\Filament\Dashboard\Resources\PartnerOrders\PartnerOrderResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListPartnerOrders extends ListRecords
{
    protected static string $resource = PartnerOrderResource::class;

    public function getTabs(): array
    {
        return [
            'pending' => Tab::make(__('Pending cash'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', OrderStatus::PENDING->value)->where('is_local', true)),
            'all' => Tab::make(__('All')),
            'approved' => Tab::make(__('Approved'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', OrderStatus::SUCCESS->value)),
            'rejected' => Tab::make(__('Rejected'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', OrderStatus::REJECTED->value)),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'pending';
    }
}
```

`Pages/ViewPartnerOrder.php`:

```php
<?php

namespace App\Filament\Dashboard\Resources\PartnerOrders\Pages;

use App\Filament\Dashboard\Resources\PartnerOrders\PartnerOrderResource;
use Filament\Resources\Pages\ViewRecord;

class ViewPartnerOrder extends ViewRecord
{
    protected static string $resource = PartnerOrderResource::class;
}
```

`Order::getRouteKeyName()` returns `uuid`, so `getUrl('view', ['record' => $order])` resolves by uuid and a foreign order 404s through `getEloquentQuery()`.

Update `DashboardMenuItemsTest::test_a_partner_sees_the_partner_group_and_no_rewards` to `['Referrals', 'Orders', 'Pricing Settings']` and add `$this->get(PartnerOrderResource::getUrl(tenant: $tenant))->assertSuccessful();` to `test_each_partner_menu_item_page_loads`.

- [ ] **Step 5: Run**

Run: `php artisan test --compact --filter="PartnerOrderResourceTest|DashboardMenuItemsTest|PartnerOrderOwnershipTest|CashPaymentMailTest"`
Expected: PASS. Then `grep -rn "PartnerOrderApproval" app tests resources` — expected empty.

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Dashboard/Resources/PartnerOrders tests/Feature/Filament/Dashboard/PartnerOrderResourceTest.php tests/Feature/Filament/Dashboard/DashboardMenuItemsTest.php
git commit -m "feat(partner): an Orders section listing every referred customer's orders with cash approval"
```

---

### Task 14: In-app notification for each new partner order

**Files:**
- Create: `database/migrations/2026_09_07_000002_create_notifications_table.php`
- Modify: `app/Providers/Filament/DashboardPanelProvider.php` (add `->databaseNotifications()`)
- Create: `app/Notifications/PartnerNewOrder.php`
- Modify: `app/Listeners/Order/NotifyPartnerOfPendingCashOrder.php:44-48`
- Modify: `tests/Feature/Mail/CashPaymentMailTest.php` (add one test)

**Interfaces:**
- Consumes: `PartnerOrderResource::getUrl('index', ['activeTab' => 'pending'], tenant: $partnerTenant)`; `User` uses `Illuminate\Notifications\Notifiable` (verify with `grep -n Notifiable app/Models/User.php`; add the trait if absent).
- Produces: `App\Notifications\PartnerNewOrder(Order $order, Tenant $partnerTenant, int $amountDue)` on the `database` channel, one per recipient the listener already emails.

- [ ] **Step 1: Add the failing test**

Append to `CashPaymentMailTest` (imports `App\Notifications\PartnerNewOrder`, `Illuminate\Support\Facades\Notification`):

```php
    public function test_the_partner_also_gets_an_in_app_notification(): void
    {
        Mail::fake();
        Notification::fake();

        $partnerTenant = $this->activePartnerTenant();
        $partnerUser = $this->createUser($partnerTenant, [TenancyPermissionConstants::PERMISSION_MANAGE_PARTNER_ORDERS]);
        $bystander = $this->createUser($partnerTenant, []);
        $customerTenant = $this->createTenant();
        $customer = $this->createUser($customerTenant);

        app(OrderService::class)->create(
            $customer,
            $customerTenant,
            totalAmount: 6900,
            currency: Currency::where('code', 'USD')->first(),
            isLocal: true,
            snapshot: ['partner_tenant_id' => $partnerTenant->id, 'base_price_snapshot' => 4900],
        );

        Notification::assertSentTo($partnerUser, PartnerNewOrder::class, function (PartnerNewOrder $notification, array $channels) use ($partnerUser) {
            $data = $notification->toDatabase($partnerUser);

            return $channels === ['database']
                && str_contains($data['body'], (string) money(6900, 'USD'))
                && str_contains(json_encode($data), 'partner-orders');
        });
        Notification::assertNotSentTo($bystander, PartnerNewOrder::class);
    }
```

Run: `php artisan test --compact --filter="CashPaymentMailTest::test_the_partner_also_gets_an_in_app_notification"` — expected FAIL (class missing).

- [ ] **Step 2: Migration and panel**

`database/migrations/2026_09_07_000002_create_notifications_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
```

In `DashboardPanelProvider::panel()` add `->databaseNotifications()` right after `->sidebarCollapsibleOnDesktop()`.

- [ ] **Step 3: The notification**

```php
<?php

namespace App\Notifications;

use App\Filament\Dashboard\Resources\PartnerOrders\PartnerOrderResource;
use App\Models\Order;
use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * The bell counterpart of the PartnerNewPendingOrder mail (spec §8.3).
 */
class PartnerNewOrder extends Notification
{
    use Queueable;

    public function __construct(
        public Order $order,
        public Tenant $partnerTenant,
        public int $amountDue,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        $currency = $this->order->currency?->code ?? config('app.default_currency');

        return FilamentNotification::make()
            ->title(__('New order from :customer', ['customer' => $this->order->user?->email ?? __('a customer')]))
            ->body(__(':amount, :method. Approve it once you have the cash.', [
                'amount' => money($this->amountDue, $currency),
                'method' => $this->order->is_local ? __('cash') : __('card'),
            ]))
            ->icon('heroicon-o-banknotes')
            ->actions([
                Action::make('view')
                    ->label(__('View orders'))
                    ->url(PartnerOrderResource::getUrl('index', ['activeTab' => 'pending'], tenant: $this->partnerTenant)),
            ])
            ->getDatabaseMessage();
    }
}
```

- [ ] **Step 4: Send it from the listener**

In `NotifyPartnerOfPendingCashOrder::handle()`, inside the recipients loop after the mail send:

```php
            $recipient->notify(new PartnerNewOrder($event->order, $partnerTenant, $amountDue));
```

(import `App\Notifications\PartnerNewOrder`).

- [ ] **Step 5: Run**

Run: `php artisan migrate && php artisan test --compact --filter="CashPaymentMailTest|DashboardMenuItemsTest"`
Expected: PASS (the migration runs in the test process on its own via `migrate:fresh`).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_09_07_000002_create_notifications_table.php app/Providers/Filament/DashboardPanelProvider.php app/Notifications/PartnerNewOrder.php app/Listeners/Order/NotifyPartnerOfPendingCashOrder.php tests/Feature/Mail/CashPaymentMailTest.php
git commit -m "feat(partner): in-app notification for each new referred-customer order"
```

---

### Task 15: Whole-branch verification

**Files:** none new. Fix-ups only where the checks below demand them.

- [ ] **Step 1: Formatting and static analysis**

```bash
vendor/bin/pint
vendor/bin/pint --test
vendor/bin/phpstan analyse
```
Expected: pint clean; phpstan reports only the one pre-existing error (compare against `git stash; vendor/bin/phpstan analyse; git stash pop` if unsure which is pre-existing).

- [ ] **Step 2: Full backend suite**

Run: `php artisan test --compact`
Expected: all green. Known follow-ups if red: a test still importing `PartnerReferralLink`/`SessionConstants::PARTNER_REFERRAL_CODE` (Task 5's grep missed it), a menu test expecting the `Referrals` group, a snapshot test asserting the old direct-sale rule (Task 12).

- [ ] **Step 3: Frontend gate**

From `frontend/`: `npm run check` — expected clean.

- [ ] **Step 4: Manual smoke in the dev stack**

1. Admin → Referral Settings: enabled. Assign the `audit-partner` plan to a tenant via Admin → Subscriptions.
2. As that tenant: sidebar shows **Partner → Referrals, Orders, Pricing Settings**; Subscriptions shows no Change Plan; copy the `?rc=REF-…` link.
3. In a private window: open the link → pricing page shows the partner banner and the partner's prices for any package enabled in Pricing Settings; close and reopen the window → still the partner's prices; open another partner's link → unchanged.
4. Register from that window, log out, log in again → prices unchanged after every step; the response carries `fp_rc`.
5. Buy a package with the Offline provider → the partner receives the email and the bell notification, sees the order under Orders → Pending cash, approves it.

- [ ] **Step 5: Final commit if anything changed**

```bash
git add -u .
git commit -m "chore(partner): pint/phpstan fix-ups after the partner attribution and packages work"
```
