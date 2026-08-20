# Audit Types Manual Pricing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Diagnostic a real paid tier (nominal $5), show tier price in the admin/dashboard tier labels, and gate the public subscription-pricing page behind login.

**Architecture:** All four Audit Types are already backed generically by `config('pricing.tiers')` (seeded by `AuditMonetizationSeeder`, priced via `AuditEntitlementService::tierPriceCents()`, purchased via the existing dashboard tier-picker and `HandleAuditTierOrder`). Diagnostic currently has no entry there and is instead funded by a lifetime free-run quota (`config('audit.free_reports_limit')`). This plan adds Diagnostic to the pricing catalog and turns the free-quota default to zero — which makes every existing generic code path (seeding, purchasability, `AuditRequestService::routeVerified()`'s `AWAITING_PAYMENT` branch) treat Diagnostic like any other paid tier with no new branching logic. A new `AuditTier::labelWithPrice()` method is introduced for the two admin/dashboard surfaces that need a price-annotated label, without touching the plain `label()` used elsewhere.

**Tech Stack:** Laravel 13 / PHP 8.4, PHPUnit (classic TestCase, no Pest), Filament 5.

**Spec:** `docs/superpowers/specs/2026-08-20-audit-types-manual-pricing-design.md`

## Global Constraints

- Backend commands run inside Docker (`docker compose exec laravel.test ...`); do not run `php artisan` or `composer` on the host.
- Never run two `php artisan test` invocations concurrently.
- `vendor/bin/pint --test` (not `--dirty`) must be clean before considering any task done.
- Prices are in cents throughout; the nominal Diagnostic price is **500** ($5).
- `frontend/src/data/pricing.json` is committed and CI-checked for drift against `config/pricing.php` (`.github/workflows/pricing-drift.yml` runs `php artisan app:export-pricing --check`) — any change to `config/pricing.php` requires regenerating and committing this file in the same task.
- Diagnostic's renamed label is **"Diagnostic Report"** (was "Free Diagnostic").
- `AuditTier::label()` stays plain (no price) everywhere it's already used; only the two new call sites in this plan use `labelWithPrice()`.

---

### Task 1: Add the Diagnostic tier to the pricing catalog and re-export frontend pricing data

**Files:**
- Modify: `config/pricing.php`
- Modify: `frontend/src/data/pricing.json` (regenerated, not hand-edited)
- Test: `tests/Feature/Seeders/AuditMonetizationSeederTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `config('pricing.tiers.audit-diagnostic')` — `['tier' => 'diagnostic', 'name' => 'Diagnostic Report', 'description' => ..., 'price' => 500, 'features' => [...]]`, consumed generically by `AuditMonetizationSeeder`, and — starting in Task 2 — by `AuditTier::priceCents()`/`AuditEntitlementService::tierPriceCents()`/`quotaFor()`, `HandleAuditTierOrder`, and the dashboard tier-picker.

- [ ] **Step 1: Write the failing test**

In `tests/Feature/Seeders/AuditMonetizationSeederTest.php`, replace `test_seeds_the_three_one_time_tier_products` with a four-tier version and add a diagnostic-specific assertion:

```php
    public function test_seeds_the_four_one_time_tier_products(): void
    {
        $this->seedCatalog();

        foreach ([
            'audit-diagnostic' => 500,
            'audit-automated' => 4900,
            'audit-deep-ai' => 19900,
            'audit-expert' => 99900,
        ] as $slug => $cents) {
            $product = OneTimeProduct::where('slug', $slug)->first();

            $this->assertNotNull($product, "Missing one-time product [{$slug}].");
            $this->assertTrue((bool) $product->is_active);
            $this->assertSame($cents, (int) $product->prices()->first()->price);
        }
    }

    public function test_diagnostic_product_carries_the_diagnostic_tier_metadata(): void
    {
        $this->seedCatalog();

        $product = OneTimeProduct::where('slug', 'audit-diagnostic')->firstOrFail();

        $this->assertSame('diagnostic', $product->metadata['audit_tier']);
    }
```

Also update `test_the_seeder_holds_no_literal_money_figure` to include the new price in the forbidden-literals list:

```php
        foreach (['500', '4900', '19900', '99900', '5900', '14900', '49900', '150000'] as $literal) {
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=AuditMonetizationSeederTest --compact`
Expected: FAIL — `Missing one-time product [audit-diagnostic].`

- [ ] **Step 3: Add the Diagnostic tier to the pricing config**

In `config/pricing.php`, add a new first entry to `tiers` (before `audit-automated`):

```php
    'tiers' => [
        'audit-diagnostic' => [
            'tier' => 'diagnostic',
            'name' => 'Diagnostic Report',
            'description' => 'A fast scan of one repository — dependency, security, and structure signals with a plain-language summary.',
            'price' => 500,
            'features' => [
                'Three static analyzers across security and dependencies',
                'Plain-language summary of what needs attention',
                'A starting point before a deeper paid tier',
            ],
        ],
        'audit-automated' => [
```

(leave the existing `audit-automated`, `audit-deep-ai`, `audit-expert` entries unchanged)

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=AuditMonetizationSeederTest --compact`
Expected: PASS.

- [ ] **Step 5: Regenerate the committed frontend pricing data file**

Run: `php artisan app:export-pricing`
Then verify the gate is clean: `php artisan app:export-pricing --check`
Expected: `Pricing data is current.`

- [ ] **Step 6: Commit**

```bash
git add config/pricing.php frontend/src/data/pricing.json tests/Feature/Seeders/AuditMonetizationSeederTest.php
git commit -m "feat(audit): add Diagnostic to the priced tier catalog"
```

---

### Task 2: `AuditTier` gains `priceCents()` and `labelWithPrice()`; Diagnostic renamed

**Files:**
- Modify: `app/Constants/AuditTier.php`
- Test: `tests/Feature/Models/AuditTierTest.php`

**Interfaces:**
- Consumes: `config('pricing.tiers')` from Task 1 (now includes the `audit-diagnostic` entry).
- Produces: `AuditTier::priceCents(): ?int` (cents from `config('pricing.tiers')` matching this tier's `value`, or `null` if no catalog entry exists for it), `AuditTier::labelWithPrice(): string` (e.g. `"Automated Health Report — $49"`; if `priceCents()` is `null`, returns `label()` unchanged).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Models/AuditTierTest.php`:

```php
    public function test_diagnostic_label_no_longer_says_free(): void
    {
        $this->assertSame('Diagnostic Report', AuditTier::DIAGNOSTIC->label());
    }

    public function test_price_cents_reads_the_pricing_catalog(): void
    {
        $this->assertSame(500, AuditTier::DIAGNOSTIC->priceCents());
        $this->assertSame(4900, AuditTier::AUTOMATED->priceCents());
        $this->assertSame(19900, AuditTier::DEEP_AI->priceCents());
        $this->assertSame(99900, AuditTier::EXPERT->priceCents());
    }

    public function test_label_with_price_appends_a_formatted_dollar_amount(): void
    {
        $this->assertSame('Diagnostic Report — $5', AuditTier::DIAGNOSTIC->labelWithPrice());
        $this->assertSame('Automated Health Report — $49', AuditTier::AUTOMATED->labelWithPrice());
        $this->assertSame('Expert Audit — $999', AuditTier::EXPERT->labelWithPrice());
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run inside the backend container: `php artisan test --filter=AuditTierTest --compact`
Expected: FAIL (`Call to undefined method App\Constants\AuditTier::priceCents()`, and the rename assertion fails because the label still says "Free Diagnostic").

- [ ] **Step 3: Implement**

Replace the full contents of `app/Constants/AuditTier.php`:

```php
<?php

namespace App\Constants;

enum AuditTier: string
{
    case DIAGNOSTIC = 'diagnostic';
    case AUTOMATED = 'automated';
    case DEEP_AI = 'deep_ai';
    case EXPERT = 'expert';

    public function label(): string
    {
        return match ($this) {
            self::DIAGNOSTIC => __('Diagnostic Report'),
            self::AUTOMATED => __('Automated Health Report'),
            self::DEEP_AI => __('Deep AI Code Review'),
            self::EXPERT => __('Expert Audit'),
        };
    }

    /**
     * Catalog price in cents from config('pricing.tiers'), or null if this
     * tier has no priced product yet. Single source of truth shared by
     * AuditEntitlementService::tierPriceCents() and labelWithPrice().
     */
    public function priceCents(): ?int
    {
        foreach ((array) config('pricing.tiers') as $definition) {
            if (($definition['tier'] ?? null) === $this->value) {
                return (int) $definition['price'];
            }
        }

        return null;
    }

    /**
     * The plain label with the catalog price appended, for the two surfaces
     * where staff/customers need to see what an audit type costs at a
     * glance. Falls back to the plain label if this tier has no price yet.
     */
    public function labelWithPrice(): string
    {
        $cents = $this->priceCents();

        if ($cents === null) {
            return $this->label();
        }

        return __(':label — $:price', ['label' => $this->label(), 'price' => number_format($cents / 100)]);
    }

    /**
     * Filament badge colour for this tier, shared by every surface that lists
     * runs so the same tier never appears in two colours.
     *
     * `warning` is deliberately unused: the report list paints an
     * "In expert review" badge warning on the same row, and a tier sharing
     * that colour would read as one state rather than two.
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::DIAGNOSTIC => 'gray',
            self::AUTOMATED => 'info',
            self::DEEP_AI => 'primary',
            self::EXPERT => 'success',
        };
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=AuditTierTest --compact`
Expected: PASS — all three new tests, since Task 1 already seeded `config('pricing.tiers.audit-diagnostic')`.

- [ ] **Step 5: Commit**

```bash
git add app/Constants/AuditTier.php tests/Feature/Models/AuditTierTest.php
git commit -m "feat(audit): add AuditTier::priceCents() and labelWithPrice(), rename Diagnostic label"
```

---

### Task 3: `AuditEntitlementService::tierPriceCents()` delegates to `AuditTier::priceCents()`

**Files:**
- Modify: `app/Services/AuditReport/AuditEntitlementService.php:167-176`
- Test: `tests/Feature/Services/AuditEntitlementServiceTest.php`

**Interfaces:**
- Consumes: `AuditTier::priceCents()` from Task 2.
- Produces: no change to `AuditEntitlementService::tierPriceCents()`'s external signature (`private function tierPriceCents(AuditTier $tier): ?int`) or `quotaFor()`'s output — this is a pure internal refactor to remove the now-duplicated config lookup.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Services/AuditEntitlementServiceTest.php`, extending `test_paid_tiers_carry_their_catalog_price`:

```php
    public function test_paid_tiers_carry_their_catalog_price(): void
    {
        $user = $this->createUser();

        $this->assertSame(500, $this->service->quotaFor($user, null, AuditTier::DIAGNOSTIC)->priceCents);
        $this->assertSame(4900, $this->service->quotaFor($user, null, AuditTier::AUTOMATED)->priceCents);
        $this->assertSame(19900, $this->service->quotaFor($user, null, AuditTier::DEEP_AI)->priceCents);
        $this->assertSame(99900, $this->service->quotaFor($user, null, AuditTier::EXPERT)->priceCents);
    }
```

- [ ] **Step 2: Run test to verify it currently passes (this is a refactor, not new behavior)**

Run: `php artisan test --filter=AuditEntitlementServiceTest::test_paid_tiers_carry_their_catalog_price --compact`
Expected: PASS already — `tierPriceCents()`'s current inline loop already reads the same config and will already return 500 for `DIAGNOSTIC` now that Task 1 added the entry. This step confirms the test is meaningful before the refactor, not that it's red.

- [ ] **Step 3: Refactor `tierPriceCents()` to delegate**

In `app/Services/AuditReport/AuditEntitlementService.php`, replace:

```php
    private function tierPriceCents(AuditTier $tier): ?int
    {
        foreach ((array) config('pricing.tiers') as $definition) {
            if (($definition['tier'] ?? null) === $tier->value) {
                return (int) $definition['price'];
            }
        }

        return null;
    }
```

with:

```php
    private function tierPriceCents(AuditTier $tier): ?int
    {
        return $tier->priceCents();
    }
```

- [ ] **Step 4: Run the full entitlement test file to confirm nothing broke**

Run: `php artisan test --filter=AuditEntitlementServiceTest --compact`
Expected: PASS (all tests, including the one from Step 1).

- [ ] **Step 5: Commit**

```bash
git add app/Services/AuditReport/AuditEntitlementService.php tests/Feature/Services/AuditEntitlementServiceTest.php
git commit -m "refactor(audit): delegate tierPriceCents() to AuditTier::priceCents()"
```

---

### Task 4: Diagnostic stops being free by default

**Files:**
- Modify: `config/audit.php:15`
- Test: `tests/Feature/Services/AuditEntitlementServiceTest.php`
- Test: `tests/Feature/Services/AuditRequestRoutingTest.php`
- Test: `tests/Feature/Filament/Dashboard/Resources/AuditRequestResourceTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `config('audit.free_reports_limit')` default becomes `0` (was `3`). No API changes — `freeRunsLimit()`, `hasFreeRun()`, `consumeFreeRun()`, and the per-user `audit_bonus_free_runs` bonus (`AuditEntitlementService::BONUS_PARAM`) are untouched and still work exactly as before for any test/deployment that explicitly sets a nonzero limit or bonus.

This task touches several existing tests that implicitly relied on the *default* being 3. Each one below is either updated to explicitly `config(['audit.free_reports_limit' => 3])` (because it's testing the quota/bonus mechanism itself, which still works, just isn't the production default anymore) or updated to assert the new zero-by-default behavior.

- [ ] **Step 1: Write/update the failing tests**

In `tests/Feature/Services/AuditEntitlementServiceTest.php`:

Replace `test_fresh_email_has_three_free_runs`:

```php
    public function test_fresh_email_has_no_free_runs_by_default(): void
    {
        $this->assertSame(0, $this->service->freeRunsLimit('fresh@example.com'));
        $this->assertSame(0, $this->service->freeRunsUsed('fresh@example.com'));
        $this->assertFalse($this->service->hasFreeRun('fresh@example.com'));
    }

    public function test_an_explicit_limit_still_grants_free_runs(): void
    {
        config(['audit.free_reports_limit' => 3]);

        $this->assertSame(3, $this->service->freeRunsLimit('configured@example.com'));
        $this->assertTrue($this->service->hasFreeRun('configured@example.com'));
    }
```

Update `test_only_free_run_flagged_requests_count` to set the limit explicitly (it's testing that only flagged requests count towards usage, which requires a nonzero limit to observe `hasFreeRun` staying true):

```php
    public function test_only_free_run_flagged_requests_count(): void
    {
        config(['audit.free_reports_limit' => 3]);
        AuditRequest::factory()->count(2)->freeRun()->create(['email' => 'used@example.com']);
        AuditRequest::factory()->create(['email' => 'used@example.com']); // not flagged — e.g. a failed submission

        $this->assertSame(2, $this->service->freeRunsUsed('used@example.com'));
        $this->assertTrue($this->service->hasFreeRun('used@example.com'));
    }
```

Update `test_registered_user_bonus_extends_limit` — the bonus now extends a zero base, so 3 free-run requests should exhaust a bonus of 2 (limit becomes 2, not 5):

```php
    public function test_registered_user_bonus_extends_limit(): void
    {
        $user = User::factory()->create(['email' => 'bonus@example.com']);
        UserParameter::create(['user_id' => $user->id, 'name' => AuditEntitlementService::BONUS_PARAM, 'value' => '2']);

        $this->assertSame(2, $this->service->freeRunsLimit('bonus@example.com'));
        $this->assertTrue($this->service->hasFreeRun('bonus@example.com'));

        AuditRequest::factory()->count(2)->freeRun()->create(['email' => 'bonus@example.com']);
        $this->assertFalse($this->service->hasFreeRun('bonus@example.com'));
    }
```

Update `test_free_runs_alone_grant_audit_access` to set an explicit bonus (a fresh signup with zero bonus and zero default limit has no free runs, so the "free runs alone grant access" scenario needs a bonus to exist):

```php
    public function test_free_runs_alone_grant_audit_access(): void
    {
        $user = User::factory()->create(['email' => 'fresh-signup@example.com']);
        UserParameter::create(['user_id' => $user->id, 'name' => AuditEntitlementService::BONUS_PARAM, 'value' => '1']);

        $this->assertTrue($this->service->hasFreeRun($user->email));
        $this->assertTrue($this->service->hasAuditAccess($user, null));
    }
```

Remove the now-redundant `config(['audit.free_reports_limit' => 0]);` line from `test_no_audit_access_without_free_runs_subscription_or_requests` and `test_expert_only_credits_grant_audit_access` (harmless to leave, but redundant now that it's the default — leave them as-is if you prefer minimal diff; removing is optional cleanup, not required for correctness).

In `tests/Feature/Filament/Dashboard/Resources/AuditRequestResourceTest.php`, update `test_navigation_visible_for_fresh_user_with_only_free_runs` — it currently relies on the *default* config granting a free run, which will no longer be true:

```php
    public function test_navigation_visible_for_fresh_user_with_only_free_runs(): void
    {
        config(['audit.free_reports_limit' => 3]);
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        $this->assertTrue(AuditRequestResource::shouldRegisterNavigation());
    }
```

`test_navigation_hidden_without_audits_allowance_or_free_runs` already sets `config(['audit.free_reports_limit' => 0])` explicitly — leave it unchanged, it continues to pass and now also documents the new default.

Update `test_diagnostic_quota_is_the_lifetime_free_quota` — Diagnostic now has a catalog price (Task 1), so it's `purchasable()`, and its default limit is 0:

```php
    public function test_diagnostic_quota_is_the_lifetime_free_quota(): void
    {
        config(['audit.free_reports_limit' => 3]);
        $user = $this->createUser();
        AuditRequest::factory()->freeRun()->create(['email' => $user->email]);

        $quota = $this->service->quotaFor($user, null, AuditTier::DIAGNOSTIC);

        $this->assertTrue($quota->isLifetime);
        $this->assertSame(3, $quota->limit);
        $this->assertSame(1, $quota->used);
        $this->assertSame(2, $quota->remaining());
        $this->assertSame(500, $quota->priceCents);
        $this->assertTrue($quota->purchasable());
    }
```

In `tests/Feature/Services/AuditRequestRoutingTest.php`, update `test_public_repo_with_free_quota_queues_and_consumes_run` to explicitly grant quota (this test is verifying the "has quota → queue immediately" branch still works, which now requires an explicit limit rather than relying on the default):

```php
    public function test_public_repo_with_free_quota_queues_and_consumes_run(): void
    {
        config(['audit.free_reports_limit' => 3]);
        $request = AuditRequest::factory()->verified()->create([
            'repo_url' => 'file://'.$this->fixtureRepo,
            'status' => AuditRequestStatus::PENDING_VERIFICATION->value,
        ]);

        $this->route($request);

        $request->refresh();
        $this->assertSame(AuditRequestStatus::QUEUED->value, $request->status);
        $this->assertTrue($request->free_run);
        Queue::assertPushed(GenerateAuditReport::class);
        Mail::assertQueued(AuditRequestReceived::class);
    }
```

Add a new test locking in the production-default behavior:

```php
    public function test_public_repo_awaits_payment_by_default_with_no_prior_free_runs(): void
    {
        $request = AuditRequest::factory()->verified()->create([
            'repo_url' => 'file://'.$this->fixtureRepo,
            'status' => AuditRequestStatus::PENDING_VERIFICATION->value,
        ]);

        $this->route($request);

        $request->refresh();
        $this->assertSame(AuditRequestStatus::AWAITING_PAYMENT->value, $request->status);
        $this->assertFalse($request->free_run);
        Queue::assertNotPushed(GenerateAuditReport::class);
        Mail::assertQueued(AuditQuotaExhausted::class);
    }
```

- [ ] **Step 2: Run the affected test files to verify the new/changed assertions fail against the current config default**

Run: `php artisan test --filter=AuditEntitlementServiceTest --compact`
Run: `php artisan test --filter=AuditRequestRoutingTest --compact`
Run: `php artisan test --filter="Tests\\Feature\\Filament\\Dashboard\\Resources\\AuditRequestResourceTest"`
Expected: FAIL on `test_fresh_email_has_no_free_runs_by_default` (`assertSame(0, ...)` fails, currently 3), on `test_public_repo_awaits_payment_by_default_with_no_prior_free_runs` (status is `QUEUED`, not `AWAITING_PAYMENT`), and on `test_navigation_visible_for_fresh_user_with_only_free_runs` if run before its `config()` line is added (it isn't new — just confirm it still passes once added, since the default alone won't carry it after Step 3).

- [ ] **Step 3: Flip the config default**

In `config/audit.php`, change:

```php
    'free_reports_limit' => 3,
```

to:

```php
    'free_reports_limit' => 0,
```

- [ ] **Step 4: Run the affected test files again**

Run: `php artisan test --filter=AuditEntitlementServiceTest --compact`
Run: `php artisan test --filter=AuditRequestRoutingTest --compact`
Run: `php artisan test --filter="Tests\\Feature\\Filament\\Dashboard\\Resources\\AuditRequestResourceTest"`
Expected: PASS, all tests in all three files.

- [ ] **Step 5: Run the full audit-related suite to catch any other implicit dependency on the old default**

Run: `php artisan test --filter=Audit --compact`
Expected: PASS. If any other test breaks on this default (e.g. in `AuditRequestControllerTest`, `AuditRequestResourceTest`), fix it the same way — either set `config(['audit.free_reports_limit' => 3])` explicitly if it's exercising the "has quota" path, or update the expected status/assertions to match the new default.

- [ ] **Step 6: Commit**

```bash
git add config/audit.php tests/Feature/Services/AuditEntitlementServiceTest.php tests/Feature/Services/AuditRequestRoutingTest.php tests/Feature/Filament/Dashboard/Resources/AuditRequestResourceTest.php
git commit -m "feat(audit): Diagnostic tier no longer free by default"
```

---

### Task 5: Admin panel shows price-annotated tier labels

**Files:**
- Modify: `app/Filament/Admin/Resources/AuditRequests/AuditRequestResource.php:78-84` (form Select) and `:189` (table column)
- Test: `tests/Feature/Filament/Admin/Resources/AuditRequestResourceTest.php` (table column)
- Test: `tests/Feature/Filament/Admin/AuditRequestTierTest.php` (form Select — this file already exists and already covers changing a request's tier via this exact Select)

**Interfaces:**
- Consumes: `AuditTier::labelWithPrice()` from Task 2.
- Produces: no change to any other resource's interface — this only changes what text renders in two places on one Filament resource.

- [ ] **Step 1: Write the failing tests**

Update `test_list_shows_the_tier_each_request_ran_at` in `tests/Feature/Filament/Admin/Resources/AuditRequestResourceTest.php`:

```php
    public function test_list_shows_the_tier_and_price_each_request_ran_at(): void
    {
        $admin = $this->createAdminUser();
        AuditRequest::factory()->create(['tier' => AuditTier::EXPERT->value]);
        AuditRequest::factory()->create(['tier' => AuditTier::AUTOMATED->value]);

        // setUp() truncates audit_requests, so only these two rows are listed
        // and an absent label really is absent.
        $this->actingAs($admin)->get(AuditRequestResource::getUrl('index', [], true, 'admin'))
            ->assertStatus(200)
            ->assertSee(AuditTier::EXPERT->labelWithPrice())
            ->assertSee(AuditTier::AUTOMATED->labelWithPrice())
            ->assertDontSee(AuditTier::DEEP_AI->labelWithPrice());
    }
```

Add a new test to the existing `tests/Feature/Filament/Admin/AuditRequestTierTest.php`, matching its established `Livewire::test(EditAuditRequest::class, ['record' => $request->uuid])` convention (record is keyed by `uuid`, not `getRouteKey()`, in this file):

```php
    public function test_the_tier_select_options_show_price(): void
    {
        $admin = $this->createAdminUser();
        $request = AuditRequest::factory()->create(['tier' => AuditTier::DIAGNOSTIC->value]);

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(EditAuditRequest::class, ['record' => $request->uuid])
            ->assertSee(AuditTier::EXPERT->labelWithPrice())
            ->assertSee(AuditTier::DIAGNOSTIC->labelWithPrice());
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=AuditRequestResourceTest --compact`
Run: `php artisan test --filter=AuditRequestTierTest --compact`
Expected: FAIL — the responses contain `"Expert Audit"` but not `"Expert Audit — $999"`.

- [ ] **Step 3: Implement**

In `app/Filament/Admin/Resources/AuditRequests/AuditRequestResource.php`, change the form Select (around line 78-84):

```php
                Select::make('tier')
                    ->label(__('Audit type'))
                    ->options(
                        collect(AuditTier::cases())
                            ->mapWithKeys(fn (AuditTier $tier): array => [$tier->value => $tier->labelWithPrice()])
                            ->all()
                    )
                    ->helperText(__('Changing this and re-running the pipeline re-analyses at the new tier.'))
                    ->required(),
```

And the table column (around line 186-190):

```php
                TextColumn::make('tier')
                    ->label(__('Audit type'))
                    ->badge()
                    ->color(fn (AuditTier $state): string => $state->badgeColor())
                    ->formatStateUsing(fn (AuditTier $state): string => $state->labelWithPrice()),
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=AuditRequestResourceTest --compact`
Run: `php artisan test --filter=AuditRequestTierTest --compact`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Admin/Resources/AuditRequests/AuditRequestResource.php tests/Feature/Filament/Admin/Resources/AuditRequestResourceTest.php tests/Feature/Filament/Admin/AuditRequestTierTest.php
git commit -m "feat(audit): show price in the admin Audit Type label and column"
```

---

### Task 6: Customer dashboard shows price-annotated tier labels in the audit list

**Files:**
- Modify: `app/Filament/Dashboard/Resources/AuditRequests/AuditRequestResource.php` (table column, ~line 90-94)
- Test: `tests/Feature/Filament/Dashboard/Resources/AuditRequestResourceTest.php`

**Interfaces:**
- Consumes: `AuditTier::labelWithPrice()` from Task 2.
- Produces: no change to any other resource's interface.

Note: the dashboard's *tier-picker* (`resources/views/filament/dashboard/pages/audit-reports.blade.php`, used to select/buy a new run) already shows `$quota->label` plus a separate "Buy for $X" line via `priceCents` — leave that file untouched; adding `labelWithPrice()` there would duplicate the price. This task only touches the *list* of a customer's past/pending audit requests.

- [ ] **Step 1: Update the existing tier-column test**

`tests/Feature/Filament/Dashboard/Resources/AuditRequestResourceTest.php` already has a test for this exact column, `test_list_shows_the_tier_each_audit_ran_at` (it uses `createTenantFor($user)` and plain `$this->get(...)->assertSee(...)`, not Livewire component testing). Replace it:

```php
    public function test_list_shows_the_tier_and_price_each_audit_ran_at(): void
    {
        $user = User::factory()->create(['email' => 'tier-column@example.com']);
        $tenant = $this->createTenantFor($user);

        AuditRequest::factory()->create([
            'user_id' => $user->id,
            'repo_url' => 'https://github.com/acme/tier-deep',
            'tier' => AuditTier::DEEP_AI->value,
        ]);
        AuditRequest::factory()->create([
            'user_id' => $user->id,
            'repo_url' => 'https://github.com/acme/tier-free',
            'tier' => AuditTier::DIAGNOSTIC->value,
        ]);

        $this->actingAs($user);

        $this->get(AuditRequestResource::getUrl('index', [], true, 'dashboard', tenant: $tenant))
            ->assertSuccessful()
            ->assertSee(AuditTier::DEEP_AI->labelWithPrice())
            ->assertSee(AuditTier::DIAGNOSTIC->labelWithPrice())
            // Nothing on this page paints a tier the user does not own.
            ->assertDontSee(AuditTier::EXPERT->labelWithPrice());
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter="Tests\\Feature\\Filament\\Dashboard\\Resources\\AuditRequestResourceTest::test_list_shows_the_tier_and_price_each_audit_ran_at"`
Expected: FAIL — the response contains `"Deep AI Code Review"` but not `"Deep AI Code Review — $199"`.

- [ ] **Step 3: Implement**

In `app/Filament/Dashboard/Resources/AuditRequests/AuditRequestResource.php`, change:

```php
                TextColumn::make('tier')
                    ->label(__('Audit type'))
                    ->badge()
                    ->color(fn (AuditTier $state): string => $state->badgeColor())
                    ->formatStateUsing(fn (AuditTier $state): string => $state->label()),
```

to:

```php
                TextColumn::make('tier')
                    ->label(__('Audit type'))
                    ->badge()
                    ->color(fn (AuditTier $state): string => $state->badgeColor())
                    ->formatStateUsing(fn (AuditTier $state): string => $state->labelWithPrice()),
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter="Tests\\Feature\\Filament\\Dashboard\\Resources\\AuditRequestResourceTest"`
Expected: PASS (all tests in the file, including the one updated in Task 4 Step 1).

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Dashboard/Resources/AuditRequests/AuditRequestResource.php tests/Feature/Filament/Dashboard/Resources/AuditRequestResourceTest.php
git commit -m "feat(audit): show price in the dashboard Audit Type column"
```

---

### Task 7: Gate the public `/pricing` route behind login

**Files:**
- Modify: `routes/web.php:48-50`
- Test: `tests/Feature/Http/Controllers/PricingPageTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `GET /pricing` now requires authentication. `route('pricing')` itself is unchanged (still resolves to `/pricing`); callers of `redirect()->route('pricing')` for a guest (e.g. the `home` route at the top of `routes/web.php`) will now bounce through `/login` via the `auth` middleware's redirect, which is expected — this is what "only registered users see prices" means. No change needed to the `home` route itself.

- [ ] **Step 1: Write the failing tests**

Replace the contents of `tests/Feature/Http/Controllers/PricingPageTest.php`:

```php
<?php

namespace Tests\Feature\Http\Controllers;

use Tests\Feature\FeatureTest;

class PricingPageTest extends FeatureTest
{
    public function test_authenticated_user_can_view_pricing(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user)->get(route('pricing'));

        $response->assertStatus(200);
        $response->assertSee(__('Plans & Pricing'));
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get(route('pricing'));

        $response->assertRedirect(route('login'));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=PricingPageTest --compact`
Expected: FAIL on `test_guest_is_redirected_to_login` (currently 200, not a redirect).

- [ ] **Step 3: Implement**

In `routes/web.php`, change:

```php
Route::get('/pricing', function () {
    return view('pricing');
})->name('pricing')->middleware('sitemapped');
```

to:

```php
Route::get('/pricing', function () {
    return view('pricing');
})->name('pricing')->middleware('auth');
```

(`sitemapped` is dropped along with the middleware swap — a login-gated page has no business in the public sitemap.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=PricingPageTest --compact`
Expected: PASS.

- [ ] **Step 5: Run the layout/branding test that also touches auth-page routing, to confirm no collateral break**

Run: `php artisan test --filter=LayoutBrandingTest --compact`
Expected: PASS (this test only asserts branding on `login`/`register`/`password.request`, unrelated to `/pricing`, but it shares the general auth-routing surface — confirm it's unaffected).

- [ ] **Step 6: Commit**

```bash
git add routes/web.php tests/Feature/Http/Controllers/PricingPageTest.php
git commit -m "feat(pricing): gate the public pricing page behind login"
```

---

### Task 8: Full verification pass

**Files:** none (verification only)

- [ ] **Step 1: Run the full backend test suite**

Run: `php artisan test --compact`
Expected: PASS, no failures. (Per the Global Constraints, never run this concurrently with another test invocation; this is the only test run in this task.)

- [ ] **Step 2: Run static analysis**

Run: `vendor/bin/phpstan analyse`
Expected: no new errors introduced by this plan's changes.

- [ ] **Step 3: Run the formatting gate**

Run: `vendor/bin/pint --test`
Expected: clean. If it reports issues, run plain `vendor/bin/pint` (not `--dirty`) to fix, then re-run `--test`, then amend the affected task's commit or add a small follow-up formatting commit.

- [ ] **Step 4: Confirm the pricing-drift gate is clean**

Run: `php artisan app:export-pricing --check`
Expected: `Pricing data is current.` (This re-confirms Task 1 Step 5's regeneration is still in sync — nothing later in the plan touches `config/pricing.php` again, so this should be a no-op check.)

- [ ] **Step 5: Manual smoke check (no UI harness in this plan — confirm by reading, not by browser)**

Confirm by inspection that:
- `AuditTier::DIAGNOSTIC->labelWithPrice()` returns `"Diagnostic Report — $5"`.
- A fresh `AuditRequest::factory()->verified()->create()` routed through `AuditRequestService::routeVerified()` with default config lands in `AWAITING_PAYMENT`, not `QUEUED`.
- `frontend/src/data/pricing.json` contains an `audit-diagnostic` key with `"price_cents": 500`.

No code changes expected from this step — it's a final sanity re-read, not a new task.
