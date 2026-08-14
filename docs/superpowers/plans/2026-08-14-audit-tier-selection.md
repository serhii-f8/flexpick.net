# Audit Tier Selection and Per-Tier Metering Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a customer and an operator choose which of the four audit tiers a run executes at, and meter each tier against its own quota.

**Architecture:** The four tiered pipelines already exist and are selected entirely by the `audit_requests.tier` column, so no pipeline code changes. The work is: record how each run was funded (`funding` column), generalize `AuditEntitlementService` from two hardcoded quota pairs to a tier-keyed lookup returning a `TierQuota` value object, surface that as a radio group on the dashboard page and a select in the admin panel, and route an exhausted-but-purchasable tier to the existing one-time checkout.

**Tech Stack:** Laravel 13, PHP 8.4, Filament 5, Livewire 4, PHPUnit 11, Larastan 3, Pint.

**Spec:** `docs/superpowers/specs/2026-08-14-audit-tier-selection-design.md`

## Global Constraints

- **All commands run inside Docker, from the repository root** (`/var/www/html/flexpick.net`), never from `backend/`: `docker compose exec laravel.test <cmd>`. Running `docker compose` from `backend/` starts a second stack and fails on a port collision.
- **One test command at a time.** Concurrent runs collide on the test database. The full suite (~150s) exceeds some agent timeouts — use `--filter` during work and reserve the full suite for the final task.
- Tests are **PHPUnit**, classic `TestCase`-based classes. There is no `Pest.php`. Feature tests extend `Tests\Feature\FeatureTest`. Scaffold with `php artisan make:test --phpunit {name}`.
- **Never run `pint --dirty`** in the dev container — the bind mount excludes `.git`, so it reports `passed` without checking anything. Run plain `pint`, verify with `pint --test`.
- Every monetary figure lives in `config/pricing.php` and nowhere else. `app:export-pricing --check` is a CI gate asserting `frontend/src/data/pricing.json` matches it.
- Business logic belongs in Services, not controllers or models.
- `funding` column default is `allowance` — the fail-loud direction. A path that forgets to set it over-counts (visible, reversible) rather than granting unlimited runs silently.
- Existing tier rows are **never backfilled**. Historical dashboard runs really did execute the diagnostic profile.

---

### Task 1: The `funding` column

Records how a run was paid for, so metering reads a fact instead of inferring one from `source`, `free_run`, `prepaid`, and status.

**Files:**
- Create: `backend/app/Constants/AuditFunding.php`
- Create: `backend/database/migrations/2026_08_14_000001_add_funding_to_audit_requests_table.php`
- Modify: `backend/app/Models/AuditRequest.php` (`$fillable` line 31 area, `$casts` line 49 area)
- Test: `backend/tests/Feature/Migrations/AuditFundingBackfillTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `App\Constants\AuditFunding` enum with cases `ALLOWANCE = 'allowance'`, `FREE = 'free'`, `PURCHASE = 'purchase'`. `AuditRequest->funding` casts to it and is mass-assignable.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Migrations;

use App\Constants\AuditFunding;
use App\Models\AuditRequest;
use Tests\Feature\FeatureTest;

class AuditFundingBackfillTest extends FeatureTest
{
    public function test_funding_defaults_to_allowance(): void
    {
        $request = AuditRequest::factory()->create();

        $this->assertSame(AuditFunding::ALLOWANCE, $request->fresh()->funding);
    }

    public function test_funding_is_mass_assignable_and_cast(): void
    {
        $request = AuditRequest::factory()->create(['funding' => AuditFunding::PURCHASE->value]);

        $this->assertSame(AuditFunding::PURCHASE, $request->fresh()->funding);
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
docker compose exec laravel.test php artisan test --filter=AuditFundingBackfillTest
```

Expected: FAIL — `Unknown column 'funding'` / `Class "App\Constants\AuditFunding" not found`.

- [ ] **Step 3: Create the enum**

```php
<?php

namespace App\Constants;

enum AuditFunding: string
{
    case ALLOWANCE = 'allowance';
    case FREE = 'free';
    case PURCHASE = 'purchase';
}
```

- [ ] **Step 4: Write the migration with its backfill**

```php
<?php

use App\Constants\AuditFunding;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            $table->string('funding')->default(AuditFunding::ALLOWANCE->value)->after('free_run')->index();
        });

        // Precedence: a paid run first, then a free-quota run, then anything
        // dashboard-sourced (which came out of the plan), then free. Tier is
        // deliberately NOT backfilled -- historical dashboard runs really did
        // execute the diagnostic profile.
        DB::table('audit_requests')->where('prepaid', true)
            ->update(['funding' => AuditFunding::PURCHASE->value]);

        DB::table('audit_requests')->where('prepaid', false)->where('free_run', true)
            ->update(['funding' => AuditFunding::FREE->value]);

        DB::table('audit_requests')->where('prepaid', false)->where('free_run', false)
            ->where('source', '!=', 'dashboard')
            ->update(['funding' => AuditFunding::FREE->value]);
    }

    public function down(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            $table->dropIndex(['funding']);
            $table->dropColumn('funding');
        });
    }
};
```

- [ ] **Step 5: Add the column to the model**

In `backend/app/Models/AuditRequest.php`, add `'funding'` to `$fillable` (next to `'free_run'`), and add to `$casts`:

```php
'funding' => AuditFunding::class,
```

Add `use App\Constants\AuditFunding;` to the imports.

- [ ] **Step 6: Run the test to confirm it passes**

```bash
docker compose exec laravel.test php artisan test --filter=AuditFundingBackfillTest
```

Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add backend/app/Constants/AuditFunding.php backend/database/migrations/2026_08_14_000001_add_funding_to_audit_requests_table.php backend/app/Models/AuditRequest.php backend/tests/Feature/Migrations/AuditFundingBackfillTest.php
git commit -m "feat(audit): record how each run was funded"
```

---

### Task 2: `TierQuota` and tier labels

The value object the selector and the usage widget both render, so neither branches on tier.

**Files:**
- Create: `backend/app/Services/AuditReport/TierQuota.php`
- Modify: `backend/app/Constants/AuditTier.php`
- Test: `backend/tests/Unit/Services/AuditReport/TierQuotaTest.php`

**Interfaces:**
- Consumes: `App\Constants\AuditTier`.
- Produces: `AuditTier::label(): string`. `TierQuota` with public readonly `tier`, `label`, `limit`, `used`, `isLifetime`, `priceCents` and methods `remaining(): int`, `hasRuns(): bool`, `purchasable(): bool`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Services\AuditReport;

use App\Constants\AuditTier;
use App\Services\AuditReport\TierQuota;
use PHPUnit\Framework\TestCase;

class TierQuotaTest extends TestCase
{
    private function quota(int $limit, int $used, ?int $priceCents = 19900): TierQuota
    {
        return new TierQuota(
            tier: AuditTier::DEEP_AI,
            label: 'Deep AI Code Review',
            limit: $limit,
            used: $used,
            isLifetime: false,
            priceCents: $priceCents,
        );
    }

    public function test_remaining_is_limit_minus_used(): void
    {
        $this->assertSame(3, $this->quota(5, 2)->remaining());
    }

    public function test_remaining_never_goes_negative(): void
    {
        // An operator-launched run can exceed the allowance; the quota must
        // report zero rather than a negative number the UI would render.
        $this->assertSame(0, $this->quota(1, 4)->remaining());
    }

    public function test_has_runs_reflects_remaining(): void
    {
        $this->assertTrue($this->quota(5, 2)->hasRuns());
        $this->assertFalse($this->quota(2, 2)->hasRuns());
    }

    public function test_a_tier_without_a_price_is_not_purchasable(): void
    {
        $this->assertTrue($this->quota(0, 0)->purchasable());
        $this->assertFalse($this->quota(0, 0, null)->purchasable());
    }

    public function test_every_tier_has_a_label(): void
    {
        foreach (AuditTier::cases() as $tier) {
            $this->assertNotSame('', $tier->label());
        }
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
docker compose exec laravel.test php artisan test --filter=TierQuotaTest
```

Expected: FAIL — `Class "App\Services\AuditReport\TierQuota" not found`.

- [ ] **Step 3: Add labels to the enum**

Append to `backend/app/Constants/AuditTier.php`, inside the enum. The three paid labels match `config/pricing.php` names exactly; diagnostic has no catalog entry because it is not sold.

```php
    public function label(): string
    {
        return match ($this) {
            self::DIAGNOSTIC => __('Free Diagnostic'),
            self::AUTOMATED => __('Automated Health Report'),
            self::DEEP_AI => __('Deep AI Code Review'),
            self::EXPERT => __('Expert Audit'),
        };
    }
```

- [ ] **Step 4: Create the value object**

```php
<?php

namespace App\Services\AuditReport;

use App\Constants\AuditTier;

/**
 * One tier's quota position for one user: how many runs it allows, how many
 * are spent, and what buying another costs.
 *
 * Diagnostic is backed by the lifetime free-run quota and every other tier by
 * a monthly plan-metadata allowance. Carrying that difference as a flag here
 * is what lets the selector and PlanUsageWidget loop over tiers instead of
 * branching on which kind of quota backs each one.
 */
final readonly class TierQuota
{
    public function __construct(
        public AuditTier $tier,
        public string $label,
        public int $limit,
        public int $used,
        public bool $isLifetime,
        public ?int $priceCents,
    ) {}

    public function remaining(): int
    {
        return max(0, $this->limit - $this->used);
    }

    public function hasRuns(): bool
    {
        return $this->remaining() > 0;
    }

    /** Only tiers with a catalog price can be bought outright. */
    public function purchasable(): bool
    {
        return $this->priceCents !== null;
    }
}
```

- [ ] **Step 5: Run the test to confirm it passes**

```bash
docker compose exec laravel.test php artisan test --filter=TierQuotaTest
```

