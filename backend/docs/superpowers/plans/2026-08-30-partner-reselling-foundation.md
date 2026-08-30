# Partner Reselling Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the data model and services for partner identity/attribution and reseller catalog configuration (spec §4–§5) — Plan 1 of 3 for the Partner Reselling feature. No payments or storefront changes yet; this plan is complete when a Partner Plan tenant can configure custom prices/quotas for public plans and products, and a referred visitor's account gets permanently attributed to that partner.

**Architecture:** New standalone models (`PartnerReferralLink`, `PartnerPlanOffering`, `PartnerProductOffering`) plus new columns on `User`/`Product`/`OneTimeProduct`. Two new services (`PartnerCapabilityService`, `PartnerAttributionService`, `PartnerCatalogService`) follow the existing `AuditEntitlementService` pattern (memoized active-subscription lookup, `data_get()` off `Product.metadata`). Attribution reuses the existing session-based referral-code plumbing style but as an independent mechanism. Two new tenant-scoped Filament Dashboard resources let a partner configure their catalog, following the exact `OrderResource` template (`getEloquentQuery()` scope + `canAccess()` permission gate).

**Tech Stack:** Laravel 13, PHP 8.4, Filament 5.6, PHPUnit 11 (no Pest, no `RefreshDatabase` — see Global Constraints).

**Spec:** `backend/docs/superpowers/specs/2026-08-30-partner-reselling-cash-payments-design.md` (§4 and §5 only; §6–§11 are Plans 2–3).

## Global Constraints

- Run all commands inside the dev container: `docker compose exec laravel.test <command>`, from the repo root (not `backend/`, unless otherwise noted — check the container's working directory maps to `/var/www/html` containing `backend/`; if commands below fail with "file not found", run them from inside `backend/` on the host via `php artisan` directly instead, whichever matches this repo's actual dev setup).
- Test base class is `Tests\Feature\FeatureTest` (extends `Tests\TestCase`) — **not** `RefreshDatabase`/`DatabaseTransactions`. It runs `migrate:fresh` + seeds once per test class (`static bool $setUpHasRunOnce`), so rows persist across test methods in the same class — use unique factory data per test method, don't rely on an empty table.
- Every new migration must have a working `down()`.
- Every enum in this codebase is a PHP backed `enum` under `App\Constants` (e.g. `OrderStatus`, `PlanType`) — follow that, not a plain class of constants, for the new `PartnerAttributionSource`.
- Filament is v5.6: form/table field layouts use `->schema([...])` (not `->form([...])`, though both exist as aliases — this codebase uses `->schema()`), and a computed (non-relation) table column uses `TextColumn::make('x')->getStateUsing(fn ($record) => ...)`.
- Run `vendor/bin/pint --format agent` before each commit; `vendor/bin/pint --test` must stay clean.
- No platform commission logic, no multi-currency partner pricing, no seat/usage-based reseller support — out of scope per spec §2.

---

### Task 1: Partner attribution columns & relations

**Files:**
- Create: `database/migrations/2026_08_30_000001_add_partner_attribution_to_users_table.php`
- Create: `app/Constants/PartnerAttributionSource.php`
- Modify: `app/Models/User.php`
- Modify: `app/Models/Tenant.php`
- Test: `tests/Feature/Models/PartnerAttributionRelationsTest.php`

**Interfaces:**
- Produces: `User::partnerTenant(): BelongsTo`, `Tenant::partnerReferredUsers(): HasMany`, new nullable `User` columns `partner_tenant_id` (int), `partner_attributed_at` (Carbon), `partner_attribution_source` (string), and `App\Constants\PartnerAttributionSource` enum with cases `LINK`, `REGISTRATION`, `LOGIN`, `ORDER`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Models;

use App\Models\User;
use Tests\Feature\FeatureTest;