Expected: PASS (5 tests).

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/AuditReport/TierQuota.php backend/app/Constants/AuditTier.php backend/tests/Unit/Services/AuditReport/TierQuotaTest.php
git commit -m "feat(audit): add TierQuota value object and tier labels"
```

---

### Task 3: Generalize `AuditEntitlementService`

Replace two hardcoded quota pairs with a tier-keyed lookup. The old methods stay as one-line delegates so the eleven existing call sites keep working; Task 11 removes them once all callers have migrated.

**Files:**
- Modify: `backend/app/Services/AuditReport/AuditEntitlementService.php`
- Test: `backend/tests/Feature/Services/AuditEntitlementServiceTest.php` (append)

**Interfaces:**
- Consumes: `AuditFunding` (Task 1), `TierQuota` and `AuditTier::label()` (Task 2).
- Produces: `allowance(?Tenant, AuditTier): int`, `runsUsedThisMonth(User, AuditTier): int`, `remainingRuns(User, ?Tenant, AuditTier): int`, `quotaFor(User, ?Tenant, AuditTier): TierQuota`, `quotas(User, ?Tenant): array<int, TierQuota>`. `consumeFreeRun()` now also sets `funding`.

- [ ] **Step 1: Write the failing tests**

Append to `backend/tests/Feature/Services/AuditEntitlementServiceTest.php`. Add `use App\Constants\AuditFunding;` and `use App\Constants\AuditTier;` to the imports.

```php
    public function test_only_allowance_funded_runs_are_metered(): void
    {
        $user = $this->createUser();

        AuditRequest::factory()->count(2)->create([
            'user_id' => $user->id,
            'tier' => AuditTier::AUTOMATED->value,
            'funding' => AuditFunding::ALLOWANCE->value,
        ]);

        // A purchased run and a free run must not spend plan quota.
        AuditRequest::factory()->create([
            'user_id' => $user->id,
            'tier' => AuditTier::AUTOMATED->value,
            'funding' => AuditFunding::PURCHASE->value,
        ]);
        AuditRequest::factory()->create([
            'user_id' => $user->id,
            'tier' => AuditTier::AUTOMATED->value,
            'funding' => AuditFunding::FREE->value,
        ]);

        $this->assertSame(2, $this->service->runsUsedThisMonth($user, AuditTier::AUTOMATED));
    }

    public function test_each_tier_meters_independently(): void
    {
        $user = $this->createUser();

        AuditRequest::factory()->create([
            'user_id' => $user->id,
            'tier' => AuditTier::AUTOMATED->value,
            'funding' => AuditFunding::ALLOWANCE->value,
        ]);
        AuditRequest::factory()->count(3)->create([
            'user_id' => $user->id,
            'tier' => AuditTier::DEEP_AI->value,
            'funding' => AuditFunding::ALLOWANCE->value,
        ]);

        $this->assertSame(1, $this->service->runsUsedThisMonth($user, AuditTier::AUTOMATED));
        $this->assertSame(3, $this->service->runsUsedThisMonth($user, AuditTier::DEEP_AI));
        $this->assertSame(0, $this->service->runsUsedThisMonth($user, AuditTier::EXPERT));
    }

    public function test_last_months_runs_do_not_count(): void
    {
        $user = $this->createUser();

        AuditRequest::factory()->create([
            'user_id' => $user->id,
            'tier' => AuditTier::AUTOMATED->value,
            'funding' => AuditFunding::ALLOWANCE->value,
            'created_at' => now()->startOfMonth()->subDay(),
        ]);

        $this->assertSame(0, $this->service->runsUsedThisMonth($user, AuditTier::AUTOMATED));
    }

    public function test_diagnostic_quota_is_the_lifetime_free_quota(): void
    {
        $user = $this->createUser();
        AuditRequest::factory()->freeRun()->create(['email' => $user->email]);

        $quota = $this->service->quotaFor($user, null, AuditTier::DIAGNOSTIC);

        $this->assertTrue($quota->isLifetime);
        $this->assertSame(3, $quota->limit);
        $this->assertSame(1, $quota->used);
        $this->assertSame(2, $quota->remaining());
        $this->assertFalse($quota->purchasable());
    }

    public function test_paid_tiers_carry_their_catalog_price(): void
    {
        $user = $this->createUser();

        $this->assertSame(4900, $this->service->quotaFor($user, null, AuditTier::AUTOMATED)->priceCents);
        $this->assertSame(19900, $this->service->quotaFor($user, null, AuditTier::DEEP_AI)->priceCents);
        $this->assertSame(99900, $this->service->quotaFor($user, null, AuditTier::EXPERT)->priceCents);
    }

    public function test_quotas_returns_one_entry_per_tier(): void
    {
        $user = $this->createUser();

        $quotas = $this->service->quotas($user, null);

        $this->assertCount(count(AuditTier::cases()), $quotas);
    }

    public function test_a_null_tenant_has_no_allowance(): void
    {
        $user = $this->createUser();

        $this->assertSame(0, $this->service->allowance(null, AuditTier::AUTOMATED));
        $this->assertSame(0, $this->service->remainingRuns($user, null, AuditTier::AUTOMATED));
    }

    public function test_consuming_a_free_run_marks_the_funding(): void
    {
        $request = AuditRequest::factory()->create(['funding' => AuditFunding::ALLOWANCE->value]);

        $this->service->consumeFreeRun($request);

        $this->assertTrue($request->fresh()->free_run);
        $this->assertSame(AuditFunding::FREE, $request->fresh()->funding);
    }
```

`createUser()` is provided by `Tests\Feature\FeatureTest`.

- [ ] **Step 2: Run them to confirm they fail**

```bash
docker compose exec laravel.test php artisan test --filter=AuditEntitlementServiceTest
```

Expected: FAIL — `Call to undefined method ...::runsUsedThisMonth()`.

- [ ] **Step 3: Rewrite the quota section of the service**

Replace lines 53-99 of `backend/app/Services/AuditReport/AuditEntitlementService.php` (from `subscriptionAllowance()` through `remainingDeepAiRuns()`) with the following, and add `use App\Constants\AuditFunding;` and `use App\Services\AuditReport\TierQuota;` — the latter is same-namespace so no import is needed.

```php
    /**
     * Plan-metadata key per metered tier. DIAGNOSTIC is absent deliberately:
     * it is funded by the lifetime free quota, not a monthly allowance.
     */
    private const QUOTA_KEYS = [
        AuditTier::AUTOMATED->value => 'audit_analyses_per_month',
        AuditTier::DEEP_AI->value => 'audit_deep_ai_credits',
        AuditTier::EXPERT->value => 'audit_expert_credits',
    ];

    public function allowance(?Tenant $tenant, AuditTier $tier): int
    {
        $key = self::QUOTA_KEYS[$tier->value] ?? null;

        if ($tenant === null || $key === null) {
            return 0;
        }

        return $this->planMetadata($tenant, $key);
    }

    private function planMetadata(Tenant $tenant, string $key): int
    {
        return (int) $this->subscriptionService->findActiveTenantSubscriptions($tenant)
            ->map(fn ($subscription): int => (int) data_get($subscription->plan?->product?->metadata, $key, 0))
            ->max();
    }

    /**
     * Runs this calendar month that came out of the plan.
     *
     * Keyed on `funding`, not `source`: a checkout intent awaiting payment and
     * a purchased run are both dashboard-sourced but neither spends quota.
     */
    public function runsUsedThisMonth(User $user, AuditTier $tier): int
    {
        return AuditRequest::query()
            ->where('user_id', $user->id)
            ->where('funding', AuditFunding::ALLOWANCE->value)
            ->where('tier', $tier->value)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    public function remainingRuns(User $user, ?Tenant $tenant, AuditTier $tier): int
    {
        if ($tier === AuditTier::DIAGNOSTIC) {
            return max(0, $this->freeRunsLimit($user->email) - $this->freeRunsUsed($user->email));
        }

        return max(0, $this->allowance($tenant, $tier) - $this->runsUsedThisMonth($user, $tier));
    }

    public function quotaFor(User $user, ?Tenant $tenant, AuditTier $tier): TierQuota
    {
        $isLifetime = $tier === AuditTier::DIAGNOSTIC;

        return new TierQuota(
            tier: $tier,
            label: $tier->label(),
            limit: $isLifetime ? $this->freeRunsLimit($user->email) : $this->allowance($tenant, $tier),
            used: $isLifetime ? $this->freeRunsUsed($user->email) : $this->runsUsedThisMonth($user, $tier),
            isLifetime: $isLifetime,
            priceCents: $this->tierPriceCents($tier),
        );
    }

    /** @return list<TierQuota> */
    public function quotas(User $user, ?Tenant $tenant): array
    {
        return array_map(
            fn (AuditTier $tier): TierQuota => $this->quotaFor($user, $tenant, $tier),
            AuditTier::cases(),
        );
    }

    private function tierPriceCents(AuditTier $tier): ?int
    {
        foreach ((array) config('pricing.tiers') as $definition) {
            if (($definition['tier'] ?? null) === $tier->value) {
                return (int) $definition['price'];
            }
        }

        return null;
    }

    // Retained so existing call sites keep working while they migrate to the
    // tier-keyed API above. Removed in full once every caller is converted.
    public function subscriptionAllowance(Tenant $tenant): int
    {
        return $this->allowance($tenant, AuditTier::AUTOMATED);
    }

    public function deepAiCredits(Tenant $tenant): int
    {
        return $this->allowance($tenant, AuditTier::DEEP_AI);
    }

    public function dashboardRunsUsedThisMonth(User $user): int
    {
        return $this->runsUsedThisMonth($user, AuditTier::AUTOMATED);
    }

    public function deepAiRunsUsedThisMonth(User $user): int
    {
        return $this->runsUsedThisMonth($user, AuditTier::DEEP_AI);
    }

    public function remainingDashboardRuns(User $user, Tenant $tenant): int
    {
        return $this->remainingRuns($user, $tenant, AuditTier::AUTOMATED);
    }

    public function remainingDeepAiRuns(User $user, Tenant $tenant): int
    {
        return $this->remainingRuns($user, $tenant, AuditTier::DEEP_AI);
    }
```

- [ ] **Step 4: Keep `free_run` and `funding` in lockstep**

Replace `consumeFreeRun()` (line 48-51):

```php
    /** Spends a free run. Sets both markers so metering and the lifetime
     *  count can never disagree about how a run was funded. */
    public function consumeFreeRun(AuditRequest $auditRequest): void
    {
        $auditRequest->update([
            'free_run' => true,
            'funding' => AuditFunding::FREE->value,
        ]);
    }
```

- [ ] **Step 5: Generalize the access gate**

Replace the final `return` of `hasAuditAccess()` (line 115):

```php
        if ($tenant === null) {
            return false;
        }

        // Any metered tier with a nonzero allowance grants access -- a tenant
        // holding only Expert credits must not be locked out of the nav.
        foreach (array_keys(self::QUOTA_KEYS) as $tierValue) {
            if ($this->allowance($tenant, AuditTier::from($tierValue)) > 0) {
                return true;
            }
        }

        return false;
```

- [ ] **Step 6: Run the test to confirm it passes**

```bash
docker compose exec laravel.test php artisan test --filter=AuditEntitlementServiceTest
```

Expected: PASS, all tests including the pre-existing ones.

- [ ] **Step 7: Confirm the delegates did not break their callers**

```bash
docker compose exec laravel.test php artisan test --filter=AuditSubscriptionEntitlementTest
```

Expect **failures**, and they are the correct kind. `AuditSubscriptionEntitlementTest` builds fixtures around `source`, which no longer decides metering — for example `test_dashboard_runs_this_month_reduce_remaining` creates a web-sourced row commented "doesn't count", but every factory row now defaults to `funding = allowance`, so it does.

Fix each by making the funding explicit, which is the whole point of the column. In that test:

```php
        AuditRequest::factory()->count(2)->create([
            'user_id' => $user->id,
            'tier' => AuditTier::AUTOMATED->value,
            'funding' => AuditFunding::ALLOWANCE->value,
        ]);
        AuditRequest::factory()->create([
            'user_id' => $user->id,
            'tier' => AuditTier::AUTOMATED->value,
            'funding' => AuditFunding::ALLOWANCE->value,
            'created_at' => now()->subMonths(2),
        ]);
        // Guest-funnel run: free-funded, so it never touches plan quota.
        AuditRequest::factory()->create([
            'user_id' => $user->id,
            'tier' => AuditTier::AUTOMATED->value,
            'funding' => AuditFunding::FREE->value,
        ]);
```

Add `use App\Constants\AuditFunding;` to that test. Do not "fix" a failure by widening the query back to `source` — the two disagreeing is the defect being repaired.

- [ ] **Step 8: Commit**

```bash
git add backend/app/Services/AuditReport/AuditEntitlementService.php backend/tests/Feature/Services/AuditEntitlementServiceTest.php
git commit -m "feat(audit): meter every tier through one tier-keyed quota lookup"
```

---

### Task 4: Expert credits in the catalog

Makes Expert a first-class metered tier. Seeded at zero on every plan — Agency at $499/mo cannot absorb a $999 audit — so the mechanism exists for a custom deal without giving anything away.

**Files:**
- Modify: `backend/config/pricing.php`
- Modify: `backend/database/seeders/AuditMonetizationSeeder.php:53-87`
- Modify: `backend/app/Console/Commands/ExportPricingCommand.php:65-76`
- Modify: `frontend/src/data/pricing.json` (regenerated, not hand-edited)
- Test: `backend/tests/Feature/Seeders/AuditMonetizationSeederTest.php` (append)

**Interfaces:**
- Consumes: nothing.
- Produces: `config('pricing.subscriptions.*.audit_expert_credits')` (int); product metadata key `audit_expert_credits`; `pricing.json` subscription key `expert_credits`.

- [ ] **Step 1: Write the failing test**

Append to `backend/tests/Feature/Seeders/AuditMonetizationSeederTest.php`:

```php
    public function test_every_plan_carries_an_expert_credits_metadata_key(): void
    {
        $this->seed(\Database\Seeders\AuditMonetizationSeeder::class);

        foreach (array_keys(config('pricing.subscriptions')) as $slug) {
            $product = \App\Models\Product::where('slug', $slug)->firstOrFail();

            $this->assertArrayHasKey(
                'audit_expert_credits',
                $product->metadata,
                "Plan {$slug} is missing audit_expert_credits metadata",
            );
            $this->assertSame(
                config("pricing.subscriptions.{$slug}.audit_expert_credits"),
                $product->metadata['audit_expert_credits'],
            );
        }
    }
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
docker compose exec laravel.test php artisan test --filter=AuditMonetizationSeederTest
```

Expected: FAIL — `Plan audit-starter is missing audit_expert_credits metadata`.

- [ ] **Step 3: Add the key to the pricing config**

In `backend/config/pricing.php`, add `'audit_expert_credits' => 0,` after each `'audit_deep_ai_credits'` line — all four of `audit-starter`, `audit-growth`, `audit-agency`, `audit-enterprise`. For example:

```php
        'audit-growth' => [
            'name' => 'Growth',
            'price' => 14900,
            'audit_analyses_per_month' => 20,
            'audit_deep_ai_credits' => 1,
            // Zero on every plan by design: a $999 audit does not fit inside
            // any current subscription price. The key exists so a custom deal
            // is an admin metadata edit rather than a code change.
            'audit_expert_credits' => 0,
            'is_popular' => true,
        ],
```

- [ ] **Step 4: Seed it**

In `AuditMonetizationSeeder::seedSubscriptions()`, add to the `metadata` array (line 67-70):

```php
                'metadata' => [
                    'audit_analyses_per_month' => $subscription['audit_analyses_per_month'],
                    'audit_deep_ai_credits' => $subscription['audit_deep_ai_credits'],
                    'audit_expert_credits' => $subscription['audit_expert_credits'],
                ],
```

Leave the `features` array alone — a "0 Expert audits / month" bullet advertises an absence.

- [ ] **Step 5: Export it**

In `ExportPricingCommand::payload()`, add to the subscriptions loop (after line 73):

```php
                'expert_credits' => $subscription['audit_expert_credits'],
```

- [ ] **Step 6: Regenerate the marketing data file**

```bash
docker compose exec laravel.test php artisan app:export-pricing
docker compose exec laravel.test php artisan app:export-pricing --check
```

Expected: `Wrote .../pricing.json`, then `Pricing data is current.`

- [ ] **Step 7: Run the test to confirm it passes**

```bash
docker compose exec laravel.test php artisan test --filter=AuditMonetizationSeederTest
```

Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add backend/config/pricing.php backend/database/seeders/AuditMonetizationSeeder.php backend/app/Console/Commands/ExportPricingCommand.php backend/tests/Feature/Seeders/AuditMonetizationSeederTest.php frontend/src/data/pricing.json
git commit -m "feat(pricing): add expert-audit credits to plan metadata"
```

---

### Task 5: Purchased runs stop consuming quota

`HandleAuditTierOrder` currently sets `free_run => false` but not `prepaid`, so a purchased run is indistinguishable from an allowance-funded one. It also gains the intent path Task 8 depends on.

**Files:**
- Modify: `backend/app/Listeners/Order/HandleAuditTierOrder.php`
- Test: `backend/tests/Feature/Listeners/HandleAuditTierOrderTest.php`

**Interfaces:**
- Consumes: `AuditFunding` (Task 1).
- Produces: `HandleAuditTierOrder::INTENT_PARAM = 'audit_tier_intent'` — the `UserParameter` name Task 8 writes, holding an `AuditRequest` uuid.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Listeners/HandleAuditTierOrderTest.php`. Follow the arrangement in the existing `HandleAuditUnlockOrderTest` for building an `Order` with a `OneTimeProduct` item.

```php
<?php

namespace Tests\Feature\Listeners;

use App\Constants\AuditFunding;
use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Events\Order\Ordered;
use App\Jobs\GenerateAuditReport;
use App\Listeners\Order\HandleAuditTierOrder;
use App\Models\AuditRequest;
use App\Models\UserParameter;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\FeatureTest;

class HandleAuditTierOrderTest extends FeatureTest
{
    public function test_a_purchased_run_is_prepaid_and_not_metered(): void
    {
        Queue::fake();
        $this->seed(\Database\Seeders\AuditMonetizationSeeder::class);

        $user = $this->createUser();
        AuditRequest::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'tier' => AuditTier::DIAGNOSTIC->value,
        ]);

        $order = $this->orderFor($user, 'audit-deep-ai');
        (new HandleAuditTierOrder)->handle(new Ordered($order));

        $run = AuditRequest::where('tier', AuditTier::DEEP_AI->value)->firstOrFail();

        $this->assertTrue($run->prepaid);
        $this->assertSame(AuditFunding::PURCHASE, $run->funding);
        $this->assertSame(0, app(\App\Services\AuditReport\AuditEntitlementService::class)
            ->runsUsedThisMonth($user, AuditTier::DEEP_AI));
        Queue::assertPushed(GenerateAuditReport::class);
    }

    public function test_an_intent_run_is_used_instead_of_cloning_a_diagnostic(): void
    {
        Queue::fake();
        $this->seed(\Database\Seeders\AuditMonetizationSeeder::class);

        $user = $this->createUser();
        $intended = AuditRequest::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'repo_url' => 'https://github.com/acme/intended',
            'tier' => AuditTier::DEEP_AI->value,
            'status' => AuditRequestStatus::AWAITING_PAYMENT->value,
            'funding' => AuditFunding::PURCHASE->value,
        ]);
        UserParameter::create([
            'user_id' => $user->id,
            'name' => HandleAuditTierOrder::INTENT_PARAM,
            'value' => $intended->uuid,
        ]);

        $order = $this->orderFor($user, 'audit-deep-ai');
        (new HandleAuditTierOrder)->handle(new Ordered($order));

        $intended->refresh();

        $this->assertSame(AuditRequestStatus::QUEUED->value, $intended->status);
        $this->assertTrue($intended->prepaid);
        $this->assertSame(1, AuditRequest::where('tier', AuditTier::DEEP_AI->value)->count());
        $this->assertNull(UserParameter::where('name', HandleAuditTierOrder::INTENT_PARAM)->first());
    }
}
```

Add this helper to the class (modelled on `HandleAuditUnlockOrderTest::unlockOrderFor()`), plus `use App\Models\OneTimeProduct;`, `use App\Models\Order;`, `use App\Models\Tenant;`, `use App\Models\User;`:

```php
    private function orderFor(User $user, string $slug): Order
    {
        $product = OneTimeProduct::where('slug', $slug)->firstOrFail();

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => Tenant::factory()->create()->id,
        ]);

        $order->items()->create([
            'one_time_product_id' => $product->id,
            'quantity' => 1,
            'currency_id' => $order->currency_id,
            'price_per_unit' => 19900,
            'price_per_unit_after_discount' => 19900,
            'discount_per_unit' => 0,
        ]);

        return $order;
    }