class PartnerAttributionRelationsTest extends FeatureTest
{
    public function test_user_can_be_attributed_to_a_partner_tenant(): void
    {
        $partnerTenant = $this->createTenant();
        $user = User::factory()->create([
            'partner_tenant_id' => $partnerTenant->id,
            'partner_attributed_at' => now(),
            'partner_attribution_source' => 'registration',
        ]);

        $this->assertTrue($user->partnerTenant->is($partnerTenant));
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $user->fresh()->partner_attributed_at);
    }

    public function test_tenant_lists_its_referred_users(): void
    {
        $partnerTenant = $this->createTenant();
        $referred = User::factory()->create(['partner_tenant_id' => $partnerTenant->id]);
        User::factory()->create();

        $this->assertTrue($partnerTenant->partnerReferredUsers->pluck('id')->contains($referred->id));
        $this->assertCount(1, $partnerTenant->partnerReferredUsers);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=PartnerAttributionRelationsTest`
Expected: FAIL — `partner_tenant_id` column does not exist / `partnerTenant()` method not found.

- [ ] **Step 3: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('partner_tenant_id')->nullable()->after('id')->constrained('tenants')->nullOnDelete();
            $table->timestamp('partner_attributed_at')->nullable()->after('partner_tenant_id');
            $table->string('partner_attribution_source')->nullable()->after('partner_attributed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_tenant_id');
            $table->dropColumn(['partner_attributed_at', 'partner_attribution_source']);
        });
    }
};
```

- [ ] **Step 4: Create the `PartnerAttributionSource` enum**

```php
<?php

namespace App\Constants;

enum PartnerAttributionSource: string
{
    case LINK = 'link';
    case REGISTRATION = 'registration';
    case LOGIN = 'login';
    case ORDER = 'order';
}
```

- [ ] **Step 5: Update `User` model**

Add `'partner_tenant_id', 'partner_attributed_at', 'partner_attribution_source'` to `$fillable`. Add `'partner_attributed_at' => 'datetime'` to `$casts`. Add `use Illuminate\Database\Eloquent\Relations\BelongsTo;` to the imports, and add this method (near the other relations, e.g. after `address()`):

```php
    public function partnerTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'partner_tenant_id');
    }
```

- [ ] **Step 6: Update `Tenant` model**

Add this method (near `orders()`):

```php
    public function partnerReferredUsers(): HasMany
    {
        return $this->hasMany(User::class, 'partner_tenant_id');
    }
```

(`HasMany` is already imported in this file.)

- [ ] **Step 7: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=PartnerAttributionRelationsTest`
Expected: PASS

- [ ] **Step 8: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
git add database/migrations/2026_08_30_000001_add_partner_attribution_to_users_table.php \
        app/Constants/PartnerAttributionSource.php app/Models/User.php app/Models/Tenant.php \
        tests/Feature/Models/PartnerAttributionRelationsTest.php
git commit -m "feat(partner): add partner attribution columns and relations"
```

---

### Task 2: `PartnerReferralLink` model

**Files:**
- Create: `database/migrations/2026_08_30_000002_create_partner_referral_links_table.php`
- Create: `app/Models/PartnerReferralLink.php`
- Create: `database/factories/PartnerReferralLinkFactory.php`
- Test: `tests/Feature/Models/PartnerReferralLinkTest.php`

**Interfaces:**
- Consumes: `Tenant` (Task 1's file, unchanged fields).
- Produces: `PartnerReferralLink` model with fillable `tenant_id`, `code`, `is_active` (bool, default `true`); `belongsTo(Tenant::class)`; unique index on `code`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Models;

use App\Models\PartnerReferralLink;
use Illuminate\Database\QueryException;
use Tests\Feature\FeatureTest;

class PartnerReferralLinkTest extends FeatureTest
{
    public function test_it_belongs_to_a_tenant(): void
    {
        $tenant = $this->createTenant();
        $link = PartnerReferralLink::factory()->create(['tenant_id' => $tenant->id, 'code' => 'ABC123']);

        $this->assertTrue($link->tenant->is($tenant));
        $this->assertTrue($link->is_active);
    }

    public function test_code_must_be_unique(): void
    {
        $tenantA = $this->createTenant();
        $tenantB = $this->createTenant();
        PartnerReferralLink::factory()->create(['tenant_id' => $tenantA->id, 'code' => 'DUPLICATE']);

        $this->expectException(QueryException::class);
        PartnerReferralLink::factory()->create(['tenant_id' => $tenantB->id, 'code' => 'DUPLICATE']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=PartnerReferralLinkTest`
Expected: FAIL — class/table does not exist.

- [ ] **Step 3: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_referral_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_referral_links');
    }
};
```

- [ ] **Step 4: Create the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerReferralLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'code',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
```

- [ ] **Step 5: Create the factory**

```php
<?php

namespace Database\Factories;

use App\Models\PartnerReferralLink;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PartnerReferralLink>
 */
class PartnerReferralLinkFactory extends Factory
{
    protected $model = PartnerReferralLink::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'code' => strtoupper(fake()->bothify('PTR-########')),
            'is_active' => true,
        ];
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=PartnerReferralLinkTest`
Expected: PASS

- [ ] **Step 7: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
git add database/migrations/2026_08_30_000002_create_partner_referral_links_table.php \
        app/Models/PartnerReferralLink.php database/factories/PartnerReferralLinkFactory.php \
        tests/Feature/Models/PartnerReferralLinkTest.php
git commit -m "feat(partner): add PartnerReferralLink model"
```

---

### Task 3: Reseller quota allowlist columns on `Product` and `OneTimeProduct`

**Files:**
- Create: `database/migrations/2026_08_30_000003_add_reseller_quota_keys_to_products_table.php`
- Create: `database/migrations/2026_08_30_000004_add_reseller_quota_keys_to_one_time_products_table.php`
- Modify: `app/Models/Product.php`
- Modify: `app/Models/OneTimeProduct.php`
- Test: `tests/Feature/Models/ResellerQuotaKeysTest.php`

**Interfaces:**
- Produces: `Product.reseller_quota_keys` and `OneTimeProduct.reseller_quota_keys`, both nullable JSON columns cast to `array`, fillable on both models.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Models;

use App\Models\OneTimeProduct;
use App\Models\Product;
use Tests\Feature\FeatureTest;

class ResellerQuotaKeysTest extends FeatureTest
{
    public function test_product_stores_reseller_quota_keys_as_array(): void
    {
        $product = Product::factory()->create(['reseller_quota_keys' => ['audit_diagnostic_credits']]);

        $this->assertSame(['audit_diagnostic_credits'], $product->fresh()->reseller_quota_keys);
    }

    public function test_one_time_product_stores_reseller_quota_keys_as_array(): void
    {
        $product = OneTimeProduct::factory()->create(['reseller_quota_keys' => ['bonus_credits']]);

        $this->assertSame(['bonus_credits'], $product->fresh()->reseller_quota_keys);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=ResellerQuotaKeysTest`
Expected: FAIL — `reseller_quota_keys` column does not exist / not mass-assignable.

- [ ] **Step 3: Create both migrations**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('reseller_quota_keys')->nullable()->after('metadata');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('reseller_quota_keys');
        });
    }
};
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('one_time_products', function (Blueprint $table) {
            $table->json('reseller_quota_keys')->nullable()->after('metadata');
        });
    }

    public function down(): void
    {
        Schema::table('one_time_products', function (Blueprint $table) {
            $table->dropColumn('reseller_quota_keys');
        });
    }
};
```

- [ ] **Step 4: Update both models**

In `app/Models/Product.php`, add `'reseller_quota_keys'` to `$fillable` and `'reseller_quota_keys' => 'array'` to `$casts`.

In `app/Models/OneTimeProduct.php`, add `'reseller_quota_keys'` to `$fillable` and `'reseller_quota_keys' => 'array'` to `$casts`.

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=ResellerQuotaKeysTest`
Expected: PASS

- [ ] **Step 6: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
git add database/migrations/2026_08_30_000003_add_reseller_quota_keys_to_products_table.php \
        database/migrations/2026_08_30_000004_add_reseller_quota_keys_to_one_time_products_table.php \
        app/Models/Product.php app/Models/OneTimeProduct.php \
        tests/Feature/Models/ResellerQuotaKeysTest.php
git commit -m "feat(partner): add reseller_quota_keys allowlist columns"
```

---

### Task 4: `PartnerCapabilityService::tenantIsActivePartner()`

**Files:**
- Create: `app/Services/PartnerCapabilityService.php`
- Test: `tests/Feature/Services/PartnerCapabilityServiceTest.php`

**Interfaces:**
- Consumes: `SubscriptionService::findActiveTenantSubscriptions(?Tenant $tenant): Collection` (existing, `app/Services/SubscriptionService.php`).
- Produces: `PartnerCapabilityService::tenantIsActivePartner(Tenant $tenant): bool`, and `PartnerCapabilityService::RESELLER_METADATA_KEY = 'enables_reseller_program'` (public const, used again in later tasks/plans).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Services;

use App\Constants\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Services\PartnerCapabilityService;
use Tests\Feature\FeatureTest;

class PartnerCapabilityServiceTest extends FeatureTest
{
    public function test_tenant_with_active_reseller_plan_is_an_active_partner(): void
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

        $this->assertTrue(app(PartnerCapabilityService::class)->tenantIsActivePartner($tenant));
    }

    public function test_tenant_with_a_non_reseller_plan_is_not_a_partner(): void
    {
        $tenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => []]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);

        $this->assertFalse(app(PartnerCapabilityService::class)->tenantIsActivePartner($tenant));
    }

    public function test_tenant_with_no_active_subscription_is_not_a_partner(): void
    {
        $tenant = $this->createTenant();

        $this->assertFalse(app(PartnerCapabilityService::class)->tenantIsActivePartner($tenant));
    }

    public function test_tenant_with_an_expired_reseller_subscription_is_not_a_partner(): void
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

        $this->assertFalse(app(PartnerCapabilityService::class)->tenantIsActivePartner($tenant));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=PartnerCapabilityServiceTest`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Write the service**

```php
<?php

namespace App\Services;

use App\Constants\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Collection;

class PartnerCapabilityService
{
    public const RESELLER_METADATA_KEY = 'enables_reseller_program';

    /**
     * Per-request memo of active subscriptions, keyed by tenant id — same
     * rationale as AuditEntitlementService: avoids fanning out into repeat
     * queries when Filament renders the tenant nav multiple times.
     *
     * @var array<int, Collection<int, Subscription>>
     */
    private array $activeSubscriptions = [];

    public function __construct(
        private SubscriptionService $subscriptionService,
    ) {}

    public function tenantIsActivePartner(Tenant $tenant): bool
    {
        return $this->activeSubscriptionsFor($tenant)
            ->contains(function (Subscription $subscription): bool {
                $product = $subscription->plan?->product;

                return $product !== null && (bool) data_get($product->metadata, self::RESELLER_METADATA_KEY, false);
            });
    }

    /**
     * @return Collection<int, Subscription>
     */
    private function activeSubscriptionsFor(Tenant $tenant): Collection
    {
        return $this->activeSubscriptions[$tenant->id] ??= $this->subscriptionService->findActiveTenantSubscriptions($tenant);
    }
}
```

Note: `findActiveTenantSubscriptions()` already filters `status = active` and `ends_at > now()`, matching `SubscriptionStatus::ACTIVE`.

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=PartnerCapabilityServiceTest`
Expected: PASS

- [ ] **Step 5: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
git add app/Services/PartnerCapabilityService.php tests/Feature/Services/PartnerCapabilityServiceTest.php
git commit -m "feat(partner): add PartnerCapabilityService"
```

---

### Task 5: `PartnerAttributionService` — pending-code session plumbing

**Files:**
- Create: `app/Constants/PartnerConstants.php`
- Modify: `app/Constants/SessionConstants.php`
- Create: `app/Services/PartnerAttributionService.php`
- Test: `tests/Feature/Services/PartnerAttributionServiceTest.php`

**Interfaces:**
- Consumes: `PartnerReferralLink` (Task 2), `PartnerCapabilityService::tenantIsActivePartner()` (Task 4).
- Produces: `PartnerAttributionService::rememberPendingCode(string $code): void`, `::pendingCode(): ?string`, `::clearPendingCode(): void`, `::resolveTenantForCode(string $code): ?Tenant`. (`::attribute()` and `::pendingCodeConflictsWithExisting()` are added in Task 6.)

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Services;

use App\Constants\PartnerAttributionSource;
use App\Models\PartnerReferralLink;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Services\PartnerAttributionService;
use Tests\Feature\FeatureTest;

class PartnerAttributionServiceTest extends FeatureTest
{
    public function test_pending_code_round_trips_through_session(): void
    {
        $service = app(PartnerAttributionService::class);

        $this->assertNull($service->pendingCode());

        $service->rememberPendingCode('ABC123');
        $this->assertSame('ABC123', $service->pendingCode());

        $service->clearPendingCode();
        $this->assertNull($service->pendingCode());
    }

    public function test_resolve_tenant_for_code_finds_the_linked_tenant(): void
    {
        $tenant = $this->createTenant();
        PartnerReferralLink::factory()->create(['tenant_id' => $tenant->id, 'code' => 'FINDME']);

        $resolved = app(PartnerAttributionService::class)->resolveTenantForCode('FINDME');

        $this->assertTrue($resolved->is($tenant));
    }

    public function test_resolve_tenant_for_code_returns_null_for_inactive_link(): void
    {
        $tenant = $this->createTenant();
        PartnerReferralLink::factory()->create(['tenant_id' => $tenant->id, 'code' => 'INACTIVE1', 'is_active' => false]);

        $this->assertNull(app(PartnerAttributionService::class)->resolveTenantForCode('INACTIVE1'));
    }

    public function test_resolve_tenant_for_code_returns_null_for_unknown_code(): void
    {
        $this->assertNull(app(PartnerAttributionService::class)->resolveTenantForCode('NOPE'));
    }
}
```

(`PartnerAttributionSource`, `Plan`, `Product`, `Subscription` imports are unused by this task's tests but will be used by Task 6's additions to the same test file — leave them out for now and add them in Task 6 instead, to keep this task's diff honest. Remove that import line from the snippet above.)

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=PartnerAttributionServiceTest`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Add the `PartnerConstants` class**

```php
<?php

namespace App\Constants;

class PartnerConstants
{
    public const HTTP_PARAM_PARTNER_CODE = 'partnerCode';
}
```

- [ ] **Step 4: Add the session key**

In `app/Constants/SessionConstants.php`, add alongside `COUPON_CODE`:

```php
    public const PARTNER_REFERRAL_CODE = 'partnerReferralCode';
```

- [ ] **Step 5: Write the service**

```php
<?php

namespace App\Services;

use App\Constants\SessionConstants;
use App\Models\PartnerReferralLink;
use App\Models\Tenant;

class PartnerAttributionService
{
    public function rememberPendingCode(string $code): void
    {
        session([SessionConstants::PARTNER_REFERRAL_CODE => $code]);
    }

    public function pendingCode(): ?string
    {
        return session(SessionConstants::PARTNER_REFERRAL_CODE);
    }

    public function clearPendingCode(): void
    {
        session()->forget(SessionConstants::PARTNER_REFERRAL_CODE);
    }

    public function resolveTenantForCode(string $code): ?Tenant
    {
        return PartnerReferralLink::where('code', $code)
            ->where('is_active', true)
            ->first()
            ?->tenant;
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=PartnerAttributionServiceTest`
Expected: PASS

- [ ] **Step 7: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
git add app/Constants/PartnerConstants.php app/Constants/SessionConstants.php \
        app/Services/PartnerAttributionService.php tests/Feature/Services/PartnerAttributionServiceTest.php
git commit -m "feat(partner): add PartnerAttributionService session plumbing"
```

---

### Task 6: `PartnerAttributionService::attribute()` — permanent, conflict-aware attribution

**Files:**
- Modify: `app/Services/PartnerAttributionService.php`
- Modify: `tests/Feature/Services/PartnerAttributionServiceTest.php`

**Interfaces:**
- Consumes: `PartnerCapabilityService::tenantIsActivePartner()` (Task 4), `User.partner_tenant_id` (Task 1).
- Produces: `PartnerAttributionService::attribute(User $user, PartnerAttributionSource $source): void`, `::pendingCodeConflictsWithExisting(User $user): bool`.

- [ ] **Step 1: Add failing tests to the same test class**

Add these methods to `PartnerAttributionServiceTest` (add the needed imports: `App\Constants\PartnerAttributionSource`, `App\Models\Plan`, `App\Models\Product`, `App\Models\Subscription`, `App\Constants\SubscriptionStatus`, `App\Models\User`):

```php
    public function test_attribute_sets_partner_tenant_when_code_resolves_to_an_active_partner(): void
    {
        $partnerTenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        Subscription::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);
        PartnerReferralLink::factory()->create(['tenant_id' => $partnerTenant->id, 'code' => 'REGCODE1']);
        $user = User::factory()->create();

        $service = app(PartnerAttributionService::class);
        $service->rememberPendingCode('REGCODE1');
        $service->attribute($user, PartnerAttributionSource::REGISTRATION);

        $user->refresh();
        $this->assertTrue($user->partnerTenant->is($partnerTenant));
        $this->assertSame(PartnerAttributionSource::REGISTRATION->value, $user->partner_attribution_source);
        $this->assertNull($service->pendingCode());
    }

    public function test_attribute_does_not_overwrite_an_existing_attribution(): void
    {
        $originalPartner = $this->createTenant();
        $otherPartner = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        Subscription::factory()->create([
            'tenant_id' => $otherPartner->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);
        PartnerReferralLink::factory()->create(['tenant_id' => $otherPartner->id, 'code' => 'OTHERCODE']);
        $user = User::factory()->create(['partner_tenant_id' => $originalPartner->id]);

        $service = app(PartnerAttributionService::class);
        $service->rememberPendingCode('OTHERCODE');
        $service->attribute($user, PartnerAttributionSource::LOGIN);

        $this->assertSame($originalPartner->id, $user->fresh()->partner_tenant_id);
    }

    public function test_attribute_ignores_a_code_for_a_tenant_that_is_not_an_active_partner(): void
    {
        $tenant = $this->createTenant();
        PartnerReferralLink::factory()->create(['tenant_id' => $tenant->id, 'code' => 'LAPSEDCODE']);
        $user = User::factory()->create();

        $service = app(PartnerAttributionService::class);
        $service->rememberPendingCode('LAPSEDCODE');
        $service->attribute($user, PartnerAttributionSource::REGISTRATION);

        $this->assertNull($user->fresh()->partner_tenant_id);
    }

    public function test_pending_code_conflicts_with_existing_attribution(): void
    {
        $originalPartner = $this->createTenant();
        $otherPartner = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        Subscription::factory()->create([
            'tenant_id' => $otherPartner->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);
        PartnerReferralLink::factory()->create(['tenant_id' => $otherPartner->id, 'code' => 'CONFLICTCODE']);
        $user = User::factory()->create(['partner_tenant_id' => $originalPartner->id]);

        $service = app(PartnerAttributionService::class);
        $service->rememberPendingCode('CONFLICTCODE');

        $this->assertTrue($service->pendingCodeConflictsWithExisting($user));
    }

    public function test_pending_code_does_not_conflict_when_it_matches_existing_attribution(): void
    {
        $partnerTenant = $this->createTenant();
        PartnerReferralLink::factory()->create(['tenant_id' => $partnerTenant->id, 'code' => 'SAMECODE']);
        $user = User::factory()->create(['partner_tenant_id' => $partnerTenant->id]);

        $service = app(PartnerAttributionService::class);
        $service->rememberPendingCode('SAMECODE');

        $this->assertFalse($service->pendingCodeConflictsWithExisting($user));
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=PartnerAttributionServiceTest`
Expected: FAIL — `attribute()`/`pendingCodeConflictsWithExisting()` do not exist.

- [ ] **Step 3: Extend the service**

Add to `app/Services/PartnerAttributionService.php` (constructor now takes `PartnerCapabilityService`, add `use App\Constants\PartnerAttributionSource;`, `use App\Models\User;`, `use App\Services\PartnerCapabilityService;`):

```php
    public function __construct(
        private PartnerCapabilityService $partnerCapabilityService,
    ) {}

    public function attribute(User $user, PartnerAttributionSource $source): void
    {
        if ($user->partner_tenant_id !== null) {
            return;
        }

        $tenant = $this->resolvePendingActivePartnerTenant();

        if ($tenant === null) {
            return;
        }

        $user->update([
            'partner_tenant_id' => $tenant->id,
            'partner_attributed_at' => now(),
            'partner_attribution_source' => $source->value,
        ]);

        $this->clearPendingCode();
    }

    public function pendingCodeConflictsWithExisting(User $user): bool
    {
        if ($user->partner_tenant_id === null) {
            return false;
        }

        $code = $this->pendingCode();

        if ($code === null) {
            return false;
        }

        $tenant = $this->resolveTenantForCode($code);

        return $tenant !== null && $tenant->id !== $user->partner_tenant_id;
    }

    private function resolvePendingActivePartnerTenant(): ?Tenant
    {
        $code = $this->pendingCode();

        if ($code === null) {
            return null;
        }

        $tenant = $this->resolveTenantForCode($code);

        if ($tenant === null || ! $this->partnerCapabilityService->tenantIsActivePartner($tenant)) {
            return null;
        }

        return $tenant;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=PartnerAttributionServiceTest`
Expected: PASS

- [ ] **Step 5: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
git add app/Services/PartnerAttributionService.php tests/Feature/Services/PartnerAttributionServiceTest.php
git commit -m "feat(partner): make partner attribution permanent and conflict-aware"
```

---

### Task 7: `TrackPartnerReferralCode` middleware

**Files:**
- Create: `app/Http/Middleware/TrackPartnerReferralCode.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/Http/Middleware/TrackPartnerReferralCodeTest.php`

**Interfaces:**
- Consumes: `PartnerAttributionService::rememberPendingCode()` (Task 5), `App\Constants\PartnerConstants::HTTP_PARAM_PARTNER_CODE` (Task 5).
- Produces: registered `web` middleware that writes `SessionConstants::PARTNER_REFERRAL_CODE` whenever `?partnerCode=` is present on any request — **not** gated by the unrelated consumer-referral `app.referral.enabled` flag.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Http\Middleware;

use App\Constants\PartnerConstants;
use App\Constants\SessionConstants;
use Tests\Feature\FeatureTest;

class TrackPartnerReferralCodeTest extends FeatureTest
{
    public function test_partner_code_is_stored_in_session(): void
    {
        $this->withExceptionHandling();

        $response = $this->get('/login?'.PartnerConstants::HTTP_PARAM_PARTNER_CODE.'=TESTCODE123');

        $response->assertSessionHas(SessionConstants::PARTNER_REFERRAL_CODE, 'TESTCODE123');
    }

    public function test_partner_code_is_not_stored_when_absent(): void
    {
        $this->withExceptionHandling();

        $response = $this->get('/login');

        $response->assertSessionMissing(SessionConstants::PARTNER_REFERRAL_CODE);
    }

    public function test_partner_code_persists_across_requests(): void
    {
        $this->withExceptionHandling();

        $this->get('/login?'.PartnerConstants::HTTP_PARAM_PARTNER_CODE.'=PERSIST1');

        $response = $this->get('/register');
        $response->assertSessionHas(SessionConstants::PARTNER_REFERRAL_CODE, 'PERSIST1');
    }

    public function test_partner_code_is_tracked_regardless_of_the_consumer_referral_flag(): void
    {
        $this->withExceptionHandling();
        config(['app.referral.enabled' => false]);

        $response = $this->get('/login?'.PartnerConstants::HTTP_PARAM_PARTNER_CODE.'=STILLWORKS');

        $response->assertSessionHas(SessionConstants::PARTNER_REFERRAL_CODE, 'STILLWORKS');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=TrackPartnerReferralCodeTest`
Expected: FAIL — middleware not registered / class does not exist.

- [ ] **Step 3: Write the middleware**

```php
<?php

namespace App\Http\Middleware;

use App\Constants\PartnerConstants;
use App\Constants\SessionConstants;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackPartnerReferralCode
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->has(PartnerConstants::HTTP_PARAM_PARTNER_CODE)) {
            session([SessionConstants::PARTNER_REFERRAL_CODE => $request->get(PartnerConstants::HTTP_PARAM_PARTNER_CODE)]);
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Register it in `bootstrap/app.php`**

Add `use App\Http\Middleware\TrackPartnerReferralCode;` to the imports, and add `TrackPartnerReferralCode::class,` to the `appendToGroup('web', [...])` array, alongside `TrackReferralCode::class` and `TrackCouponCode::class`.

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=TrackPartnerReferralCodeTest`
Expected: PASS

- [ ] **Step 6: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
git add app/Http/Middleware/TrackPartnerReferralCode.php bootstrap/app.php \
        tests/Feature/Http/Middleware/TrackPartnerReferralCodeTest.php
git commit -m "feat(partner): track partner referral code from any request"
```

---

### Task 8: Wire attribution into registration

**Files:**
- Modify: `app/Services/UserService.php`
- Test: `tests/Feature/Services/UserServiceTest.php` (create if it doesn't already exist — check first with `find tests/Feature/Services -iname 'UserServiceTest.php'`; if it exists, add the test method to it instead of overwriting).

**Interfaces:**
- Consumes: `PartnerAttributionService::attribute()` (Task 6).
- Produces: no new public API — `UserService::createUser()` now also attempts partner attribution.

- [ ] **Step 1: Write the failing test**

If `tests/Feature/Services/UserServiceTest.php` does not exist, create it with this content. If it exists, add this method to the existing class and add the needed imports.

```php
<?php

namespace Tests\Feature\Services;

use App\Constants\PartnerAttributionSource;
use App\Constants\SessionConstants;
use App\Constants\SubscriptionStatus;
use App\Models\PartnerReferralLink;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Services\UserService;
use Tests\Feature\FeatureTest;

class UserServiceTest extends FeatureTest
{
    public function test_creating_a_user_attributes_a_pending_partner_code(): void
    {
        $partnerTenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        Subscription::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);
        PartnerReferralLink::factory()->create(['tenant_id' => $partnerTenant->id, 'code' => 'SIGNUPCODE']);
        session([SessionConstants::PARTNER_REFERRAL_CODE => 'SIGNUPCODE']);

        $user = app(UserService::class)->createUser([
            'name' => 'Jane Doe',
            'email' => 'jane-'.uniqid().'@example.com',
            'password' => 'password',
        ]);

        $this->assertTrue($user->partnerTenant->is($partnerTenant));
        $this->assertSame(PartnerAttributionSource::REGISTRATION->value, $user->partner_attribution_source);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=UserServiceTest`
Expected: FAIL — `partner_tenant_id` stays null.

- [ ] **Step 3: Wire it in `UserService::createUser()`**

Add `use App\Constants\PartnerAttributionSource;` and inject `PartnerAttributionService` in the constructor:

```php
    public function __construct(
        private ReferralService $referralService,
        private PartnerAttributionService $partnerAttributionService,
    ) {}
```

Add, right after the existing referral-tracking block (after `session()->forget(SessionConstants::REFERRAL_CODE);` and before `if ($dispatchRegisterEvent)`):

```php
        $this->partnerAttributionService->attribute($user, PartnerAttributionSource::REGISTRATION);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=UserServiceTest`
Expected: PASS

- [ ] **Step 5: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
git add app/Services/UserService.php tests/Feature/Services/UserServiceTest.php
git commit -m "feat(partner): attribute pending partner code at registration"
```

---

### Task 9: Wire attribution into login

**Files:**
- Create: `app/Listeners/User/AttributePartnerOnLogin.php`
- Test: `tests/Feature/Listeners/AttributePartnerOnLoginTest.php`

**Interfaces:**
- Consumes: `PartnerAttributionService::attribute()` (Task 6).
- Produces: a `Illuminate\Auth\Events\Login` listener, auto-discovered like `App\Listeners\User\ProcessReferralOnVerification`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Listeners;

use App\Constants\PartnerAttributionSource;
use App\Constants\SessionConstants;
use App\Constants\SubscriptionStatus;
use App\Models\PartnerReferralLink;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Tests\Feature\FeatureTest;

class AttributePartnerOnLoginTest extends FeatureTest
{
    public function test_login_attributes_a_pending_partner_code_for_an_unattributed_user(): void
    {
        $partnerTenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        Subscription::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);
        PartnerReferralLink::factory()->create(['tenant_id' => $partnerTenant->id, 'code' => 'LOGINCODE']);
        session([SessionConstants::PARTNER_REFERRAL_CODE => 'LOGINCODE']);

        $user = User::factory()->create();

        event(new Login('web', $user, false));

        $this->assertTrue($user->fresh()->partnerTenant->is($partnerTenant));
        $this->assertSame(PartnerAttributionSource::LOGIN->value, $user->fresh()->partner_attribution_source);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=AttributePartnerOnLoginTest`
Expected: FAIL — attribution does not happen (no listener yet).

- [ ] **Step 3: Write the listener**

Deliberately **not** `ShouldQueue`: it reads the pending partner code out of the current HTTP session, which a queued job would not have access to.

```php
<?php

namespace App\Listeners\User;

use App\Constants\PartnerAttributionSource;
use App\Services\PartnerAttributionService;
use Illuminate\Auth\Events\Login;

class AttributePartnerOnLogin
{
    public function __construct(
        private PartnerAttributionService $partnerAttributionService,
    ) {}

    public function handle(Login $event): void
    {
        $this->partnerAttributionService->attribute($event->user, PartnerAttributionSource::LOGIN);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=AttributePartnerOnLoginTest`
Expected: PASS. If Laravel's event auto-discovery is disabled in this app (check `bootstrap/app.php` / any provider overriding `shouldDiscoverEvents()`), register the listener explicitly instead: add `Event::listen(Login::class, AttributePartnerOnLogin::class);` in `app/Providers/AppServiceProvider.php::boot()`.

- [ ] **Step 5: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
git add app/Listeners/User/AttributePartnerOnLogin.php tests/Feature/Listeners/AttributePartnerOnLoginTest.php
git commit -m "feat(partner): attribute pending partner code at login"
```

---

### Task 10: `PartnerPlanOffering` model

**Files:**
- Create: `database/migrations/2026_08_30_000005_create_partner_plan_offerings_table.php`
- Create: `app/Models/PartnerPlanOffering.php`
- Create: `database/factories/PartnerPlanOfferingFactory.php`
- Test: `tests/Feature/Models/PartnerPlanOfferingTest.php`

**Interfaces:**
- Produces: `PartnerPlanOffering` with fillable `tenant_id`, `plan_id`, `price` (int), `quota_overrides` (array cast), `is_enabled` (bool cast, default `false`); `belongsTo(Tenant::class)`, `belongsTo(Plan::class)`; unique on (`tenant_id`, `plan_id`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Models;

use App\Models\Plan;
use App\Models\PartnerPlanOffering;
use Illuminate\Database\QueryException;
use Tests\Feature\FeatureTest;

class PartnerPlanOfferingTest extends FeatureTest
{
    public function test_it_belongs_to_a_tenant_and_a_plan(): void
    {
        $tenant = $this->createTenant();
        $plan = Plan::factory()->create();
        $offering = PartnerPlanOffering::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'price' => 5000,
            'quota_overrides' => ['audit_diagnostic_credits' => 20],
            'is_enabled' => true,
        ]);

        $this->assertTrue($offering->tenant->is($tenant));
        $this->assertTrue($offering->plan->is($plan));
        $this->assertSame(['audit_diagnostic_credits' => 20], $offering->fresh()->quota_overrides);
        $this->assertTrue($offering->is_enabled);
    }

    public function test_tenant_and_plan_combination_must_be_unique(): void
    {
        $tenant = $this->createTenant();
        $plan = Plan::factory()->create();
        PartnerPlanOffering::factory()->create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id]);

        $this->expectException(QueryException::class);
        PartnerPlanOffering::factory()->create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=PartnerPlanOfferingTest`
Expected: FAIL — class/table does not exist.

- [ ] **Step 3: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_plan_offerings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->integer('price');
            $table->json('quota_overrides')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();
            $table->unique(['tenant_id', 'plan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_plan_offerings');
    }
};
```

- [ ] **Step 4: Create the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerPlanOffering extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'plan_id',
        'price',
        'quota_overrides',
        'is_enabled',
    ];

    protected $casts = [
        'quota_overrides' => 'array',
        'is_enabled' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
```

- [ ] **Step 5: Create the factory**

```php
<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\PartnerPlanOffering;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PartnerPlanOffering>
 */
class PartnerPlanOfferingFactory extends Factory
{
    protected $model = PartnerPlanOffering::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'plan_id' => Plan::factory(),
            'price' => 1000,
            'quota_overrides' => [],
            'is_enabled' => false,
        ];
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=PartnerPlanOfferingTest`
Expected: PASS

- [ ] **Step 7: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
git add database/migrations/2026_08_30_000005_create_partner_plan_offerings_table.php \
        app/Models/PartnerPlanOffering.php database/factories/PartnerPlanOfferingFactory.php \
        tests/Feature/Models/PartnerPlanOfferingTest.php
git commit -m "feat(partner): add PartnerPlanOffering model"
```

---

### Task 11: `PartnerProductOffering` model

**Files:**
- Create: `database/migrations/2026_08_30_000006_create_partner_product_offerings_table.php`
- Create: `app/Models/PartnerProductOffering.php`
- Create: `database/factories/PartnerProductOfferingFactory.php`
- Test: `tests/Feature/Models/PartnerProductOfferingTest.php`

**Interfaces:**
- Produces: `PartnerProductOffering`, identical shape to `PartnerPlanOffering` (Task 10) but keyed on `one_time_product_id`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Models;

use App\Models\OneTimeProduct;
use App\Models\PartnerProductOffering;
use Illuminate\Database\QueryException;
use Tests\Feature\FeatureTest;

class PartnerProductOfferingTest extends FeatureTest
{
    public function test_it_belongs_to_a_tenant_and_a_one_time_product(): void
    {
        $tenant = $this->createTenant();
        $product = OneTimeProduct::factory()->create();
        $offering = PartnerProductOffering::factory()->create([
            'tenant_id' => $tenant->id,
            'one_time_product_id' => $product->id,
            'price' => 2500,
        ]);

        $this->assertTrue($offering->tenant->is($tenant));
        $this->assertTrue($offering->oneTimeProduct->is($product));
    }

    public function test_tenant_and_product_combination_must_be_unique(): void
    {
        $tenant = $this->createTenant();
        $product = OneTimeProduct::factory()->create();
        PartnerProductOffering::factory()->create(['tenant_id' => $tenant->id, 'one_time_product_id' => $product->id]);

        $this->expectException(QueryException::class);
        PartnerProductOffering::factory()->create(['tenant_id' => $tenant->id, 'one_time_product_id' => $product->id]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=PartnerProductOfferingTest`
Expected: FAIL — class/table does not exist.

- [ ] **Step 3: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_product_offerings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('one_time_product_id')->constrained()->cascadeOnDelete();
            $table->integer('price');
            $table->json('quota_overrides')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();
            $table->unique(['tenant_id', 'one_time_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_product_offerings');
    }
};
```

- [ ] **Step 4: Create the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerProductOffering extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'one_time_product_id',
        'price',
        'quota_overrides',
        'is_enabled',
    ];

    protected $casts = [
        'quota_overrides' => 'array',
        'is_enabled' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function oneTimeProduct(): BelongsTo
    {
        return $this->belongsTo(OneTimeProduct::class);
    }
}
```

- [ ] **Step 5: Create the factory**

```php
<?php

namespace Database\Factories;

use App\Models\OneTimeProduct;
use App\Models\PartnerProductOffering;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PartnerProductOffering>
 */
class PartnerProductOfferingFactory extends Factory
{
    protected $model = PartnerProductOffering::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'one_time_product_id' => OneTimeProduct::factory(),
            'price' => 1000,
            'quota_overrides' => [],
            'is_enabled' => false,
        ];
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=PartnerProductOfferingTest`
Expected: PASS

- [ ] **Step 7: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
git add database/migrations/2026_08_30_000006_create_partner_product_offerings_table.php \
        app/Models/PartnerProductOffering.php database/factories/PartnerProductOfferingFactory.php \
        tests/Feature/Models/PartnerProductOfferingTest.php
git commit -m "feat(partner): add PartnerProductOffering model"
```

---

### Task 12: `PartnerCatalogService` — plan offering validation & upsert

**Files:**
- Create: `app/Exceptions/PartnerOfferingValidationException.php`
- Create: `app/Services/PartnerCatalogService.php`
- Modify: `app/Constants/TenancyPermissionConstants.php`
- Test: `tests/Feature/Services/PartnerCatalogServiceTest.php`

**Interfaces:**
- Consumes: `CurrencyService::getCurrency(): Currency` (existing), `PartnerPlanOffering` (Task 10).
- Produces: `PartnerCatalogService::planBasePrice(Plan $plan): int`, `::setPlanOffering(Tenant $tenant, Plan $plan, int $price, array $quotaOverrides, bool $isEnabled): PartnerPlanOffering`, throwing `PartnerOfferingValidationException` on any floor violation. `TenancyPermissionConstants::PERMISSION_MANAGE_RESELLER_CATALOG = 'tenancy: manage reseller catalog'`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Services;

use App\Exceptions\PartnerOfferingValidationException;
use App\Models\Currency;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Services\PartnerCatalogService;
use Tests\Feature\FeatureTest;

class PartnerCatalogServiceTest extends FeatureTest
{
    private function planWithBasePrice(int $basePrice, array $metadata = []): Plan
    {
        $product = Product::factory()->create(['metadata' => $metadata]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        PlanPrice::factory()->create([
            'plan_id' => $plan->id,
            'currency_id' => Currency::where('code', 'USD')->first()->id,
            'price' => $basePrice,
        ]);

        return $plan->fresh();
    }

    public function test_plan_base_price_reads_the_default_currency_price(): void
    {
        $plan = $this->planWithBasePrice(4900);

        $this->assertSame(4900, app(PartnerCatalogService::class)->planBasePrice($plan));
    }

    public function test_set_plan_offering_succeeds_at_or_above_the_floor(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->planWithBasePrice(4900, ['reseller_quota_keys' => ['audit_diagnostic_credits'], 'audit_diagnostic_credits' => 10]);

        $offering = app(PartnerCatalogService::class)->setPlanOffering($tenant, $plan, 5900, ['audit_diagnostic_credits' => 15], true);

        $this->assertSame(5900, $offering->price);
        $this->assertSame(['audit_diagnostic_credits' => 15], $offering->quota_overrides);
        $this->assertTrue($offering->is_enabled);
    }

    public function test_set_plan_offering_rejects_a_price_below_the_base_price(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->planWithBasePrice(4900);

        $this->expectException(PartnerOfferingValidationException::class);
        app(PartnerCatalogService::class)->setPlanOffering($tenant, $plan, 100, [], true);
    }

    public function test_set_plan_offering_rejects_a_quota_key_not_on_the_allowlist(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->planWithBasePrice(4900, ['reseller_quota_keys' => []]);

        $this->expectException(PartnerOfferingValidationException::class);
        app(PartnerCatalogService::class)->setPlanOffering($tenant, $plan, 4900, ['audit_diagnostic_credits' => 100], true);
    }

    public function test_set_plan_offering_rejects_a_quota_value_below_the_base_value(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->planWithBasePrice(4900, ['reseller_quota_keys' => ['audit_diagnostic_credits'], 'audit_diagnostic_credits' => 10]);

        $this->expectException(PartnerOfferingValidationException::class);
        app(PartnerCatalogService::class)->setPlanOffering($tenant, $plan, 4900, ['audit_diagnostic_credits' => 5], true);
    }

    public function test_set_plan_offering_updates_an_existing_offering_instead_of_duplicating(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->planWithBasePrice(4900);
        $service = app(PartnerCatalogService::class);

        $first = $service->setPlanOffering($tenant, $plan, 4900, [], false);
        $second = $service->setPlanOffering($tenant, $plan, 5900, [], true);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(5900, $second->fresh()->price);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=PartnerCatalogServiceTest`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Add the permission constant**

In `app/Constants/TenancyPermissionConstants.php`, add alongside `PERMISSION_VIEW_TRANSACTIONS`:

```php
    public const PERMISSION_MANAGE_RESELLER_CATALOG = 'tenancy: manage reseller catalog';
```

- [ ] **Step 4: Create the exception**

```php
<?php

namespace App\Exceptions;

use Exception;

class PartnerOfferingValidationException extends Exception
{
}
```

- [ ] **Step 5: Write the service**

```php
<?php

namespace App\Services;

use App\Exceptions\PartnerOfferingValidationException;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\PartnerPlanOffering;
use App\Models\Tenant;

class PartnerCatalogService
{
    public function __construct(
        private CurrencyService $currencyService,
    ) {}

    public function planBasePrice(Plan $plan): int
    {
        $price = PlanPrice::where('plan_id', $plan->id)
            ->where('currency_id', $this->currencyService->getCurrency()->id)
            ->value('price');

        if ($price === null) {
            throw new PartnerOfferingValidationException("Plan [{$plan->slug}] has no price in the default currency.");
        }

        return (int) $price;
    }

    public function setPlanOffering(Tenant $tenant, Plan $plan, int $price, array $quotaOverrides, bool $isEnabled): PartnerPlanOffering
    {
        $this->assertPriceAtOrAboveBase($price, $this->planBasePrice($plan));
        $this->assertQuotasValid(
            $quotaOverrides,
            (array) ($plan->product->reseller_quota_keys ?? []),
            (array) ($plan->product->metadata ?? []),
        );

        return PartnerPlanOffering::updateOrCreate(
            ['tenant_id' => $tenant->id, 'plan_id' => $plan->id],
            ['price' => $price, 'quota_overrides' => $quotaOverrides, 'is_enabled' => $isEnabled],
        );
    }

    private function assertPriceAtOrAboveBase(int $price, int $basePrice): void
    {
        if ($price < $basePrice) {
            throw new PartnerOfferingValidationException("Price must be at least the base price of {$basePrice}.");
        }
    }

    private function assertQuotasValid(array $quotaOverrides, array $allowedKeys, array $baseMetadata): void
    {
        foreach ($quotaOverrides as $key => $value) {
            if (! in_array($key, $allowedKeys, true)) {
                throw new PartnerOfferingValidationException("Quota key [{$key}] is not configurable for this item.");
            }

            $baseValue = (int) data_get($baseMetadata, $key, 0);

            if ((int) $value < $baseValue) {
                throw new PartnerOfferingValidationException("Quota [{$key}] must be at least {$baseValue}.");
            }
        }
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=PartnerCatalogServiceTest`
Expected: PASS

- [ ] **Step 7: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
git add app/Exceptions/PartnerOfferingValidationException.php app/Services/PartnerCatalogService.php \
        app/Constants/TenancyPermissionConstants.php tests/Feature/Services/PartnerCatalogServiceTest.php
git commit -m "feat(partner): validate and upsert partner plan offerings"
```

---

### Task 13: `PartnerCatalogService` — product offering validation & upsert

**Files:**
- Modify: `app/Services/PartnerCatalogService.php`
- Modify: `tests/Feature/Services/PartnerCatalogServiceTest.php`

**Interfaces:**
- Consumes: `PartnerProductOffering` (Task 11), the same private validators added in Task 12.
- Produces: `PartnerCatalogService::productBasePrice(OneTimeProduct $product): int`, `::setProductOffering(Tenant $tenant, OneTimeProduct $product, int $price, array $quotaOverrides, bool $isEnabled): PartnerProductOffering`.

- [ ] **Step 1: Add failing tests to the same test class**

Add (with `use App\Models\OneTimeProduct;`, `use App\Models\OneTimeProductPrice;`, `use App\Models\PartnerProductOffering;`):

```php
    private function oneTimeProductWithBasePrice(int $basePrice, array $metadata = []): OneTimeProduct
    {
        $product = OneTimeProduct::factory()->create(['metadata' => $metadata]);
        OneTimeProductPrice::factory()->create([
            'one_time_product_id' => $product->id,
            'currency_id' => Currency::where('code', 'USD')->first()->id,
            'price' => $basePrice,
        ]);

        return $product->fresh();
    }

    public function test_product_base_price_reads_the_default_currency_price(): void
    {
        $product = $this->oneTimeProductWithBasePrice(1500);

        $this->assertSame(1500, app(PartnerCatalogService::class)->productBasePrice($product));
    }

    public function test_set_product_offering_succeeds_at_or_above_the_floor(): void
    {
        $tenant = $this->createTenant();
        $product = $this->oneTimeProductWithBasePrice(1500);

        $offering = app(PartnerCatalogService::class)->setProductOffering($tenant, $product, 2000, [], true);

        $this->assertInstanceOf(PartnerProductOffering::class, $offering);
        $this->assertSame(2000, $offering->price);
    }

    public function test_set_product_offering_rejects_a_price_below_the_base_price(): void
    {
        $tenant = $this->createTenant();
        $product = $this->oneTimeProductWithBasePrice(1500);

        $this->expectException(PartnerOfferingValidationException::class);
        app(PartnerCatalogService::class)->setProductOffering($tenant, $product, 500, [], true);
    }

    public function test_set_product_offering_updates_an_existing_offering_instead_of_duplicating(): void
    {
        $tenant = $this->createTenant();
        $product = $this->oneTimeProductWithBasePrice(1500);
        $service = app(PartnerCatalogService::class);

        $first = $service->setProductOffering($tenant, $product, 1500, [], false);
        $second = $service->setProductOffering($tenant, $product, 2500, [], true);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(2500, $second->fresh()->price);
    }
```

Check `OneTimeProductPriceFactory` exists — if it does not, create `database/factories/OneTimeProductPriceFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Currency;
use App\Models\OneTimeProduct;
use App\Models\OneTimeProductPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OneTimeProductPrice>
 */
class OneTimeProductPriceFactory extends Factory
{
    protected $model = OneTimeProductPrice::class;

    public function definition(): array
    {
        return [
            'one_time_product_id' => OneTimeProduct::factory(),
            'currency_id' => Currency::where('code', 'USD')->first()->id,
            'price' => 1000,
        ];
    }
}
```

(Same for `PlanPriceFactory` used in Task 12 if it doesn't already exist — mirror the shape above with `plan_id` instead of `one_time_product_id`.)

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=PartnerCatalogServiceTest`
Expected: FAIL — `productBasePrice()`/`setProductOffering()` do not exist.

- [ ] **Step 3: Extend the service**

Add to `app/Services/PartnerCatalogService.php` (add `use App\Models\OneTimeProduct;`, `use App\Models\OneTimeProductPrice;`, `use App\Models\PartnerProductOffering;`):

```php
    public function productBasePrice(OneTimeProduct $product): int
    {
        $price = OneTimeProductPrice::where('one_time_product_id', $product->id)
            ->where('currency_id', $this->currencyService->getCurrency()->id)
            ->value('price');

        if ($price === null) {
            throw new PartnerOfferingValidationException("Product [{$product->slug}] has no price in the default currency.");
        }

        return (int) $price;
    }

    public function setProductOffering(Tenant $tenant, OneTimeProduct $product, int $price, array $quotaOverrides, bool $isEnabled): PartnerProductOffering
    {
        $this->assertPriceAtOrAboveBase($price, $this->productBasePrice($product));
        $this->assertQuotasValid(
            $quotaOverrides,
            (array) ($product->reseller_quota_keys ?? []),
            (array) ($product->metadata ?? []),
        );

        return PartnerProductOffering::updateOrCreate(
            ['tenant_id' => $tenant->id, 'one_time_product_id' => $product->id],
            ['price' => $price, 'quota_overrides' => $quotaOverrides, 'is_enabled' => $isEnabled],
        );
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=PartnerCatalogServiceTest`
Expected: PASS

- [ ] **Step 5: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
git add app/Services/PartnerCatalogService.php tests/Feature/Services/PartnerCatalogServiceTest.php \
        database/factories/OneTimeProductPriceFactory.php database/factories/PlanPriceFactory.php
git commit -m "feat(partner): validate and upsert partner product offerings"
```

---

### Task 14: `PartnerCatalogService` — below-minimum detection

**Files:**
- Modify: `app/Services/PartnerCatalogService.php`
- Modify: `tests/Feature/Services/PartnerCatalogServiceTest.php`

**Interfaces:**
- Produces: `PartnerCatalogService::isPlanOfferingBelowMinimum(PartnerPlanOffering $offering): bool`, `::isProductOfferingBelowMinimum(PartnerProductOffering $offering): bool`.

- [ ] **Step 1: Add failing tests**

```php
    public function test_plan_offering_is_flagged_below_minimum_after_a_base_price_increase(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->planWithBasePrice(4900);
        $offering = app(PartnerCatalogService::class)->setPlanOffering($tenant, $plan, 4900, [], true);

        PlanPrice::where('plan_id', $plan->id)->update(['price' => 6900]);

        $this->assertTrue(app(PartnerCatalogService::class)->isPlanOfferingBelowMinimum($offering->fresh()));
    }

    public function test_plan_offering_is_not_flagged_when_still_at_or_above_minimum(): void
    {
        $tenant = $this->createTenant();
        $plan = $this->planWithBasePrice(4900);
        $offering = app(PartnerCatalogService::class)->setPlanOffering($tenant, $plan, 9900, [], true);

        $this->assertFalse(app(PartnerCatalogService::class)->isPlanOfferingBelowMinimum($offering->fresh()));
    }

    public function test_product_offering_is_flagged_below_minimum_after_a_base_price_increase(): void
    {
        $tenant = $this->createTenant();
        $product = $this->oneTimeProductWithBasePrice(1500);
        $offering = app(PartnerCatalogService::class)->setProductOffering($tenant, $product, 1500, [], true);

        OneTimeProductPrice::where('one_time_product_id', $product->id)->update(['price' => 3000]);

        $this->assertTrue(app(PartnerCatalogService::class)->isProductOfferingBelowMinimum($offering->fresh()));
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=PartnerCatalogServiceTest`
Expected: FAIL — methods do not exist.

- [ ] **Step 3: Add the methods**

```php
    public function isPlanOfferingBelowMinimum(PartnerPlanOffering $offering): bool
    {
        $plan = $offering->plan;
        $product = $plan->product;

        return $offering->price < $this->planBasePrice($plan)
            || $this->hasQuotaBelowFloor((array) $offering->quota_overrides, (array) ($product->metadata ?? []));
    }

    public function isProductOfferingBelowMinimum(PartnerProductOffering $offering): bool
    {
        $product = $offering->oneTimeProduct;

        return $offering->price < $this->productBasePrice($product)
            || $this->hasQuotaBelowFloor((array) $offering->quota_overrides, (array) ($product->metadata ?? []));
    }

    private function hasQuotaBelowFloor(array $quotaOverrides, array $baseMetadata): bool
    {
        foreach ($quotaOverrides as $key => $value) {
            if ((int) $value < (int) data_get($baseMetadata, $key, 0)) {
                return true;
            }
        }

        return false;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=PartnerCatalogServiceTest`
Expected: PASS

- [ ] **Step 5: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
git add app/Services/PartnerCatalogService.php tests/Feature/Services/PartnerCatalogServiceTest.php
git commit -m "feat(partner): detect offerings that fell below the admin minimum"
```

---

### Task 15: `PartnerPlanCatalogResource` — Filament Dashboard resource

**Files:**
- Create: `app/Filament/Dashboard/Resources/PartnerPlanCatalog/PartnerPlanCatalogResource.php`
- Create: `app/Filament/Dashboard/Resources/PartnerPlanCatalog/Pages/ListPartnerPlanCatalog.php`
- Test: `tests/Feature/Filament/Dashboard/PartnerPlanCatalogResourceTest.php`

**Interfaces:**
- Consumes: `PartnerCapabilityService::tenantIsActivePartner()` (Task 4), `TenantPermissionService::tenantUserHasPermissionTo()` (existing), `PartnerCatalogService::setPlanOffering()` (Task 12), `TenancyPermissionConstants::PERMISSION_MANAGE_RESELLER_CATALOG` (Task 12).
- Produces: a tenant-scoped Filament Dashboard resource listing public plans, with a "Configure" row action that upserts the tenant's `PartnerPlanOffering`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\SubscriptionStatus;
use App\Constants\TenancyPermissionConstants;
use App\Filament\Dashboard\Resources\PartnerPlanCatalog\PartnerPlanCatalogResource;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Services\PartnerCatalogService;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class PartnerPlanCatalogResourceTest extends FeatureTest
{
    private function activePartnerTenant()
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

    public function test_it_denies_access_to_a_non_partner_tenant(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, [TenancyPermissionConstants::PERMISSION_MANAGE_RESELLER_CATALOG]);
        $this->actingAs($user);

        \Filament\Facades\Filament::setTenant($tenant);

        $this->assertFalse(PartnerPlanCatalogResource::canAccess());
    }

    public function test_it_allows_access_to_an_active_partner_tenant_with_permission(): void
    {
        $tenant = $this->activePartnerTenant();
        $user = $this->createUser($tenant, [TenancyPermissionConstants::PERMISSION_MANAGE_RESELLER_CATALOG]);
        $this->actingAs($user);

        \Filament\Facades\Filament::setTenant($tenant);

        $this->assertTrue(PartnerPlanCatalogResource::canAccess());
    }

    public function test_it_denies_access_without_the_permission_even_for_an_active_partner(): void
    {
        $tenant = $this->activePartnerTenant();
        $user = $this->createUser($tenant, []);
        $this->actingAs($user);

        \Filament\Facades\Filament::setTenant($tenant);

        $this->assertFalse(PartnerPlanCatalogResource::canAccess());
    }

    public function test_only_visible_plans_are_listed(): void
    {
        Plan::factory()->create(['is_visible' => true]);
        Plan::factory()->create(['is_visible' => false]);

        $query = PartnerPlanCatalogResource::getEloquentQuery();

        $this->assertTrue($query->get()->every(fn (Plan $plan) => $plan->is_visible));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=PartnerPlanCatalogResourceTest`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Write the resource**

```php
<?php

namespace App\Filament\Dashboard\Resources\PartnerPlanCatalog;

use App\Constants\TenancyPermissionConstants;
use App\Exceptions\PartnerOfferingValidationException;
use App\Filament\Dashboard\Resources\PartnerPlanCatalog\Pages\ListPartnerPlanCatalog;
use App\Models\Plan;
use App\Models\PartnerPlanOffering;
use App\Services\CurrencyService;
use App\Services\PartnerCapabilityService;
use App\Services\PartnerCatalogService;
use App\Services\TenantPermissionService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PartnerPlanCatalogResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('is_visible', true);
    }

    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        if ($tenant === null || ! app(PartnerCapabilityService::class)->tenantIsActivePartner($tenant)) {
            return false;
        }

        return app(TenantPermissionService::class)->tenantUserHasPermissionTo(
            $tenant,
            auth()->user(),
            TenancyPermissionConstants::PERMISSION_MANAGE_RESELLER_CATALOG,
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
                TextColumn::make('name')->label(__('Plan')),
                TextColumn::make('product.name')->label(__('Product')),
                TextColumn::make('partner_price')
                    ->label(__('Your Price'))
                    ->getStateUsing(function (Plan $record): string {
                        $offering = self::offeringFor($record);

                        return $offering === null
                            ? __('Not configured')
                            : money($offering->price, app(CurrencyService::class)->getCurrency()->code);
                    }),
                TextColumn::make('partner_enabled')
                    ->label(__('Enabled'))
                    ->getStateUsing(fn (Plan $record): string => self::offeringFor($record)?->is_enabled ? __('Yes') : __('No')),
            ])
            ->recordActions([
                Action::make('configure')
                    ->label(__('Configure'))
                    ->schema(function (Plan $record): array {
                        $offering = self::offeringFor($record);
                        $allowedKeys = (array) ($record->product->reseller_quota_keys ?? []);

                        return [
                            TextInput::make('price')
                                ->label(__('Your Price (in cents)'))
                                ->numeric()
                                ->default($offering->price ?? null)
                                ->required(),
                            ...array_map(
                                fn (string $key) => TextInput::make("quota_overrides.{$key}")
                                    ->label($key)
                                    ->numeric()
                                    ->default(data_get($offering->quota_overrides ?? [], $key)),
                                $allowedKeys,
                            ),
                            Toggle::make('is_enabled')
                                ->label(__('Resell this plan'))
                                ->default($offering->is_enabled ?? false),
                        ];
                    })
                    ->action(function (array $data, Plan $record, PartnerCatalogService $catalogService): void {
                        try {
                            $catalogService->setPlanOffering(
                                Filament::getTenant(),
                                $record,
                                (int) $data['price'],
                                (array) ($data['quota_overrides'] ?? []),
                                (bool) $data['is_enabled'],
                            );
                        } catch (PartnerOfferingValidationException $e) {
                            Notification::make()->danger()->title(__('Could not save offering'))->body($e->getMessage())->persistent()->send();

                            return;
                        }

                        Notification::make()->success()->title(__('Offering saved'))->send();
                    }),
            ]);
    }

    private static function offeringFor(Plan $plan): ?PartnerPlanOffering
    {
        return PartnerPlanOffering::where('tenant_id', Filament::getTenant()->id)
            ->where('plan_id', $plan->id)
            ->first();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPartnerPlanCatalog::route('/'),
        ];
    }
}
```

- [ ] **Step 4: Write the list page**

```php
<?php

namespace App\Filament\Dashboard\Resources\PartnerPlanCatalog\Pages;

use App\Filament\Dashboard\Resources\PartnerPlanCatalog\PartnerPlanCatalogResource;
use Filament\Resources\Pages\ListRecords;

class ListPartnerPlanCatalog extends ListRecords
{
    protected static string $resource = PartnerPlanCatalogResource::class;
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=PartnerPlanCatalogResourceTest`
Expected: PASS. If `Filament::setTenant()` isn't the right call in this Filament version, check `vendor/filament/filament/src/Facades/Filament.php` for the equivalent (e.g. it may require passing `isQuiet: true` or need `Filament::getCurrentPanel()` set up first via `Filament::setCurrentPanel(Filament::getPanel('dashboard'))` before `setTenant()` — add that call in the test's setup if `getTenant()` returns null unexpectedly).

- [ ] **Step 6: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
git add app/Filament/Dashboard/Resources/PartnerPlanCatalog tests/Feature/Filament/Dashboard/PartnerPlanCatalogResourceTest.php
git commit -m "feat(partner): add partner plan catalog dashboard resource"
```

---

### Task 16: `PartnerProductCatalogResource` — Filament Dashboard resource

**Files:**
- Create: `app/Filament/Dashboard/Resources/PartnerProductCatalog/PartnerProductCatalogResource.php`
- Create: `app/Filament/Dashboard/Resources/PartnerProductCatalog/Pages/ListPartnerProductCatalog.php`
- Test: `tests/Feature/Filament/Dashboard/PartnerProductCatalogResourceTest.php`

**Interfaces:**
- Mirrors Task 15 exactly, for `OneTimeProduct`/`PartnerProductOffering`/`PartnerCatalogService::setProductOffering()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\SubscriptionStatus;
use App\Constants\TenancyPermissionConstants;
use App\Filament\Dashboard\Resources\PartnerProductCatalog\PartnerProductCatalogResource;
use App\Models\OneTimeProduct;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use Tests\Feature\FeatureTest;

class PartnerProductCatalogResourceTest extends FeatureTest
{
    private function activePartnerTenant()
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

    public function test_it_allows_access_to_an_active_partner_tenant_with_permission(): void
    {
        $tenant = $this->activePartnerTenant();
        $user = $this->createUser($tenant, [TenancyPermissionConstants::PERMISSION_MANAGE_RESELLER_CATALOG]);
        $this->actingAs($user);

        \Filament\Facades\Filament::setTenant($tenant);

        $this->assertTrue(PartnerProductCatalogResource::canAccess());
    }

    public function test_it_denies_access_to_a_non_partner_tenant(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, [TenancyPermissionConstants::PERMISSION_MANAGE_RESELLER_CATALOG]);
        $this->actingAs($user);

        \Filament\Facades\Filament::setTenant($tenant);

        $this->assertFalse(PartnerProductCatalogResource::canAccess());
    }

    public function test_only_visible_products_are_listed(): void
    {
        OneTimeProduct::factory()->create(['is_visible' => true]);
        OneTimeProduct::factory()->create(['is_visible' => false]);

        $query = PartnerProductCatalogResource::getEloquentQuery();

        $this->assertTrue($query->get()->every(fn (OneTimeProduct $p) => $p->is_visible));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=PartnerProductCatalogResourceTest`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Write the resource**

```php
<?php

namespace App\Filament\Dashboard\Resources\PartnerProductCatalog;

use App\Constants\TenancyPermissionConstants;
use App\Exceptions\PartnerOfferingValidationException;
use App\Filament\Dashboard\Resources\PartnerProductCatalog\Pages\ListPartnerProductCatalog;
use App\Models\OneTimeProduct;
use App\Models\PartnerProductOffering;
use App\Services\CurrencyService;
use App\Services\PartnerCapabilityService;
use App\Services\PartnerCatalogService;
use App\Services\TenantPermissionService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PartnerProductCatalogResource extends Resource
{
    protected static ?string $model = OneTimeProduct::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('is_visible', true);
    }

    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        if ($tenant === null || ! app(PartnerCapabilityService::class)->tenantIsActivePartner($tenant)) {
            return false;
        }

        return app(TenantPermissionService::class)->tenantUserHasPermissionTo(
            $tenant,
            auth()->user(),
            TenancyPermissionConstants::PERMISSION_MANAGE_RESELLER_CATALOG,
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
                TextColumn::make('name')->label(__('Product')),
                TextColumn::make('partner_price')
                    ->label(__('Your Price'))
                    ->getStateUsing(function (OneTimeProduct $record): string {
                        $offering = self::offeringFor($record);

                        return $offering === null
                            ? __('Not configured')
                            : money($offering->price, app(CurrencyService::class)->getCurrency()->code);
                    }),
                TextColumn::make('partner_enabled')
                    ->label(__('Enabled'))
                    ->getStateUsing(fn (OneTimeProduct $record): string => self::offeringFor($record)?->is_enabled ? __('Yes') : __('No')),
            ])
            ->recordActions([
                Action::make('configure')
                    ->label(__('Configure'))
                    ->schema(function (OneTimeProduct $record): array {
                        $offering = self::offeringFor($record);
                        $allowedKeys = (array) ($record->reseller_quota_keys ?? []);

                        return [
                            TextInput::make('price')
                                ->label(__('Your Price (in cents)'))
                                ->numeric()
                                ->default($offering->price ?? null)
                                ->required(),
                            ...array_map(
                                fn (string $key) => TextInput::make("quota_overrides.{$key}")
                                    ->label($key)
                                    ->numeric()
                                    ->default(data_get($offering->quota_overrides ?? [], $key)),
                                $allowedKeys,
                            ),
                            Toggle::make('is_enabled')
                                ->label(__('Resell this product'))
                                ->default($offering->is_enabled ?? false),
                        ];
                    })
                    ->action(function (array $data, OneTimeProduct $record, PartnerCatalogService $catalogService): void {
                        try {
                            $catalogService->setProductOffering(
                                Filament::getTenant(),
                                $record,
                                (int) $data['price'],
                                (array) ($data['quota_overrides'] ?? []),
                                (bool) $data['is_enabled'],
                            );
                        } catch (PartnerOfferingValidationException $e) {
                            Notification::make()->danger()->title(__('Could not save offering'))->body($e->getMessage())->persistent()->send();

                            return;
                        }

                        Notification::make()->success()->title(__('Offering saved'))->send();
                    }),
            ]);
    }

    private static function offeringFor(OneTimeProduct $product): ?PartnerProductOffering
    {
        return PartnerProductOffering::where('tenant_id', Filament::getTenant()->id)
            ->where('one_time_product_id', $product->id)
            ->first();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPartnerProductCatalog::route('/'),
        ];
    }
}
```

- [ ] **Step 4: Write the list page**

```php
<?php

namespace App\Filament\Dashboard\Resources\PartnerProductCatalog\Pages;

use App\Filament\Dashboard\Resources\PartnerProductCatalog\PartnerProductCatalogResource;
use Filament\Resources\Pages\ListRecords;

class ListPartnerProductCatalog extends ListRecords
{
    protected static string $resource = PartnerProductCatalogResource::class;
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=PartnerProductCatalogResourceTest`
Expected: PASS

- [ ] **Step 6: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
git add app/Filament/Dashboard/Resources/PartnerProductCatalog tests/Feature/Filament/Dashboard/PartnerProductCatalogResourceTest.php
git commit -m "feat(partner): add partner product catalog dashboard resource"
```

---

### Task 17: Full regression pass

**Files:** none (verification only).

- [ ] **Step 1: Run the full backend test suite**

Run: `docker compose exec laravel.test php artisan test --compact`
Expected: all tests pass except any pre-existing, already-known flakes unrelated to this plan (e.g. `MetricServiceTest`/`SubscriptionCheckoutFormTest` test-isolation flakes noted in prior work on this codebase, if they reappear — confirm any failure is pre-existing by checking it fails identically on `main` before this branch, not introduced by this plan).

- [ ] **Step 2: Run static analysis and formatting gates**

```bash
docker compose exec laravel.test vendor/bin/phpstan analyse
docker compose exec laravel.test vendor/bin/pint --test
```

Expected: both clean.

- [ ] **Step 3: Commit if anything was fixed during this pass**

If Steps 1–2 required any fixes, stage and commit them with a message describing the regression fixed. If everything was already clean, no commit is needed for this task.