```

`createUser()` comes from `Tests\Feature\FeatureTest`.

- [ ] **Step 2: Run it to confirm it fails**

```bash
docker compose exec laravel.test php artisan test --filter=HandleAuditTierOrderTest
```

Expected: FAIL — `Undefined constant ...::INTENT_PARAM`.

- [ ] **Step 3: Add the intent path to the listener**

In `HandleAuditTierOrder`, add `use App\Constants\AuditFunding;` and `use App\Models\UserParameter;`, then add the constant and rework `handle()`:

```php
    /** Written by the dashboard when a user buys a tier for a named repo. */
    public const INTENT_PARAM = 'audit_tier_intent';

    public function handle(Ordered $event): void
    {
        $order = $event->order;

        foreach ($this->orderedProductSlugs($order) as $slug) {
            $tierValue = config("pricing.tiers.{$slug}.tier");

            if ($tierValue === null) {
                continue;
            }

            // A dashboard purchase already named the repository and the tier,
            // so honour that request rather than cloning an old diagnostic.
            $intended = $this->intentRequestFor($order, $tierValue);

            if ($intended !== null) {
                $intended->update([
                    'prepaid' => true,
                    'funding' => AuditFunding::PURCHASE->value,
                    'status' => AuditRequestStatus::QUEUED->value,
                ]);
                GenerateAuditReport::dispatch($intended);

                continue;
            }

            $source = $this->sourceRequestFor($order);

            if ($source === null) {
                continue;
            }

            $run = AuditRequest::create([
                'name' => $source->name,
                'email' => $source->email,
                'repo_url' => $source->repo_url,
                'message' => $source->message,
                'user_id' => $source->user_id,
                'tier' => $tierValue,
                'source' => $source->source,
                'status' => AuditRequestStatus::QUEUED->value,
                // A purchased run never consumes the free quota or plan quota.
                'free_run' => false,
                'prepaid' => true,
                'funding' => AuditFunding::PURCHASE->value,
                'email_verified_at' => $source->email_verified_at,
                'marketing_consent' => $source->marketing_consent,
                'consented_at' => $source->consented_at,
            ]);

            GenerateAuditReport::dispatch($run);
        }
    }

    /** The dashboard-created request this order was started to pay for. */
    private function intentRequestFor(Order $order, string $tierValue): ?AuditRequest
    {
        $intent = UserParameter::query()
            ->where('user_id', $order->user_id)
            ->where('name', self::INTENT_PARAM)
            ->first();

        if ($intent === null) {
            return null;
        }

        $request = AuditRequest::query()
            ->where('uuid', $intent->value)
            ->where('tier', $tierValue)
            ->where('status', AuditRequestStatus::AWAITING_PAYMENT->value)
            ->first();

        if ($request === null) {
            return null;
        }

        $intent->delete();

        return $request;
    }
```

- [ ] **Step 4: Run the test to confirm it passes**

```bash
docker compose exec laravel.test php artisan test --filter=HandleAuditTierOrderTest
```

Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add backend/app/Listeners/Order/HandleAuditTierOrder.php backend/tests/Feature/Listeners/HandleAuditTierOrderTest.php
git commit -m "fix(audit): purchased tier runs no longer consume plan quota"
```

---

### Task 6: Tier selection in `launchAudit()`

The core fix. The page currently creates untiered (therefore diagnostic) requests after gating on the automated allowance, so the allowance is never consumed and subscribers get the free pipeline.

**Files:**
- Create: `backend/tests/Support/CreatesAuditSubscriptions.php`
- Modify: `backend/app/Filament/Dashboard/Pages/AuditReports.php`
- Test: `backend/tests/Feature/Filament/Dashboard/AuditReportsTierSelectionTest.php`

**Interfaces:**
- Consumes: `quotaFor()`/`quotas()` (Task 3), `AuditFunding` (Task 1), `TierQuota` (Task 2).
- Produces: `AuditReports::$tier` (string, the selected `AuditTier` value); `launchAudit(?string $repoUrl = null, ?string $tier = null)`; view data key `quotas` (list of `TierQuota`). Trait `Tests\Support\CreatesAuditSubscriptions` with `userWithAllowance(int $analyses, int $deepAi = 0, int $expert = 0): array{0: User, 1: Tenant}` and `actAsTenantUser(User $user, ?Tenant $tenant = null): void` — used by Tasks 8, 9, and 10.

- [ ] **Step 1: Create the shared test trait**

Filament's dashboard panel is tenant-scoped, so a Livewire test must select the panel and the tenant before mounting a page — the pattern `PlanUsageWidgetTest` already uses.

```php
<?php

namespace Tests\Support;

use App\Constants\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;

trait CreatesAuditSubscriptions
{
    /** @return array{0: User, 1: Tenant} */
    protected function userWithAllowance(int $analyses, int $deepAi = 0, int $expert = 0): array
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user);

        $product = Product::factory()->create(['metadata' => [
            'audit_analyses_per_month' => $analyses,
            'audit_deep_ai_credits' => $deepAi,
            'audit_expert_credits' => $expert,
        ]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);

        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);

        return [$user, $tenant];
    }

    /** The dashboard panel is tenant-scoped; a page will not mount without this. */
    protected function actAsTenantUser(User $user, ?Tenant $tenant = null): void
    {
        if ($tenant === null) {
            $tenant = Tenant::factory()->create();
            $tenant->users()->attach($user);
        }

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);
    }
}
```

- [ ] **Step 2: Write the failing test**

```php
<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\AuditFunding;
use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Filament\Dashboard\Pages\AuditReports;
use App\Jobs\GenerateAuditReport;
use App\Models\AuditRequest;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;
use Tests\Support\CreatesAuditSubscriptions;

class AuditReportsTierSelectionTest extends FeatureTest
{
    use CreatesAuditSubscriptions;

    public function test_an_automated_run_is_created_at_that_tier_and_spends_the_allowance(): void
    {
        Queue::fake();
        [$user, $tenant] = $this->userWithAllowance(analyses: 5);
        $this->actAsTenantUser($user, $tenant);

        Livewire::test(AuditReports::class)
            ->set('repoUrl', 'https://github.com/acme/app')
            ->set('tier', AuditTier::AUTOMATED->value)
            ->call('launchAudit');

        $request = AuditRequest::latest('id')->firstOrFail();

        $this->assertSame(AuditTier::AUTOMATED, $request->tier);
        $this->assertSame(AuditFunding::ALLOWANCE, $request->funding);
        $this->assertSame('dashboard', $request->source);
        $this->assertSame(
            4,
            app(\App\Services\AuditReport\AuditEntitlementService::class)
                ->remainingRuns($user, $tenant, AuditTier::AUTOMATED),
        );
        Queue::assertPushed(GenerateAuditReport::class);
    }

    public function test_a_diagnostic_run_spends_a_free_run_not_the_allowance(): void
    {
        Queue::fake();
        [$user, $tenant] = $this->userWithAllowance(analyses: 5);
        $this->actAsTenantUser($user, $tenant);

        Livewire::test(AuditReports::class)
            ->set('repoUrl', 'https://github.com/acme/app')
            ->set('tier', AuditTier::DIAGNOSTIC->value)
            ->call('launchAudit');

        $request = AuditRequest::latest('id')->firstOrFail();

        $this->assertSame(AuditTier::DIAGNOSTIC, $request->tier);
        $this->assertSame(AuditFunding::FREE, $request->funding);
        $this->assertTrue($request->free_run);
        $this->assertSame(
            5,
            app(\App\Services\AuditReport\AuditEntitlementService::class)
                ->remainingRuns($user, $tenant, AuditTier::AUTOMATED),
        );
    }

    public function test_a_tier_with_no_quota_creates_nothing(): void
    {
        Queue::fake();
        // Zero expert credits, and Expert is purchasable -- so the guard must
        // route to checkout rather than queue anything. Setting the property
        // directly is exactly what a crafted request would do.
        [$user, $tenant] = $this->userWithAllowance(analyses: 5, expert: 0);
        $this->actAsTenantUser($user, $tenant);

        Livewire::test(AuditReports::class)
            ->set('repoUrl', 'https://github.com/acme/app')
            ->set('tier', AuditTier::EXPERT->value)
            ->call('launchAudit');

        $this->assertSame(
            0,
            AuditRequest::where('status', AuditRequestStatus::QUEUED->value)->count(),
        );
        Queue::assertNothingPushed();
    }

    public function test_an_unknown_tier_value_is_rejected(): void
    {
        Queue::fake();
        [$user, $tenant] = $this->userWithAllowance(analyses: 5);
        $this->actAsTenantUser($user, $tenant);

        Livewire::test(AuditReports::class)
            ->set('repoUrl', 'https://github.com/acme/app')
            ->set('tier', 'platinum')
            ->call('launchAudit');

        $this->assertSame(0, AuditRequest::count());
        Queue::assertNothingPushed();
    }
}
```

In the third test, `Queue::assertNothingPushed()` holds because the purchase branch redirects without creating a queued run. Until Task 8 replaces the stub `purchase()`, that test passes on the notification path; afterwards it passes on the redirect path. Both satisfy the assertion, which is the point — no run may start without quota.

- [ ] **Step 3: Run it to confirm it fails**

```bash
docker compose exec laravel.test php artisan test --filter=AuditReportsTierSelectionTest
```

Expected: FAIL — `Unable to set component data. Public property [$tier] not found`.

- [ ] **Step 4: Add the tier property and its default**

In `backend/app/Filament/Dashboard/Pages/AuditReports.php`, add imports for `App\Constants\AuditFunding`, `App\Constants\AuditTier`, and `App\Services\AuditReport\TierQuota`, then add below `public ?string $repoUrl = null;`:

```php
    public string $tier = '';

    public function mount(): void
    {
        $this->tier = $this->defaultTier()->value;
    }

    /**
     * Preselect the best tier the user can actually run, so the common case is
     * one click. Diagnostic is the floor -- it is always offered, even at zero,
     * because its exhausted state is the free-quota upsell.
     */
    private function defaultTier(): AuditTier
    {
        $user = auth()->user();
        $tenant = Filament::getTenant();
        $entitlements = app(AuditEntitlementService::class);

        foreach ([AuditTier::AUTOMATED, AuditTier::DEEP_AI, AuditTier::EXPERT, AuditTier::DIAGNOSTIC] as $tier) {
            if ($entitlements->remainingRuns($user, $tenant, $tier) > 0) {
                return $tier;
            }
        }

        return AuditTier::DIAGNOSTIC;
    }
```

- [ ] **Step 5: Rewrite `launchAudit()`**

Replace the whole method (lines 51-94):

```php
    public function launchAudit(?string $repoUrl = null, ?string $tier = null): void
    {
        $repoUrl ??= $this->repoUrl;
        $user = auth()->user();
        $tenant = Filament::getTenant();
        $entitlements = app(AuditEntitlementService::class);

        // $tier arrives from a client-controlled Livewire property, so the
        // rendered UI is a hint and this method is the gate.
        $selected = AuditTier::tryFrom($tier ?? $this->tier);

        if ($selected === null) {
            Notification::make()->title(__('Choose an audit type'))->danger()->send();

            return;
        }

        if ($repoUrl === null || ! str_starts_with($repoUrl, 'http')) {
            Notification::make()->title(__('Enter a valid repository URL'))->danger()->send();

            return;
        }

        $quota = $entitlements->quotaFor($user, $tenant, $selected);

        if (! $quota->hasRuns()) {
            if ($quota->purchasable()) {
                $this->purchase($repoUrl, $selected);

                return;
            }

            Notification::make()
                ->title(__('No :tier runs left', ['tier' => $quota->label]))
                ->body(__('Upgrade your plan to run more audits.'))
                ->warning()
                ->send();

            return;
        }

        $auditRequest = AuditRequest::create([
            'name' => $user->name,
            'email' => $user->email,
            'repo_url' => $repoUrl,
            'status' => AuditRequestStatus::QUEUED->value,
            'email_verified_at' => now(),
            'source' => 'dashboard',
            'tier' => $selected->value,
            'funding' => $quota->isLifetime
                ? AuditFunding::FREE->value
                : AuditFunding::ALLOWANCE->value,
            'user_id' => $user->id,
        ]);

        // An allowance run is metered simply by existing at its tier. A free
        // run has to be flagged on the request to be deducted.
        if ($quota->isLifetime) {
            $entitlements->consumeFreeRun($auditRequest);
        }

        GenerateAuditReport::dispatch($auditRequest);
        $this->repoUrl = null;

        Notification::make()
            ->title(__('Audit started'))
            ->body(__('You\'ll get an email when the report is ready.'))
            ->success()
            ->send();
    }

    /** Filled in by Task 8. */
    private function purchase(string $repoUrl, AuditTier $tier): void
    {
        Notification::make()->title(__('No runs left'))->warning()->send();
    }
```

- [ ] **Step 6: Publish the quotas to the view**

In `getViewData()`, replace the `$allowance` / `$remainingRuns` / `$freeRunsRemaining` / `$canRun` block (lines 134-145) with:

```php
        $quotas = $entitlements->quotas($user, $tenant);

        return [
            'reports' => $reports,
            'quotas' => $quotas,
            // Any tier can start a run: one from quota, the rest by purchase.
            'canRun' => collect($quotas)->contains(
                fn (TierQuota $quota): bool => $quota->hasRuns() || $quota->purchasable(),
            ),
```

Keep the remaining keys (`schedules`, `repoGroups`, `deltas`) unchanged, and drop `allowance`, `remainingRuns`, and `freeRunsRemaining` — Task 7 rewrites the blade that reads them.

- [ ] **Step 7: Show expert-tier runs in the list**

In `getViewData()`, delete the `whereHas` that filters out `expert_review` (line 130). A $999 run held for review must not leave the customer looking at an empty list.

```php
        $reports = $user->auditReports()
            ->with('auditRequest')
            ->latest()
            ->get();
```

- [ ] **Step 8: Run the test to confirm it passes**

```bash
docker compose exec laravel.test php artisan test --filter=AuditReportsTierSelectionTest
```

Expected: PASS (4 tests). The blade still references removed keys, so a full page render will fail until Task 7 — these tests exercise `launchAudit()`, not rendering.

- [ ] **Step 9: Commit**

```bash
git add backend/app/Filament/Dashboard/Pages/AuditReports.php backend/tests/Support/CreatesAuditSubscriptions.php backend/tests/Feature/Filament/Dashboard/AuditReportsTierSelectionTest.php
git commit -m "fix(audit): dashboard runs record their tier and spend that tier's quota"
```

---

### Task 7: The selector UI

**Files:**
- Modify: `backend/resources/views/filament/dashboard/pages/audit-reports.blade.php`
- Test: `backend/tests/Feature/Filament/Dashboard/AuditReportsRenderTest.php`

**Interfaces:**
- Consumes: view data keys `quotas` and `canRun` (Task 6).
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\AuditRequestStatus;
use App\Filament\Dashboard\Pages\AuditReports;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;
use Tests\Support\CreatesAuditSubscriptions;

class AuditReportsRenderTest extends FeatureTest
{
    use CreatesAuditSubscriptions;

    public function test_the_page_lists_every_tier(): void
    {
        [$user, $tenant] = $this->userWithAllowance(analyses: 5, deepAi: 1);
        $this->actAsTenantUser($user, $tenant);

        Livewire::test(AuditReports::class)
            ->assertOk()
            ->assertSee('Free Diagnostic')
            ->assertSee('Automated Health Report')
            ->assertSee('Deep AI Code Review')
            ->assertSee('Expert Audit');
    }

    public function test_a_report_held_for_expert_review_is_visible(): void
    {
        [$user, $tenant] = $this->userWithAllowance(analyses: 5);
        $this->actAsTenantUser($user, $tenant);

        // Let the factory build its own request, then bend that request into
        // the held state -- this does not assume the report factory's FK name.
        $report = AuditReport::factory()->create(['user_id' => $user->id]);
        $report->auditRequest->update([
            'user_id' => $user->id,
            'email' => $user->email,
            'repo_url' => 'https://github.com/acme/held',
            'status' => AuditRequestStatus::EXPERT_REVIEW->value,
        ]);

        Livewire::test(AuditReports::class)
            ->assertOk()
            ->assertSee('In expert review');
    }
}
```

Drop the unused `AuditRequest` import if the second test no longer references it.

- [ ] **Step 2: Run it to confirm it fails**

```bash
docker compose exec laravel.test php artisan test --filter=AuditReportsRenderTest
```

Expected: FAIL — `Undefined variable $allowance`.

- [ ] **Step 3: Replace the submit section**

Replace lines 2-21 of the blade:

```blade
    @if ($canRun)
        <x-filament::section class="mb-6">
            <div class="flex flex-wrap items-end gap-3">
                <div class="grow">
                    <label class="text-sm font-medium" for="audit-repo-url">{{ __('Repository URL') }}</label>
                    <x-filament::input.wrapper>
                        <x-filament::input id="audit-repo-url" type="url" wire:model="repoUrl" placeholder="https://github.com/you/repo" />
                    </x-filament::input.wrapper>
                </div>
            </div>

            <fieldset class="mt-4">
                <legend class="text-sm font-medium">{{ __('Audit type') }}</legend>
                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                    @foreach ($quotas as $quota)
                        <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                            <input type="radio" wire:model.live="tier" value="{{ $quota->tier->value }}" class="mt-1" />
                            <span>
                                <span class="block text-sm font-medium">{{ $quota->label }}</span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">
                                    @if ($quota->hasRuns())
                                        @if ($quota->isLifetime)
                                            {{ trans_choice('{1} :count free run left|[2,*] :count free runs left', $quota->remaining(), ['count' => $quota->remaining()]) }}
                                        @else
                                            {{ __(':remaining of :limit left this month', ['remaining' => $quota->remaining(), 'limit' => $quota->limit]) }}
                                        @endif
                                    @elseif ($quota->purchasable())
                                        {{ __('Buy for $:price', ['price' => number_format($quota->priceCents / 100)]) }}
                                    @else
                                        {{ __('None left') }}
                                    @endif
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            @php
                $selected = collect($quotas)->firstWhere(fn ($q) => $q->tier->value === $tier);
            @endphp

            <div class="mt-4">
                <x-filament::button wire:click="launchAudit">
                    @if ($selected && ! $selected->hasRuns() && $selected->purchasable())
                        {{ __('Buy this audit — $:price', ['price' => number_format($selected->priceCents / 100)]) }}
                    @else
                        {{ __('Run new audit') }}
                    @endif
                </x-filament::button>
            </div>
        </x-filament::section>
    @endif
```

- [ ] **Step 4: Fix the two remaining `$allowance` references**

The per-repo schedule block is gated on `$allowance > 0` (line 63) and the re-run button drops the tier (line 75). Replace the opening of that block:

```blade
            @php
                $automated = collect($quotas)->firstWhere(fn ($q) => $q->tier === \App\Constants\AuditTier::AUTOMATED);
                $originTier = $group['reports']->first()->auditRequest->tier->value;
            @endphp

            @if ($automated && $automated->limit > 0)
```

and the re-run button, so re-running a Deep AI audit does not silently downgrade:

```blade
                    <x-filament::button size="sm" color="gray" wire:click="launchAudit('{{ $repoUrl }}', '{{ $originTier }}')">
                        {{ __('Re-run') }}
                    </x-filament::button>
```

- [ ] **Step 5: Mark reports held for review**

In the per-report row (around line 87), replace the action buttons with a state check:

```blade
                        <div class="flex items-center gap-2">
                            @if ($report->auditRequest->status === \App\Constants\AuditRequestStatus::EXPERT_REVIEW->value)
                                <x-filament::badge color="warning">{{ __('In expert review') }}</x-filament::badge>
                            @else
                                <span class="text-sm font-medium">{{ data_get($report->payload, 'scores.overall', '—') }}</span>
                                <x-filament::button tag="a" size="xs" color="gray" href="{{ route('reports.download', $report) }}">
                                    {{ __('PDF') }}
                                </x-filament::button>
                                <x-filament::button tag="a" size="xs" color="primary" href="{{ app(\App\Services\AuditReport\AuditReportService::class)->signedUrl($report) }}">
                                    {{ __('View') }}
                                </x-filament::button>
                            @endif
                        </div>
```

- [ ] **Step 6: Run both dashboard test classes**

```bash
docker compose exec laravel.test php artisan test --filter=AuditReports
```

Expected: PASS — both `AuditReportsRenderTest` and `AuditReportsTierSelectionTest`.

- [ ] **Step 7: Commit**

```bash
git add backend/resources/views/filament/dashboard/pages/audit-reports.blade.php backend/tests/Feature/Filament/Dashboard/AuditReportsRenderTest.php
git commit -m "feat(audit): add the audit-type selector to the dashboard"
```

---

### Task 8: Checkout fallback

An exhausted but purchasable tier routes to the existing one-time checkout, carrying the repository and tier the user just chose.

**Files:**
- Modify: `backend/app/Filament/Dashboard/Pages/AuditReports.php`
- Test: `backend/tests/Feature/Filament/Dashboard/AuditReportsPurchaseTest.php`

**Interfaces:**
- Consumes: `HandleAuditTierOrder::INTENT_PARAM` (Task 5); `AuditRequestStatus::AWAITING_PAYMENT`.
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\AuditFunding;
use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Filament\Dashboard\Pages\AuditReports;
use App\Jobs\GenerateAuditReport;
use App\Listeners\Order\HandleAuditTierOrder;
use App\Models\AuditRequest;
use App\Models\UserParameter;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;
use Tests\Support\CreatesAuditSubscriptions;

class AuditReportsPurchaseTest extends FeatureTest
{
    use CreatesAuditSubscriptions;

    public function test_an_exhausted_paid_tier_creates_an_intent_and_redirects(): void
    {
        Queue::fake();
        $this->seed(\Database\Seeders\AuditMonetizationSeeder::class);
        [$user, $tenant] = $this->userWithAllowance(analyses: 5, deepAi: 0);
        $this->actAsTenantUser($user, $tenant);

        Livewire::test(AuditReports::class)
            ->set('repoUrl', 'https://github.com/acme/app')
            ->set('tier', AuditTier::DEEP_AI->value)
            ->call('launchAudit')
            ->assertRedirect(route('buy.product', ['productSlug' => 'audit-deep-ai']));

        $request = AuditRequest::latest('id')->firstOrFail();

        $this->assertSame(AuditTier::DEEP_AI, $request->tier);
        $this->assertSame(AuditRequestStatus::AWAITING_PAYMENT->value, $request->status);
        $this->assertSame(AuditFunding::PURCHASE, $request->funding);
        $this->assertSame('https://github.com/acme/app', $request->repo_url);

        $this->assertSame(
            $request->uuid,
            UserParameter::where('user_id', $user->id)
                ->where('name', HandleAuditTierOrder::INTENT_PARAM)
                ->value('value'),
        );

        // Nothing runs until the order lands.
        Queue::assertNothingPushed();
    }

    public function test_an_unpaid_intent_does_not_consume_quota(): void
    {
        Queue::fake();
        $this->seed(\Database\Seeders\AuditMonetizationSeeder::class);
        [$user, $tenant] = $this->userWithAllowance(analyses: 5, deepAi: 1);
        $this->actAsTenantUser($user, $tenant);

        // Spend the single Deep AI credit, then try again.
        Livewire::test(AuditReports::class)
            ->set('repoUrl', 'https://github.com/acme/one')
            ->set('tier', AuditTier::DEEP_AI->value)
            ->call('launchAudit');

        Livewire::test(AuditReports::class)
            ->set('repoUrl', 'https://github.com/acme/two')
            ->set('tier', AuditTier::DEEP_AI->value)
            ->call('launchAudit');

        // The pending purchase must not push usage past the credit that was
        // actually spent.
        $this->assertSame(
            1,
            app(\App\Services\AuditReport\AuditEntitlementService::class)
                ->runsUsedThisMonth($user, AuditTier::DEEP_AI),
        );
    }
}
```

`Queue::assertNothingPushed()` in the first test is what proves the intent is inert: nothing may run until the order lands.

- [ ] **Step 2: Run it to confirm it fails**

```bash
docker compose exec laravel.test php artisan test --filter=AuditReportsPurchaseTest
```

Expected: FAIL — no redirect; the stub `purchase()` only sends a notification.

- [ ] **Step 3: Implement `purchase()`**

Replace the Task 6 stub in `AuditReports`, adding `use App\Listeners\Order\HandleAuditTierOrder;` and `use App\Models\UserParameter;`:

```php
    /**
     * Capture the repository and tier now, pay for them next.
     *
     * The request is created up front so the choice survives the round trip
     * through checkout; HandleAuditTierOrder finds it by intent and runs it.
     * It is funded as a purchase from the start, so a customer who abandons
     * checkout is never charged a plan credit for it.
     */
    private function purchase(string $repoUrl, AuditTier $tier): void
    {
        $user = auth()->user();
        $slug = collect((array) config('pricing.tiers'))
            ->search(fn (array $definition): bool => ($definition['tier'] ?? null) === $tier->value);

        if ($slug === false) {
            Notification::make()->title(__('That audit type is not for sale'))->danger()->send();

            return;
        }

        $auditRequest = AuditRequest::create([
            'name' => $user->name,
            'email' => $user->email,
            'repo_url' => $repoUrl,
            'status' => AuditRequestStatus::AWAITING_PAYMENT->value,
            'email_verified_at' => now(),
            'source' => 'dashboard',
            'tier' => $tier->value,
            'funding' => AuditFunding::PURCHASE->value,
            'user_id' => $user->id,
        ]);

        UserParameter::updateOrCreate(
            ['user_id' => $user->id, 'name' => HandleAuditTierOrder::INTENT_PARAM],
            ['value' => $auditRequest->uuid],
        );

        $this->redirect(route('buy.product', ['productSlug' => $slug]));
    }
```

- [ ] **Step 4: Run the test to confirm it passes**

```bash
docker compose exec laravel.test php artisan test --filter=AuditReportsPurchaseTest
```

Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add backend/app/Filament/Dashboard/Pages/AuditReports.php backend/tests/Feature/Filament/Dashboard/AuditReportsPurchaseTest.php backend/tests/Support/CreatesAuditSubscriptions.php
git commit -m "feat(audit): route an exhausted tier to one-time checkout"
```

---

### Task 9: Per-schedule tier

`RunScheduledAudits` has the same defect as the dashboard page: it checks the automated allowance, then creates an untiered request.

**Files:**
- Create: `backend/database/migrations/2026_08_14_000002_add_tier_to_audit_schedules_table.php`
- Modify: `backend/app/Models/AuditSchedule.php`
- Modify: `backend/app/Console/Commands/RunScheduledAudits.php`
- Modify: `backend/app/Filament/Dashboard/Pages/AuditReports.php` (`setSchedule`, lines 96-120)
- Modify: `backend/resources/views/filament/dashboard/pages/audit-reports.blade.php`
- Test: `backend/tests/Feature/Console/RunScheduledAuditsTest.php`

**Interfaces:**
- Consumes: `remainingRuns()` (Task 3), `AuditFunding` (Task 1).
- Produces: `audit_schedules.tier`; `AuditReports::setSchedule(string $repoUrl, string $frequency, ?string $tier = null)`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Console;

use App\Constants\AuditFunding;
use App\Constants\AuditTier;
use App\Jobs\GenerateAuditReport;
use App\Models\AuditRequest;
use App\Models\AuditSchedule;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\FeatureTest;
use Tests\Support\CreatesAuditSubscriptions;

class RunScheduledAuditsTest extends FeatureTest
{
    use CreatesAuditSubscriptions;

    public function test_a_schedule_runs_at_its_own_tier(): void
    {
        Queue::fake();
        [$user, $tenant] = $this->userWithAllowance(analyses: 5, deepAi: 2);

        AuditSchedule::create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'repo_url' => 'https://github.com/acme/app',
            'frequency' => 'weekly',
            'tier' => AuditTier::DEEP_AI->value,
        ]);

        $this->artisan('app:run-scheduled-audits')->assertSuccessful();

        $request = AuditRequest::latest('id')->firstOrFail();

        $this->assertSame(AuditTier::DEEP_AI, $request->tier);
        $this->assertSame(AuditFunding::ALLOWANCE, $request->funding);
        Queue::assertPushed(GenerateAuditReport::class);
    }

    public function test_an_exhausted_tier_is_skipped_not_downgraded(): void
    {
        Queue::fake();
        [$user, $tenant] = $this->userWithAllowance(analyses: 5, deepAi: 0);

        AuditSchedule::create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'repo_url' => 'https://github.com/acme/app',
            'frequency' => 'weekly',
            'tier' => AuditTier::DEEP_AI->value,
        ]);

        $this->artisan('app:run-scheduled-audits')->assertSuccessful();

        $this->assertSame(0, AuditRequest::count());
        Queue::assertNothingPushed();
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
docker compose exec laravel.test php artisan test --filter=RunScheduledAuditsTest
```

Expected: FAIL — `Unknown column 'tier'` on `audit_schedules`.

- [ ] **Step 3: Add the column**

```php
<?php

use App\Constants\AuditTier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_schedules', function (Blueprint $table) {
            // Automated, not diagnostic: a schedule is a subscriber feature,
            // and the allowance the command already checks meters that tier.
            $table->string('tier')->default(AuditTier::AUTOMATED->value)->after('frequency')->index();
        });
    }

    public function down(): void
    {
        Schema::table('audit_schedules', function (Blueprint $table) {
            $table->dropIndex(['tier']);
            $table->dropColumn('tier');
        });
    }
};
```

In `backend/app/Models/AuditSchedule.php`, add `'tier'` to `$fillable` and `'tier' => AuditTier::class` to `$casts` (add `use App\Constants\AuditTier;`).

- [ ] **Step 4: Use the tier in the command**

In `RunScheduledAudits::handle()`, replace the loop body (lines 27-41):

```php
            $tier = $schedule->tier;

            if ($entitlements->remainingRuns($schedule->user, $schedule->tenant, $tier) < 1) {
                // Never downgrade to a cheaper tier and never auto-charge:
                // both deliver something the customer did not agree to at
                // schedule time. Logged, because a schedule that quietly
                // stops firing is otherwise invisible.
                $this->warn("Skipping {$schedule->repo_url}: no {$tier->value} runs left for {$schedule->user->email}");

                continue;
            }

            $auditRequest = AuditRequest::create([
                'name' => $schedule->user->name,
                'email' => $schedule->user->email,
                'repo_url' => $schedule->repo_url,
                'status' => AuditRequestStatus::QUEUED->value,
                'email_verified_at' => now(),
                'source' => 'dashboard',
                'tier' => $tier->value,
                'funding' => AuditFunding::ALLOWANCE->value,
                'user_id' => $schedule->user->id,
            ]);
```

Add `use App\Constants\AuditFunding;`.

- [ ] **Step 5: Accept a tier when saving a schedule**

In `AuditReports::setSchedule()`, change the signature and the write:

```php
    public function setSchedule(string $repoUrl, string $frequency, ?string $tier = null): void
    {
        $user = auth()->user();
        $tenant = Filament::getTenant();
        $selected = AuditTier::tryFrom($tier ?? AuditTier::AUTOMATED->value) ?? AuditTier::AUTOMATED;

        if ($tenant === null || ! in_array($frequency, ['off', 'weekly', 'monthly'], true)) {
            return;
        }

        $repoUrl = rtrim($repoUrl, '/');

        if ($frequency === 'off') {
            AuditSchedule::query()->where('user_id', $user->id)->where('repo_url', $repoUrl)->delete();
            Notification::make()->title(__('Scheduled audits turned off'))->success()->send();

            return;
        }

        AuditSchedule::updateOrCreate(
            ['user_id' => $user->id, 'repo_url' => $repoUrl],
            ['tenant_id' => $tenant->id, 'frequency' => $frequency, 'tier' => $selected->value],
        );

        Notification::make()->title(__('Audits scheduled :frequency', ['frequency' => __($frequency)]))->success()->send();
    }
```

`getViewData()`'s `schedules` key currently plucks `frequency` by repo. Change it to carry both:

```php
            'schedules' => AuditSchedule::query()->where('user_id', $user->id)
                ->get()
                ->keyBy(fn (AuditSchedule $s): string => rtrim($s->repo_url, '/')),
```

- [ ] **Step 6: Add the tier select to the schedule control**

In the blade's schedule block, update the frequency select's `wire:change` to pass the tier, and add a second select. Replace the existing `<select>` (lines 65-73):

```blade
                    @php
                        $schedule = $schedules[rtrim($repoUrl, '/')] ?? null;
                        $scheduleFrequency = $schedule->frequency ?? 'off';
                        $scheduleTier = $schedule?->tier->value ?? \App\Constants\AuditTier::AUTOMATED->value;
                    @endphp

                    <select
                        class="fi-select-input rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800"
                        wire:change="setSchedule('{{ $repoUrl }}', $event.target.value, '{{ $scheduleTier }}')"
                        aria-label="{{ __('Audit schedule for :repo', ['repo' => $repoUrl]) }}"
                    >
                        @foreach (['off' => __('No schedule'), 'weekly' => __('Audit weekly'), 'monthly' => __('Audit monthly')] as $value => $optionLabel)
                            <option value="{{ $value }}" @selected($scheduleFrequency === $value)>{{ $optionLabel }}</option>
                        @endforeach
                    </select>

                    @if ($scheduleFrequency !== 'off')
                        <select
                            class="fi-select-input rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800"
                            wire:change="setSchedule('{{ $repoUrl }}', '{{ $scheduleFrequency }}', $event.target.value)"
                            aria-label="{{ __('Scheduled audit type for :repo', ['repo' => $repoUrl]) }}"
                        >
                            @foreach ($quotas as $quota)
                                @if (! $quota->isLifetime)
                                    <option value="{{ $quota->tier->value }}" @selected($scheduleTier === $quota->tier->value)>{{ $quota->label }}</option>
                                @endif
                            @endforeach
                        </select>
                    @endif
```

- [ ] **Step 7: Run the tests**

```bash
docker compose exec laravel.test php artisan test --filter=RunScheduledAuditsTest
```

Expected: PASS (2 tests). Then re-run the dashboard tests, since `schedules` changed shape:

```bash
docker compose exec laravel.test php artisan test --filter=AuditReports
```

Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add backend/database/migrations/2026_08_14_000002_add_tier_to_audit_schedules_table.php backend/app/Models/AuditSchedule.php backend/app/Console/Commands/RunScheduledAudits.php backend/app/Filament/Dashboard/Pages/AuditReports.php backend/resources/views/filament/dashboard/pages/audit-reports.blade.php backend/tests/Feature/Console/RunScheduledAuditsTest.php
git commit -m "feat(audit): scheduled audits carry and meter their own tier"
```

---

### Task 10: Per-tier usage bars

**Files:**
- Modify: `backend/app/Filament/Dashboard/Widgets/PlanUsageWidget.php:31-82`
- Test: `backend/tests/Feature/Filament/Dashboard/PlanUsageWidgetTest.php` (append)

**Interfaces:**
- Consumes: `quotas()` (Task 3).
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Write the failing test**

```php
    public function test_a_bar_is_shown_for_every_tier_with_an_allowance(): void
    {
        [$user, $tenant] = $this->userWithAllowance(analyses: 20, deepAi: 1, expert: 1);
        $this->actAsTenantUser($user, $tenant);

        Livewire::test(PlanUsageWidget::class)
            ->assertSee('Automated Health Report')
            ->assertSee('Deep AI Code Review')
            ->assertSee('Expert Audit');
    }

    public function test_a_tier_with_no_allowance_is_not_advertised(): void
    {
        [$user, $tenant] = $this->userWithAllowance(analyses: 20, deepAi: 0, expert: 0);
        $this->actAsTenantUser($user, $tenant);

        Livewire::test(PlanUsageWidget::class)
            ->assertSee('Automated Health Report')
            ->assertDontSee('Deep AI Code Review')
            ->assertDontSee('Expert Audit');
    }
```

Add `use Tests\Support\CreatesAuditSubscriptions;` and `use CreatesAuditSubscriptions;` inside the class. The existing tests in this file assert on the old `Analyses this month` label — update those to `Automated Health Report`, which is what the widget now renders.

- [ ] **Step 2: Run it to confirm it fails**

```bash
docker compose exec laravel.test php artisan test --filter=PlanUsageWidgetTest
```

Expected: FAIL — the widget renders `Analyses this month`, not the tier labels.

- [ ] **Step 3: Build the bars from quotas**

Replace `getViewData()`'s bar-building block (lines 42-81):

```php
        $quotas = $entitlements->quotas($user, $tenant);
        $metered = collect($quotas)->reject(fn (TierQuota $quota): bool => $quota->isLifetime);

        $colors = ['bg-primary-500', 'bg-secondary-500', 'bg-warning-500'];
        $bars = [];

        foreach ($metered->values() as $index => $quota) {
            // Hidden entirely at zero: a plan without credits for a tier
            // should not advertise them.
            if ($quota->limit < 1) {
                continue;
            }

            $bars[] = [
                'label' => $quota->label,
                'used' => $quota->used,
                'total' => $quota->limit,
                'color' => $colors[$index % count($colors)],
            ];
        }

        if ($bars === []) {
            $free = collect($quotas)->firstWhere(fn (TierQuota $quota): bool => $quota->isLifetime);

            $bars[] = [
                'label' => __('Free audits'),
                'used' => $free?->used ?? 0,
                'total' => $free?->limit ?? 0,
                'color' => 'bg-primary-500',
            ];
        }

        return [
            'planName' => $subscription?->plan?->name ?? __('Free'),
            'renewsAt' => $subscription?->ends_at ? Carbon::parse($subscription->ends_at) : null,
            'bars' => $bars,
            'showUpgrade' => collect($quotas)->every(fn (TierQuota $quota): bool => ! $quota->hasRuns()),
        ];
```

Add `use App\Services\AuditReport\TierQuota;`.

- [ ] **Step 4: Run the test to confirm it passes**

```bash
docker compose exec laravel.test php artisan test --filter=PlanUsageWidgetTest
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Filament/Dashboard/Widgets/PlanUsageWidget.php backend/tests/Feature/Filament/Dashboard/PlanUsageWidgetTest.php
git commit -m "feat(audit): show one usage bar per metered tier"
```

---

### Task 11: Operator tier control and caller cleanup

Lets an operator correct a tier and re-run, and removes the delegate methods now that every caller has migrated.

**Files:**
- Modify: `backend/app/Filament/Admin/Resources/AuditRequests/AuditRequestResource.php:65-86`
- Modify: `backend/app/Filament/Dashboard/Resources/AuditRequests/Pages/ListAuditRequests.php:19-27`
- Modify: `backend/app/Services/AuditRequestService.php`
- Modify: `backend/app/Services/AuditReport/AuditEntitlementService.php`
- Test: `backend/tests/Feature/Filament/Admin/AuditRequestTierTest.php`

**Interfaces:**
- Consumes: everything above.
- Produces: `AuditEntitlementService` no longer exposes `subscriptionAllowance`, `deepAiCredits`, `dashboardRunsUsedThisMonth`, `deepAiRunsUsedThisMonth`, `remainingDashboardRuns`, `remainingDeepAiRuns`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Filament\Admin;

use App\Constants\AuditTier;
use App\Filament\Admin\Resources\AuditRequests\Pages\EditAuditRequest;
use App\Models\AuditRequest;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class AuditRequestTierTest extends FeatureTest
{
    public function test_an_operator_can_change_a_requests_tier(): void
    {
        $admin = $this->createAdminUser();
        $request = AuditRequest::factory()->create(['tier' => AuditTier::DIAGNOSTIC->value]);

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(EditAuditRequest::class, ['record' => $request->getKey()])
            ->fillForm(['tier' => AuditTier::DEEP_AI->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(AuditTier::DEEP_AI, $request->fresh()->tier);
    }
}
```

`createAdminUser()` comes from `Tests\Feature\FeatureTest`. Add `use Filament\Facades\Filament;`. The admin panel is not tenant-scoped, so no `setTenant()` call is needed. `fillForm()` needs every required field present — if `save` reports errors on `status`, `name`, or `email`, include their current values in the `fillForm()` array.

- [ ] **Step 2: Run it to confirm it fails**

```bash
docker compose exec laravel.test php artisan test --filter=AuditRequestTierTest
```

Expected: FAIL — no `tier` field on the form.

- [ ] **Step 3: Add the tier select**

In `AuditRequestResource::form()`, add after the `status` select. Add `use App\Constants\AuditTier;` and confirm `Select` is already imported.

```php
            Select::make('tier')
                ->label(__('Audit type'))
                ->options(
                    collect(AuditTier::cases())
                        ->mapWithKeys(fn (AuditTier $tier): array => [$tier->value => $tier->label()])
                        ->all()
                )
                ->helperText(__('Changing this and re-running the pipeline re-analyses at the new tier.'))
                ->required(),
```

- [ ] **Step 4: Fix the resource action's visibility**

In `ListAuditRequests`, the `runNewAudit` action is visible only when `remainingDashboardRuns > 0`, so a free-quota user sees it on the page but not here. Replace that condition with the same test the page uses:

```php
            ->visible(fn (): bool => collect(
                app(AuditEntitlementService::class)->quotas(auth()->user(), Filament::getTenant())
            )->contains(fn (TierQuota $quota): bool => $quota->hasRuns() || $quota->purchasable()))
```

Add `use App\Services\AuditReport\TierQuota;` and `use Filament\Facades\Filament;`.

- [ ] **Step 5: Mark the guest funnel as free-funded**

In `AuditRequestService::submit()`, add `'funding' => AuditFunding::FREE->value,` to the `AuditRequest::create()` array. The guest funnel is the free acquisition step and never spends plan quota. Add `use App\Constants\AuditFunding;`.

- [ ] **Step 6: Check the admin launch action**

`AuditRequestResource`'s `launch` action (around line 243) calls `consumeFreeRun()` when a free run is available, then queues regardless. Since Task 3 made `consumeFreeRun()` set `funding => free`, the free-run branch is already correct and needs no edit.

The other branch — no free run available — queues an existing record without touching `funding`, which is right: an operator launching a request the customer already owns should not re-fund it. Confirm by reading the action; change nothing unless it writes `funding` itself.

- [ ] **Step 7: Delete the delegates**

Remove the six retained methods from `AuditEntitlementService` (`subscriptionAllowance`, `deepAiCredits`, `dashboardRunsUsedThisMonth`, `deepAiRunsUsedThisMonth`, `remainingDashboardRuns`, `remainingDeepAiRuns`) along with their block comment. Then confirm nothing still calls them:

```bash
docker compose exec laravel.test grep -rn "remainingDashboardRuns\|remainingDeepAiRuns\|subscriptionAllowance\|deepAiCredits\|dashboardRunsUsedThisMonth\|deepAiRunsUsedThisMonth" app/ tests/
```

Expected: no output. Any hit is a caller that still needs converting to `remainingRuns()` / `allowance()` / `runsUsedThisMonth()` with an explicit `AuditTier`.

- [ ] **Step 8: Run the affected tests**

```bash
docker compose exec laravel.test php artisan test --filter=AuditRequestTierTest
```

Expected: PASS. Then:

```bash
docker compose exec laravel.test php artisan test --filter=Audit
```

Expected: PASS across every audit test class.

- [ ] **Step 9: Commit**

```bash
git add backend/app/Filament/Admin/Resources/AuditRequests/AuditRequestResource.php backend/app/Filament/Dashboard/Resources/AuditRequests/Pages/ListAuditRequests.php backend/app/Services/AuditRequestService.php backend/app/Services/AuditReport/AuditEntitlementService.php backend/tests/Feature/Filament/Admin/AuditRequestTierTest.php
git commit -m "feat(audit): operator tier control; drop the per-tier delegate methods"
```

---

### Task 12: Sweep abandoned checkouts

Task 8's up-front request means an abandoned checkout leaves an `awaiting_payment` row behind. Nothing collects them today.

**Files:**
- Modify: `backend/app/Console/Commands/PurgeUnverifiedAuditRequests.php`
- Test: `backend/tests/Feature/Console/PurgeUnverifiedAuditRequestsTest.php`

**Interfaces:**
- Consumes: `AuditRequestStatus::AWAITING_PAYMENT`.
- Produces: nothing.

- [ ] **Step 1: Write the failing test**

```php
    public function test_abandoned_checkouts_are_purged_after_the_window(): void
    {
        $days = (int) config('audit.unverified_purge_days');

        $stale = AuditRequest::factory()->create([
            'status' => AuditRequestStatus::AWAITING_PAYMENT->value,
            'email_verified_at' => now(),
            'created_at' => now()->subDays($days + 1),
        ]);
        $recent = AuditRequest::factory()->create([
            'status' => AuditRequestStatus::AWAITING_PAYMENT->value,
            'email_verified_at' => now(),
            'created_at' => now()->subDay(),
        ]);

        $this->artisan('app:purge-unverified-audit-requests')->assertSuccessful();

        $this->assertNull(AuditRequest::find($stale->id));
        $this->assertNotNull(AuditRequest::find($recent->id));
    }
```

- [ ] **Step 2: Run it to confirm it fails**

```bash
docker compose exec laravel.test php artisan test --filter=PurgeUnverifiedAuditRequestsTest
```

Expected: FAIL — the stale row survives.

- [ ] **Step 3: Add the second sweep**

In `PurgeUnverifiedAuditRequests::handle()`, after the existing delete:

```php
        // A dashboard checkout intent is email-verified, so it can never match
        // the sweep above. Separate condition, same retention window.
        $abandoned = AuditRequest::query()
            ->where('status', AuditRequestStatus::AWAITING_PAYMENT->value)
            ->where('created_at', '<', now()->subDays((int) config('audit.unverified_purge_days')))
            ->delete();

        $this->info("Purged {$deleted} unverified and {$abandoned} abandoned audit request(s).");

        return self::SUCCESS;
```

Delete the old `$this->info(...)` line it replaces.

- [ ] **Step 4: Run the test to confirm it passes**

```bash
docker compose exec laravel.test php artisan test --filter=PurgeUnverifiedAuditRequestsTest
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Console/Commands/PurgeUnverifiedAuditRequests.php backend/tests/Feature/Console/PurgeUnverifiedAuditRequestsTest.php
git commit -m "chore(audit): purge abandoned checkout intents"
```

---

### Task 13: Full verification

**Files:** none — this task only runs gates and fixes what they surface.

- [ ] **Step 1: Format**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
```

Never `--dirty` — the bind mount excludes `.git`, so it would report `passed` without checking anything.

- [ ] **Step 2: Confirm formatting is clean**

```bash
docker compose exec laravel.test vendor/bin/pint --test
```

Expected: `PASS`.

- [ ] **Step 3: Static analysis**

```bash
docker compose exec laravel.test vendor/bin/phpstan analyse
```

Expected: `[OK] No errors`. Most likely complaints are missing array shapes on `quotas()` and `config()` returning `mixed` — fix with the `(array)` casts already used in Task 3's `tierPriceCents()`.

- [ ] **Step 4: Full suite**

```bash
docker compose exec laravel.test php artisan test --compact
```

Expected: all green. Runs ~150 seconds; do not launch a second test command while it runs.

- [ ] **Step 5: Confirm the marketing data is in sync**

```bash
docker compose exec laravel.test php artisan app:export-pricing --check
```

Expected: `Pricing data is current.`

- [ ] **Step 6: Verify the fix end to end by hand**

Migrate and seed, then visit the dashboard audit page, select Automated, and run an audit. Confirm the created request has `tier = automated` and `funding = allowance`, and that the remaining count on the page drops by one.

```bash
docker compose exec laravel.test php artisan migrate
docker compose exec laravel.test php artisan db:seed --class=AuditMonetizationSeeder
```

- [ ] **Step 7: Commit any fixes**

```bash
git add -A
git commit -m "chore(audit): satisfy formatting and static analysis gates"
```

---

## Notes for the implementer

- **`AuditRequestFactory`** may need a `funding` default. The column default covers most cases, but a test asserting on quota should set it explicitly rather than rely on the default — the point of the column is that funding is stated, not inferred.
- **The `expert` tier holds delivery.** An expert-tier run ends at `expert_review` and sends no email; that is Phase 13 behavior working as designed, not a bug in this change.
- **`AuditPipeline` is not touched by any task.** If you find yourself editing it, stop — the tier already drives pipeline composition through `TierProfileResolver`, and the profile config in `config/audit.php` is out of scope.
