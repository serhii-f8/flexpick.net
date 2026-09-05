# Partner Reselling — Cash Payments & Order Approval Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make a paid Offline ("cash") purchase land as a `PENDING` order carrying an immutable price/quota snapshot, and give the partner tenant (and admins) an audited, idempotent, ownership-enforced Approve/Reject queue that activates or kills it — Plan 2 of 3 for the Partner Reselling feature.

**Architecture:** Everything hangs off the `Order` row. A cash *product* purchase is already a `PENDING` local order today; a cash *subscription* purchase becomes a `PENDING` order too (`type = purchase`, `subscription_id` set), so one approval queue and one audit table (`order_approvals`) cover purchases, subscription starts, and renewals. New snapshot columns on `orders`/`subscriptions` freeze base price and quota values at creation, and `AuditEntitlementService` reads the snapshot in preference to live product metadata. Three new services live in `app/Services/CashPayments/`: `PurchaseSnapshotService` (what to freeze), `CashSubscriptionService` (pending cash subscriptions and their orders), `OrderApprovalService` (the guarded state machine). Two scheduled commands issue renewal orders and expire stale ones.

**Tech Stack:** Laravel 13, PHP 8.4, Filament 5.6, PHPUnit 11 (no Pest, no `RefreshDatabase` — see Global Constraints).

**Spec:** `backend/docs/superpowers/specs/2026-08-30-partner-reselling-cash-payments-design.md` (§6, §7 and §9; §4–§5 shipped in Plan 1, §8/§10/§11 are Plan 3).

**Plan 1 (prerequisite, already merged):** `backend/docs/superpowers/plans/2026-08-30-partner-reselling-foundation.md` — `PartnerReferralLink`, `PartnerPlanOffering`, `PartnerProductOffering`, `PartnerCapabilityService`, `PartnerAttributionService`, `PartnerCatalogService`, `users.partner_tenant_id`, `products.reseller_quota_keys`, and the two partner catalog dashboard resources. This plan consumes all of them and assumes they are on the branch.

## Global Constraints

- Run all commands inside the dev container: `docker compose exec laravel.test <command>`, from the repo root. (If a command reports "file not found", the container working directory does not map the way you assumed — run `php artisan …` from `backend/` on the host instead.)
- Test base class is `Tests\Feature\FeatureTest` (extends `Tests\TestCase`) — **not** `RefreshDatabase`/`DatabaseTransactions`. It runs `migrate:fresh` + seeds **once per test class** (`static bool $setUpHasRunOnce`), so rows persist across test methods in the same class. Use unique factory data per test method; never assume an empty table.
- Every new migration must have a working `down()`.
- Every enum in this codebase is a PHP backed `enum` under `App\Constants` — follow that for `OrderType`, `OrderApprovalActor`, `OrderApprovalDecision`.
- Filament is v5.6: form/table layouts use `->schema([...])`, computed table columns use `TextColumn::make('x')->getStateUsing(fn ($record) => …)`, and table actions are tested with `->callTableAction('name', $record, data: [...])->assertHasNoTableActionErrors()`.
- Money is stored in **minor units** (integer cents) and rendered with the global `money($amount, $currencyCode)` helper from `saasykit/laravel-money`.
- `Subscription::$casts` does **not** cast `ends_at`/`trial_ends_at` — they come back as **strings**. Always `Carbon::parse()` before doing date math on them (the existing `SubscriptionService::handleDispatchingEvents` does exactly this).
- `orders.total_amount_after_discount` is `NOT NULL DEFAULT 0`, **not** nullable. Reading amount due off a persisted order as `$order->total_amount_after_discount ?? $order->total_amount` therefore always sees `0` and misreads a paid cash order as comped. Use the `amountDue()` rule defined in Task 10. (Inside `OrderService::create()` the equivalent `??` chain *is* correct — there they are nullable method parameters, not columns.)
- The Offline payment provider row is seeded with `is_active = false`. Any test that exercises a cash flow must activate it first: `PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)->update(['is_active' => true]);`
- Run `vendor/bin/pint --format agent` before each commit; `vendor/bin/pint --test` and `vendor/bin/phpstan analyse` must stay clean.
- Out of scope, per spec §2: platform commission/settlement, multi-currency partner pricing, seat-based or usage-based cash plans (Offline stays `flat_rate`-only via the untouched `supportsPlan()`), sub-reseller chains.
- Out of scope, deferred to Plan 3: the storefront/`PartnerPricingResolver` price re-derivation (§8), the admin/customer reporting columns and attribution-history tab (§10), and the `shouldRegisterNavigation()` revert (§11). **Do not** change `CalculationService` pricing in this plan — orders are still charged at base price; this plan freezes and approves what checkout produces.

## Design decisions this plan locks in

These are decisions the spec leaves to implementation. They are binding for every task below.

1. **The Order is the unit of approval.** A cash subscription's first payment is a `PENDING` `Order` with `type = purchase` and `subscription_id` set, not a bare pending subscription. One queue, one audit table, one idempotency guard for purchases, subscription starts and renewals alike.
2. **Cash mode is gated on amount due, exactly as spec §6.1 says.** A local order with amount due `0` (the admin-comped grant) still completes immediately as `SUCCESS`; a local order with amount due `> 0` is created as `PENDING`. Nothing about the admin comped path changes.
3. **`partner_tenant_id` on an order/subscription is set only when a *usable* partner offering exists** — the buyer is attributed to a currently-active partner (Plan 1's `users.partner_tenant_id` + `PartnerCapabilityService`), that partner has an offering for the item, it is `is_enabled`, and it is not below the live minimum (`PartnerCatalogService::isPlanOfferingBelowMinimum()`). Otherwise the purchase is a direct sale: snapshots are still written from base metadata, and an admin approves it (§7.3). This prevents a partner being handed an approval task for an item they declined to resell (§8.2's rule, enforced from the write side).
4. **Renewal timing.** `app:issue-cash-renewal-orders` creates the next `PENDING` renewal order `cash_payments.renewal_lead_hours` before `ends_at`. `app:expire-pending-cash-orders` then applies: a pending **purchase** order older than `pending_ttl_hours` → `REJECTED` (system actor) and its subscription `CANCELED`; a cash subscription past `ends_at` with no approved renewal → `PAST_DUE`; a `PAST_DUE` cash subscription more than `pending_ttl_hours` past `ends_at` → `CANCELED`, with any still-pending renewal order `REJECTED`. Renewal TTL is measured from `ends_at`, not from order creation — otherwise a renewal issued early would expire before the cycle it renews.
5. **Rejecting a renewal does not revoke the cycle already paid for.** It sets `is_canceled_at_end_of_cycle = true`; the expiry sweep cancels the subscription once `ends_at` passes. Rejecting an initial purchase cancels the pending subscription immediately.
6. **Cash subscriptions are excluded from `SubscriptionService::cleanupLocalSubscriptionStatuses()`.** That sweep flips expired locally-managed subscriptions to `INACTIVE`, which would race the `PAST_DUE → CANCELED` ladder above. Cash subscriptions (locally managed + Offline provider + `price > 0`) belong to the expiry command.
7. **Spec §9 "notifications" are implemented as Mailables sent through `RenderSafeMailer`,** the pattern every other customer email in this codebase uses (`App\Mail\Order\Ordered` + `App\Listeners\Order\SendOrderNotification`), not as `Illuminate\Notifications\Notification` classes. Same channel, same "no new infrastructure" intent, one less parallel mail path to maintain.
8. **No new subscription-side event is added.** Spec §6.1 anticipates "a new subscription-side equivalent event"; `App\Events\Subscription\SubscribedOffline` already exists and already fires when a locally-managed Offline subscription lands in `PENDING`, so the pending cash subscription reuses it.
9. **The partner-facing "new pending order" email hangs off the existing `OrderedOffline` event,** which fires on every path that puts a local Offline order into `PENDING`. One listener covers product checkout, subscription start and renewal issuance.

## File structure

**New — `app/Constants/`**
- `OrderType.php` — `PURCHASE` | `RENEWAL`.
- `OrderApprovalActor.php` — `PARTNER` | `ADMIN` | `SYSTEM`.
- `OrderApprovalDecision.php` — `APPROVED` | `REJECTED`.

**New — `app/Services/CashPayments/`**
- `PurchaseSnapshotService.php` — resolves the buyer's usable partner offering and returns the `partner_tenant_id` / `base_price_snapshot` / `quota_snapshot` triple for a plan or a one-time product.
- `CashSubscriptionService.php` — identifies cash subscriptions, puts a new one into `PENDING`, and creates its purchase/renewal orders.
- `OrderApprovalService.php` — `approve()`/`reject()` (locked, idempotent, audited), the partner-facing wrappers that enforce ownership, and `isPendingCashOrder()`.

**New — models, migrations, factories**
- `app/Models/OrderApproval.php`, `database/factories/OrderApprovalFactory.php`.
- `database/migrations/2026_09_05_000001_add_partner_snapshot_to_orders_table.php`
- `database/migrations/2026_09_05_000002_add_partner_snapshot_to_subscriptions_table.php`
- `database/migrations/2026_09_05_000003_create_order_approvals_table.php`

**New — commands, config, mail**
- `config/cash_payments.php`
- `app/Console/Commands/CashPayments/IssueCashRenewalOrders.php`
- `app/Console/Commands/CashPayments/ExpirePendingCashOrders.php`
- `app/Mail/CashPayments/{PartnerNewPendingOrder,CustomerOrderApproved,CustomerOrderRejected,CustomerOrderExpired}.php`
- `resources/views/emails/cash-payments/{partner-new-pending-order,customer-order-approved,customer-order-rejected,customer-order-expired}.blade.php`
- `app/Listeners/Order/NotifyPartnerOfPendingCashOrder.php`

**New — Filament**
- `app/Filament/Dashboard/Resources/PartnerOrderApprovals/PartnerOrderApprovalResource.php`
- `app/Filament/Dashboard/Resources/PartnerOrderApprovals/Pages/ListPartnerOrderApprovals.php`

**Modified**
- `app/Constants/OrderStatus.php` — add `REJECTED`.
- `app/Constants/TenancyPermissionConstants.php` + `database/seeders/RolesAndPermissionsSeeder.php` — add `PERMISSION_MANAGE_PARTNER_ORDERS`.
- `app/Mapper/OrderStatusMapper.php`, `app/Filament/Admin/Resources/Orders/Pages/ListOrders.php`, `app/Filament/Dashboard/Resources/Orders/Pages/ListOrders.php` — render/filter the new status.
- `app/Models/Order.php`, `app/Models/Subscription.php` — snapshot columns, casts, relations.
- `app/Services/OrderService.php` — snapshot attributes + amount-due gating.
- `app/Services/SubscriptionService.php` — snapshot attributes + cash-subscription exclusion from the local cleanup sweep.
- `app/Services/CheckoutService.php` — pass the product snapshot when creating the order.
- `app/Services/PaymentProviders/Offline/OfflineProvider.php` — start a pending cash subscription.
- `app/Services/AuditReport/AuditEntitlementService.php` — prefer `quota_snapshot`.
- `app/Filament/Admin/Resources/Orders/Pages/ViewOrder.php` — admin Approve/Reject, and hide the legacy free-form status action for pending cash orders.
- `routes/console.php` — schedule the two new commands.

---

### Task 1: `OrderStatus::REJECTED`

Adds the status that distinguishes "a human declined this" from "the gateway failed", and makes it visible everywhere order status is rendered or filtered.

**Files:**
- Modify: `app/Constants/OrderStatus.php`
- Modify: `app/Mapper/OrderStatusMapper.php`
- Modify: `app/Filament/Admin/Resources/Orders/Pages/ListOrders.php`
- Modify: `app/Filament/Dashboard/Resources/Orders/Pages/ListOrders.php`
- Test: `tests/Feature/Mapper/OrderStatusMapperTest.php`

**Interfaces:**
- Produces: `App\Constants\OrderStatus::REJECTED` (value `'rejected'`), rendered as `__('Rejected')` in `danger` by `OrderStatusMapper`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Mapper/OrderStatusMapperTest.php`:

```php
<?php

namespace Tests\Feature\Mapper;

use App\Constants\OrderStatus;
use App\Mapper\OrderStatusMapper;
use Tests\Feature\FeatureTest;

class OrderStatusMapperTest extends FeatureTest
{
    public function test_rejected_reads_differently_from_failed(): void
    {
        $mapper = new OrderStatusMapper;

        $this->assertSame(__('Rejected'), $mapper->mapForDisplay(OrderStatus::REJECTED->value));
        $this->assertSame(__('Failed'), $mapper->mapForDisplay(OrderStatus::FAILED->value));
    }

    public function test_rejected_is_coloured_as_a_failure_not_a_pending_state(): void
    {
        $mapper = new OrderStatusMapper;

        $this->assertSame('danger', $mapper->mapColor(OrderStatus::REJECTED->value));
        $this->assertSame('warning', $mapper->mapColor(OrderStatus::PENDING->value));
        $this->assertSame('success', $mapper->mapColor(OrderStatus::SUCCESS->value));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=OrderStatusMapperTest`
Expected: FAIL — `Error: undefined constant App\Constants\OrderStatus::REJECTED`.

- [ ] **Step 3: Add the enum case**

In `app/Constants/OrderStatus.php`, add below `case FAILED`:

```php
    case REJECTED = 'rejected';
```

- [ ] **Step 4: Teach the mapper about it**

In `app/Mapper/OrderStatusMapper.php`:

```php
    public function mapForDisplay(string $status)
    {
        return match ($status) {
            OrderStatus::SUCCESS->value => __('Success'),
            OrderStatus::NEW->value => __('New'),
            OrderStatus::REFUNDED->value => __('Refunded'),
            OrderStatus::FAILED->value => __('Failed'),
            OrderStatus::REJECTED->value => __('Rejected'),
            default => __('Pending'),
        };
    }

    public function mapColor(string $status)
    {
        return match ($status) {
            OrderStatus::SUCCESS->value => 'success',
            OrderStatus::REJECTED->value => 'danger',
            default => 'warning',
        };
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=OrderStatusMapperTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Add the list tabs**

In `app/Filament/Admin/Resources/Orders/Pages/ListOrders.php`, inside `getTabs()`, after the `failed` tab:

```php
            __('rejected') => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', OrderStatus::REJECTED)),
```

In `app/Filament/Dashboard/Resources/Orders/Pages/ListOrders.php`, inside `getTabs()`, after the `failed` tab:

```php
            'rejected' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', OrderStatus::REJECTED)),
```

- [ ] **Step 7: Verify the panels still render**

Run: `docker compose exec laravel.test php artisan test --filter="OrderResourceTest|OrderStatusMapperTest"`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
git add app/Constants/OrderStatus.php app/Mapper/OrderStatusMapper.php app/Filament/Admin/Resources/Orders/Pages/ListOrders.php app/Filament/Dashboard/Resources/Orders/Pages/ListOrders.php tests/Feature/Mapper/OrderStatusMapperTest.php
git commit -m "feat(cash): add a rejected order status distinct from gateway failure"
```

---

### Task 2: Order snapshot columns and `OrderType`

**Files:**
- Create: `app/Constants/OrderType.php`
- Create: `database/migrations/2026_09_05_000001_add_partner_snapshot_to_orders_table.php`
- Modify: `app/Models/Order.php`
- Test: `tests/Feature/Models/OrderSnapshotColumnsTest.php`

**Interfaces:**
- Produces: `App\Constants\OrderType` with cases `PURCHASE` (`'purchase'`) and `RENEWAL` (`'renewal'`); `orders` columns `partner_tenant_id` (nullable FK → `tenants`), `base_price_snapshot` (nullable unsigned big int, minor units), `quota_snapshot` (nullable json, cast to array), `subscription_id` (nullable FK → `subscriptions`), `type` (string, DB default `'purchase'`); `Order::partnerTenant(): BelongsTo`, `Order::subscription(): BelongsTo`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Models/OrderSnapshotColumnsTest.php`:

```php
<?php

namespace Tests\Feature\Models;

use App\Constants\OrderType;
use App\Models\Order;
use App\Models\Subscription;
use Tests\Feature\FeatureTest;

class OrderSnapshotColumnsTest extends FeatureTest
{
    public function test_an_order_carries_a_partner_and_a_frozen_snapshot(): void
    {
        $tenant = $this->createTenant();
        $partnerTenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $subscription = Subscription::factory()->create(['user_id' => $user->id, 'tenant_id' => $tenant->id]);

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'partner_tenant_id' => $partnerTenant->id,
            'base_price_snapshot' => 4900,
            'quota_snapshot' => ['audit_diagnostic_credits' => 25],
            'subscription_id' => $subscription->id,
            'type' => OrderType::RENEWAL->value,
        ]);

        $fresh = $order->fresh();

        $this->assertTrue($fresh->partnerTenant->is($partnerTenant));
        $this->assertTrue($fresh->subscription->is($subscription));
        $this->assertSame(4900, (int) $fresh->base_price_snapshot);
        $this->assertSame(['audit_diagnostic_credits' => 25], $fresh->quota_snapshot);
        $this->assertSame(OrderType::RENEWAL->value, $fresh->type);
    }

    public function test_an_ordinary_order_defaults_to_a_purchase_with_no_partner(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $order = Order::factory()->create(['user_id' => $user->id, 'tenant_id' => $tenant->id])->fresh();

        $this->assertSame(OrderType::PURCHASE->value, $order->type);
        $this->assertNull($order->partner_tenant_id);
        $this->assertNull($order->subscription_id);
        $this->assertNull($order->quota_snapshot);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=OrderSnapshotColumnsTest`
Expected: FAIL — `Class "App\Constants\OrderType" not found`.

- [ ] **Step 3: Add the enum**

Create `app/Constants/OrderType.php`:

```php
<?php

namespace App\Constants;

enum OrderType: string
{
    case PURCHASE = 'purchase';
    case RENEWAL = 'renewal';
}
```

- [ ] **Step 4: Add the migration**

Create `database/migrations/2026_09_05_000001_add_partner_snapshot_to_orders_table.php`:

```php
<?php

use App\Constants\OrderType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('partner_tenant_id')->nullable()->after('tenant_id')->constrained('tenants')->nullOnDelete();
            $table->unsignedBigInteger('base_price_snapshot')->nullable()->after('partner_tenant_id');
            $table->json('quota_snapshot')->nullable()->after('base_price_snapshot');
            $table->foreignId('subscription_id')->nullable()->after('quota_snapshot')->constrained('subscriptions')->nullOnDelete();
            $table->string('type')->default(OrderType::PURCHASE->value)->after('subscription_id');
            $table->index(['partner_tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['partner_tenant_id', 'status']);
            $table->dropConstrainedForeignId('partner_tenant_id');
            $table->dropConstrainedForeignId('subscription_id');
            $table->dropColumn(['base_price_snapshot', 'quota_snapshot', 'type']);
        });
    }
};
```

- [ ] **Step 5: Extend the model**

In `app/Models/Order.php`, add to `$fillable` after `'is_local'`:

```php
        'partner_tenant_id',
        'base_price_snapshot',
        'quota_snapshot',
        'subscription_id',
        'type',
```

Add a `$casts` property (the model has none today) after `$fillable`:

```php
    protected $casts = [
        'quota_snapshot' => 'array',
    ];
```

And add the two relations next to `tenant()`:

```php
    public function partnerTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'partner_tenant_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
```

- [ ] **Step 6: Run the migration and the test**

```bash
docker compose exec laravel.test php artisan test --filter=OrderSnapshotColumnsTest
```

Expected: PASS (2 tests). The `FeatureTest` base runs `migrate:fresh` itself, so no separate migrate step is needed.

- [ ] **Step 7: Verify the migration rolls back**

```bash
docker compose exec laravel.test php artisan migrate:rollback --step=1 --env=testing
docker compose exec laravel.test php artisan migrate --env=testing
```

Expected: both succeed with no error.

- [ ] **Step 8: Commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
git add app/Constants/OrderType.php database/migrations/2026_09_05_000001_add_partner_snapshot_to_orders_table.php app/Models/Order.php tests/Feature/Models/OrderSnapshotColumnsTest.php
git commit -m "feat(cash): add partner and snapshot columns to orders"
```

---

### Task 3: Subscription snapshot columns

**Files:**
- Create: `database/migrations/2026_09_05_000002_add_partner_snapshot_to_subscriptions_table.php`
- Modify: `app/Models/Subscription.php`
- Test: `tests/Feature/Models/SubscriptionSnapshotColumnsTest.php`

**Interfaces:**
- Produces: `subscriptions` columns `partner_tenant_id` (nullable FK → `tenants`), `base_price_snapshot` (nullable unsigned big int), `quota_snapshot` (nullable json, cast to array); `Subscription::partnerTenant(): BelongsTo`, `Subscription::orders(): HasMany`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Models/SubscriptionSnapshotColumnsTest.php`:

```php
<?php

namespace Tests\Feature\Models;

use App\Constants\OrderType;
use App\Models\Order;
use App\Models\Subscription;
use Tests\Feature\FeatureTest;

class SubscriptionSnapshotColumnsTest extends FeatureTest
{
    public function test_a_subscription_carries_a_partner_and_a_frozen_snapshot(): void
    {
        $tenant = $this->createTenant();
        $partnerTenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $subscription = Subscription::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'partner_tenant_id' => $partnerTenant->id,
            'base_price_snapshot' => 4900,
            'quota_snapshot' => ['audit_deep_ai_credits' => 3],
        ])->fresh();

        $this->assertTrue($subscription->partnerTenant->is($partnerTenant));
        $this->assertSame(4900, (int) $subscription->base_price_snapshot);
        $this->assertSame(['audit_deep_ai_credits' => 3], $subscription->quota_snapshot);
    }

    public function test_a_subscription_lists_its_cash_orders(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $subscription = Subscription::factory()->create(['user_id' => $user->id, 'tenant_id' => $tenant->id]);

        Order::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'subscription_id' => $subscription->id,
            'type' => OrderType::RENEWAL->value,
        ]);

        $this->assertCount(1, $subscription->fresh()->orders);
        $this->assertSame(OrderType::RENEWAL->value, $subscription->fresh()->orders->first()->type);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=SubscriptionSnapshotColumnsTest`
Expected: FAIL — `Column not found: 'partner_tenant_id'`.

- [ ] **Step 3: Add the migration**

Create `database/migrations/2026_09_05_000002_add_partner_snapshot_to_subscriptions_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('partner_tenant_id')->nullable()->after('tenant_id')->constrained('tenants')->nullOnDelete();
            $table->unsignedBigInteger('base_price_snapshot')->nullable()->after('partner_tenant_id');
            $table->json('quota_snapshot')->nullable()->after('base_price_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_tenant_id');
            $table->dropColumn(['base_price_snapshot', 'quota_snapshot']);
        });
    }
};
```

- [ ] **Step 4: Extend the model**

In `app/Models/Subscription.php`, add to `$fillable` after `'tenant_id'`:

```php
        'partner_tenant_id',
        'base_price_snapshot',
        'quota_snapshot',
```

Add to the existing `$casts` array:

```php
        'quota_snapshot' => 'array',
```

Add the relations next to `tenant()`:

```php
    public function partnerTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'partner_tenant_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=SubscriptionSnapshotColumnsTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
git add database/migrations/2026_09_05_000002_add_partner_snapshot_to_subscriptions_table.php app/Models/Subscription.php tests/Feature/Models/SubscriptionSnapshotColumnsTest.php
git commit -m "feat(cash): add partner and snapshot columns to subscriptions"
```

---

### Task 4: `order_approvals` audit table

**Files:**
- Create: `app/Constants/OrderApprovalActor.php`
- Create: `app/Constants/OrderApprovalDecision.php`
- Create: `database/migrations/2026_09_05_000003_create_order_approvals_table.php`
- Create: `app/Models/OrderApproval.php`
- Create: `database/factories/OrderApprovalFactory.php`
- Modify: `app/Models/Order.php`
- Test: `tests/Feature/Models/OrderApprovalTest.php`

**Interfaces:**
- Produces: `App\Constants\OrderApprovalActor` (`PARTNER`/`ADMIN`/`SYSTEM`), `App\Constants\OrderApprovalDecision` (`APPROVED`/`REJECTED`), `App\Models\OrderApproval` with `$fillable` `order_id, actor_type, actor_user_id, decision, note, decided_at` and relations `order()`, `actorUser()`; `Order::approval(): HasOne`. The `order_id` column is **unique** — the DB-level backstop against double approval (spec §7.2).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Models/OrderApprovalTest.php`:

```php
<?php

namespace Tests\Feature\Models;

use App\Constants\OrderApprovalActor;
use App\Constants\OrderApprovalDecision;
use App\Models\Order;
use App\Models\OrderApproval;
use Illuminate\Database\QueryException;
use Tests\Feature\FeatureTest;

class OrderApprovalTest extends FeatureTest
{
    public function test_it_records_who_decided_what(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $order = Order::factory()->create(['user_id' => $user->id, 'tenant_id' => $tenant->id]);

        $approval = OrderApproval::create([
            'order_id' => $order->id,
            'actor_type' => OrderApprovalActor::PARTNER->value,
            'actor_user_id' => $user->id,
            'decision' => OrderApprovalDecision::APPROVED->value,
            'note' => 'Cash received in person.',
            'decided_at' => now(),
        ])->fresh();

        $this->assertTrue($approval->order->is($order));
        $this->assertTrue($approval->actorUser->is($user));
        $this->assertSame(OrderApprovalDecision::APPROVED->value, $approval->decision);
        $this->assertTrue($order->fresh()->approval->is($approval));
    }

    public function test_an_order_can_only_be_decided_once(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $order = Order::factory()->create(['user_id' => $user->id, 'tenant_id' => $tenant->id]);

        OrderApproval::factory()->create(['order_id' => $order->id]);

        $this->expectException(QueryException::class);

        OrderApproval::factory()->create(['order_id' => $order->id]);
    }

    public function test_a_system_decision_has_no_actor_user(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $order = Order::factory()->create(['user_id' => $user->id, 'tenant_id' => $tenant->id]);

        $approval = OrderApproval::factory()->create([
            'order_id' => $order->id,
            'actor_type' => OrderApprovalActor::SYSTEM->value,
            'actor_user_id' => null,
            'decision' => OrderApprovalDecision::REJECTED->value,
        ])->fresh();

        $this->assertNull($approval->actor_user_id);
        $this->assertNull($approval->actorUser);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=OrderApprovalTest`
Expected: FAIL — `Class "App\Constants\OrderApprovalActor" not found`.

- [ ] **Step 3: Add the enums**

Create `app/Constants/OrderApprovalActor.php`:

```php
<?php

namespace App\Constants;

enum OrderApprovalActor: string
{
    case PARTNER = 'partner';
    case ADMIN = 'admin';
    case SYSTEM = 'system';
}
```

Create `app/Constants/OrderApprovalDecision.php`:

```php
<?php

namespace App\Constants;

enum OrderApprovalDecision: string
{
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
}
```

- [ ] **Step 4: Add the migration**

Create `database/migrations/2026_09_05_000003_create_order_approvals_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_approvals', function (Blueprint $table) {
            $table->id();
            // Unique: the database-level backstop against a double approval
            // slipping past the application's lockForUpdate guard (spec §7.2).
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('actor_type');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision');
            $table->text('note')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_approvals');
    }
};
```

- [ ] **Step 5: Add the model and factory**

Create `app/Models/OrderApproval.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderApproval extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'actor_type',
        'actor_user_id',
        'decision',
        'note',
        'decided_at',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
```

Create `database/factories/OrderApprovalFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Constants\OrderApprovalActor;
use App\Constants\OrderApprovalDecision;
use App\Models\Order;
use App\Models\OrderApproval;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderApproval>
 */
class OrderApprovalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'actor_type' => OrderApprovalActor::ADMIN->value,
            'actor_user_id' => null,
            'decision' => OrderApprovalDecision::APPROVED->value,
            'note' => null,
            'decided_at' => now(),
        ];
    }
}
```

In `app/Models/Order.php`, add the relation (and the `HasOne` import):

```php
    public function approval(): HasOne
    {
        return $this->hasOne(OrderApproval::class);
    }
```

- [ ] **Step 6: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=OrderApprovalTest`
Expected: PASS (3 tests).

- [ ] **Step 7: Commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
git add app/Constants/OrderApprovalActor.php app/Constants/OrderApprovalDecision.php database/migrations/2026_09_05_000003_create_order_approvals_table.php app/Models/OrderApproval.php database/factories/OrderApprovalFactory.php app/Models/Order.php tests/Feature/Models/OrderApprovalTest.php
git commit -m "feat(cash): add the order_approvals audit table"
```

---
### Task 5: `PurchaseSnapshotService` — what gets frozen

Decides, for one buyer and one item, which partner (if any) owns the sale and what price/quota values to freeze onto the order and subscription. This is the only place that reads Plan 1's offering tables at purchase time.

**Files:**
- Create: `app/Services/CashPayments/PurchaseSnapshotService.php`
- Test: `tests/Feature/Services/CashPayments/PurchaseSnapshotServiceTest.php`

**Interfaces:**
- Consumes: `PartnerCapabilityService::tenantIsActivePartner(Tenant): bool`, `PartnerCatalogService::planBasePrice(Plan): int`, `PartnerCatalogService::productBasePrice(OneTimeProduct): int`, `PartnerCatalogService::isPlanOfferingBelowMinimum(PartnerPlanOffering): bool`, `PartnerCatalogService::isProductOfferingBelowMinimum(PartnerProductOffering): bool`, `User::partnerTenant(): BelongsTo` (all from Plan 1).
- Produces:
  - `PurchaseSnapshotService::partnerTenantFor(User $user): ?Tenant`
  - `PurchaseSnapshotService::forPlan(User $user, Plan $plan): array` returning `['partner_tenant_id' => ?int, 'base_price_snapshot' => ?int, 'quota_snapshot' => array<string, mixed>]`
  - `PurchaseSnapshotService::forProduct(User $user, OneTimeProduct $product): array` — same shape.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Services/CashPayments/PurchaseSnapshotServiceTest.php`:

```php
<?php

namespace Tests\Feature\Services\CashPayments;

use App\Constants\SubscriptionStatus;
use App\Models\Currency;
use App\Models\OneTimeProduct;
use App\Models\OneTimeProductPrice;
use App\Models\PartnerPlanOffering;
use App\Models\PartnerProductOffering;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CashPayments\PurchaseSnapshotService;
use Tests\Feature\FeatureTest;

class PurchaseSnapshotServiceTest extends FeatureTest
{
    private function activePartnerTenant(): Tenant
    {
        $tenant = $this->createTenant();
        $partnerProduct = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        $partnerPlan = Plan::factory()->create(['product_id' => $partnerProduct->id]);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $partnerPlan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);

        return $tenant;
    }

    /** @return array{0: Plan, 1: Product} */
    private function sellablePlan(int $basePrice = 4900, array $metadata = ['audit_diagnostic_credits' => 10]): array
    {
        $product = Product::factory()->create([
            'reseller_quota_keys' => array_keys($metadata),
            'metadata' => $metadata,
        ]);
        $plan = Plan::factory()->create(['product_id' => $product->id, 'is_visible' => true]);
        PlanPrice::factory()->create([
            'plan_id' => $plan->id,
            'currency_id' => Currency::where('code', 'USD')->first()->id,
            'price' => $basePrice,
        ]);

        return [$plan, $product];
    }

    public function test_an_unattributed_buyer_freezes_base_price_and_base_metadata(): void
    {
        [$plan] = $this->sellablePlan();
        $user = User::factory()->create();

        $snapshot = app(PurchaseSnapshotService::class)->forPlan($user, $plan);

        $this->assertNull($snapshot['partner_tenant_id']);
        $this->assertSame(4900, $snapshot['base_price_snapshot']);
        $this->assertSame(['audit_diagnostic_credits' => 10], $snapshot['quota_snapshot']);
    }

    public function test_an_attributed_buyer_gets_the_partners_quota_overrides_merged_over_base(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        [$plan] = $this->sellablePlan(4900, ['audit_diagnostic_credits' => 10, 'audit_deep_ai_credits' => 2]);
        $user = User::factory()->create(['partner_tenant_id' => $partnerTenant->id, 'partner_attributed_at' => now()]);

        PartnerPlanOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $plan->id,
            'price' => 6900,
            'quota_overrides' => ['audit_diagnostic_credits' => 25],
            'is_enabled' => true,
        ]);

        $snapshot = app(PurchaseSnapshotService::class)->forPlan($user, $plan);

        $this->assertSame($partnerTenant->id, $snapshot['partner_tenant_id']);
        $this->assertSame(4900, $snapshot['base_price_snapshot'], 'base_price_snapshot is the platform base, never the partner price');
        $this->assertSame(
            ['audit_diagnostic_credits' => 25, 'audit_deep_ai_credits' => 2],
            $snapshot['quota_snapshot'],
        );
    }

    public function test_a_disabled_offering_makes_it_a_direct_sale(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        [$plan] = $this->sellablePlan();
        $user = User::factory()->create(['partner_tenant_id' => $partnerTenant->id, 'partner_attributed_at' => now()]);

        PartnerPlanOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $plan->id,
            'price' => 6900,
            'quota_overrides' => ['audit_diagnostic_credits' => 25],
            'is_enabled' => false,
        ]);

        $snapshot = app(PurchaseSnapshotService::class)->forPlan($user, $plan);

        $this->assertNull($snapshot['partner_tenant_id']);
        $this->assertSame(['audit_diagnostic_credits' => 10], $snapshot['quota_snapshot']);
    }

    public function test_an_offering_that_fell_below_the_admin_minimum_makes_it_a_direct_sale(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        [$plan, $product] = $this->sellablePlan();
        $user = User::factory()->create(['partner_tenant_id' => $partnerTenant->id, 'partner_attributed_at' => now()]);

        PartnerPlanOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $plan->id,
            'price' => 4900,
            'quota_overrides' => ['audit_diagnostic_credits' => 12],
            'is_enabled' => true,
        ]);

        // The admin raises the base quota above what the partner promised.
        $product->update(['metadata' => ['audit_diagnostic_credits' => 20]]);

        $snapshot = app(PurchaseSnapshotService::class)->forPlan($user, $plan);

        $this->assertNull($snapshot['partner_tenant_id']);
        $this->assertSame(['audit_diagnostic_credits' => 20], $snapshot['quota_snapshot']);
    }

    public function test_a_lapsed_partner_plan_makes_it_a_direct_sale(): void
    {
        $partnerTenant = $this->createTenant(); // no partner-plan subscription at all
        [$plan] = $this->sellablePlan();
        $user = User::factory()->create(['partner_tenant_id' => $partnerTenant->id, 'partner_attributed_at' => now()]);

        PartnerPlanOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $plan->id,
            'price' => 6900,
            'quota_overrides' => [],
            'is_enabled' => true,
        ]);

        $this->assertNull(app(PurchaseSnapshotService::class)->partnerTenantFor($user));
        $this->assertNull(app(PurchaseSnapshotService::class)->forPlan($user, $plan)['partner_tenant_id']);
    }

    public function test_it_snapshots_one_time_products_too(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $product = OneTimeProduct::factory()->create([
            'reseller_quota_keys' => ['audit_expert_credits'],
            'metadata' => ['audit_expert_credits' => 1],
        ]);
        OneTimeProductPrice::factory()->create([
            'one_time_product_id' => $product->id,
            'currency_id' => Currency::where('code', 'USD')->first()->id,
            'price' => 9900,
        ]);
        $user = User::factory()->create(['partner_tenant_id' => $partnerTenant->id, 'partner_attributed_at' => now()]);

        PartnerProductOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'one_time_product_id' => $product->id,
            'price' => 12900,
            'quota_overrides' => ['audit_expert_credits' => 3],
            'is_enabled' => true,
        ]);

        $snapshot = app(PurchaseSnapshotService::class)->forProduct($user, $product);

        $this->assertSame($partnerTenant->id, $snapshot['partner_tenant_id']);
        $this->assertSame(9900, $snapshot['base_price_snapshot']);
        $this->assertSame(['audit_expert_credits' => 3], $snapshot['quota_snapshot']);
    }

    public function test_an_item_with_no_price_in_the_default_currency_snapshots_a_null_base_price(): void
    {
        $product = Product::factory()->create(['metadata' => []]);
        $plan = Plan::factory()->create(['product_id' => $product->id]); // no PlanPrice row
        $user = User::factory()->create();

        $snapshot = app(PurchaseSnapshotService::class)->forPlan($user, $plan);

        $this->assertNull($snapshot['base_price_snapshot']);
        $this->assertSame([], $snapshot['quota_snapshot']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=PurchaseSnapshotServiceTest`
Expected: FAIL — `Target class [App\Services\CashPayments\PurchaseSnapshotService] does not exist`.

- [ ] **Step 3: Write the service**

Create `app/Services/CashPayments/PurchaseSnapshotService.php`:

```php
<?php

namespace App\Services\CashPayments;

use App\Exceptions\PartnerOfferingValidationException;
use App\Models\OneTimeProduct;
use App\Models\PartnerPlanOffering;
use App\Models\PartnerProductOffering;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PartnerCapabilityService;
use App\Services\PartnerCatalogService;

/**
 * Decides what an order/subscription freezes at creation time (spec §6.2).
 *
 * The snapshot is written for every purchase, partner-attributed or not, so a
 * later admin edit to a product's metadata can never retroactively change an
 * existing customer's entitlement.
 */
class PurchaseSnapshotService
{
    public function __construct(
        private PartnerCapabilityService $capabilityService,
        private PartnerCatalogService $catalogService,
    ) {}

    /**
     * The partner tenant this buyer is attributed to, but only while that
     * tenant's Partner Plan is currently active (spec §7.5).
     */
    public function partnerTenantFor(User $user): ?Tenant
    {
        /** @var Tenant|null $tenant */
        $tenant = $user->partnerTenant;

        if ($tenant === null) {
            return null;
        }

        return $this->capabilityService->tenantIsActivePartner($tenant) ? $tenant : null;
    }

    /**
     * @return array{partner_tenant_id: int|null, base_price_snapshot: int|null, quota_snapshot: array<string, mixed>}
     */
    public function forPlan(User $user, Plan $plan): array
    {
        /** @var Product|null $product */
        $product = $plan->product;
        $baseMetadata = (array) ($product->metadata ?? []);
        $offering = $this->usablePlanOffering($user, $plan);

        return [
            'partner_tenant_id' => $offering?->tenant_id,
            'base_price_snapshot' => $this->basePriceOrNull(fn (): int => $this->catalogService->planBasePrice($plan)),
            'quota_snapshot' => array_merge($baseMetadata, (array) ($offering->quota_overrides ?? [])),
        ];
    }

    /**
     * @return array{partner_tenant_id: int|null, base_price_snapshot: int|null, quota_snapshot: array<string, mixed>}
     */
    public function forProduct(User $user, OneTimeProduct $product): array
    {
        $baseMetadata = (array) ($product->metadata ?? []);
        $offering = $this->usableProductOffering($user, $product);

        return [
            'partner_tenant_id' => $offering?->tenant_id,
            'base_price_snapshot' => $this->basePriceOrNull(fn (): int => $this->catalogService->productBasePrice($product)),
            'quota_snapshot' => array_merge($baseMetadata, (array) ($offering->quota_overrides ?? [])),
        ];
    }

    /**
     * An offering only owns the sale when it is enabled AND still at or above
     * the live admin floor. A disabled or below-minimum offering makes this a
     * direct sale (base pricing, admin-approved) rather than blocking checkout.
     */
    private function usablePlanOffering(User $user, Plan $plan): ?PartnerPlanOffering
    {
        $tenant = $this->partnerTenantFor($user);

        if ($tenant === null) {
            return null;
        }

        $offering = PartnerPlanOffering::where('tenant_id', $tenant->id)
            ->where('plan_id', $plan->id)
            ->where('is_enabled', true)
            ->first();

        if ($offering === null) {
            return null;
        }

        return $this->catalogService->isPlanOfferingBelowMinimum($offering) ? null : $offering;
    }

    private function usableProductOffering(User $user, OneTimeProduct $product): ?PartnerProductOffering
    {
        $tenant = $this->partnerTenantFor($user);

        if ($tenant === null) {
            return null;
        }

        $offering = PartnerProductOffering::where('tenant_id', $tenant->id)
            ->where('one_time_product_id', $product->id)
            ->where('is_enabled', true)
            ->first();

        if ($offering === null) {
            return null;
        }

        return $this->catalogService->isProductOfferingBelowMinimum($offering) ? null : $offering;
    }

    /**
     * Checkout must not blow up on an item that has no price row in the store's
     * default currency — the margin column simply has nothing to report.
     *
     * @param  callable(): int  $resolver
     */
    private function basePriceOrNull(callable $resolver): ?int
    {
        try {
            return $resolver();
        } catch (PartnerOfferingValidationException) {
            return null;
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=PurchaseSnapshotServiceTest`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
docker compose exec laravel.test vendor/bin/phpstan analyse
git add app/Services/CashPayments/PurchaseSnapshotService.php tests/Feature/Services/CashPayments/PurchaseSnapshotServiceTest.php
git commit -m "feat(cash): resolve the price and quota snapshot for a purchase"
```

---

### Task 6: `OrderService` — snapshot attributes and the amount-due gate

**Files:**
- Modify: `app/Services/OrderService.php`
- Modify: `app/Services/CheckoutService.php`
- Test: `tests/Feature/Services/CashPayments/CashOrderCreationTest.php`

**Interfaces:**
- Consumes: `PurchaseSnapshotService::forProduct(User, OneTimeProduct): array`.
- Produces: `OrderService::create(..., bool $isLocal = false, array $snapshot = []): Order` — `$snapshot` accepts any of `partner_tenant_id`, `base_price_snapshot`, `quota_snapshot`, `subscription_id`, `type` and ignores anything else. A local order with amount due `> 0` is created `PENDING` and dispatches `OrderedOffline`; a local order with amount due `0` is created `SUCCESS` and dispatches `Ordered`, exactly as before.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Services/CashPayments/CashOrderCreationTest.php`:

```php
<?php

namespace Tests\Feature\Services\CashPayments;

use App\Constants\OrderStatus;
use App\Constants\OrderType;
use App\Constants\PaymentProviderConstants;
use App\Events\Order\Ordered;
use App\Events\Order\OrderedOffline;
use App\Models\PaymentProvider;
use App\Services\OrderService;
use Illuminate\Support\Facades\Event;
use Tests\Feature\FeatureTest;

class CashOrderCreationTest extends FeatureTest
{
    private function offlineProvider(): PaymentProvider
    {
        $provider = PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)->firstOrFail();
        $provider->update(['is_active' => true]);

        return $provider;
    }

    public function test_a_comped_zero_amount_local_order_still_completes_immediately(): void
    {
        Event::fake([Ordered::class, OrderedOffline::class]);

        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $order = app(OrderService::class)->create($user, $tenant, isLocal: true);

        $this->assertSame(OrderStatus::SUCCESS->value, $order->status);
        Event::assertDispatched(Ordered::class);
        Event::assertNotDispatched(OrderedOffline::class);
    }

    public function test_a_paid_local_order_waits_for_a_human_to_confirm_the_cash(): void
    {
        Event::fake([Ordered::class, OrderedOffline::class]);

        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $order = app(OrderService::class)->create(
            $user,
            $tenant,
            paymentProvider: $this->offlineProvider(),
            totalAmount: 4900,
            isLocal: true,
        );

        $this->assertSame(OrderStatus::PENDING->value, $order->status);
        Event::assertNotDispatched(Ordered::class);
        Event::assertDispatched(OrderedOffline::class);
    }

    public function test_the_discounted_amount_decides_the_gate(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $order = app(OrderService::class)->create(
            $user,
            $tenant,
            paymentProvider: $this->offlineProvider(),
            totalAmount: 4900,
            discountTotal: 4900,
            totalAmountAfterDiscount: 0,
            isLocal: true,
        );

        $this->assertSame(OrderStatus::SUCCESS->value, $order->status);
    }

    public function test_it_persists_the_snapshot_attributes_it_is_handed(): void
    {
        $tenant = $this->createTenant();
        $partnerTenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $order = app(OrderService::class)->create(
            $user,
            $tenant,
            paymentProvider: $this->offlineProvider(),
            totalAmount: 6900,
            isLocal: true,
            snapshot: [
                'partner_tenant_id' => $partnerTenant->id,
                'base_price_snapshot' => 4900,
                'quota_snapshot' => ['audit_diagnostic_credits' => 25],
                'type' => OrderType::RENEWAL->value,
                'not_a_column' => 'ignored',
            ],
        )->fresh();

        $this->assertSame($partnerTenant->id, $order->partner_tenant_id);
        $this->assertSame(4900, (int) $order->base_price_snapshot);
        $this->assertSame(['audit_diagnostic_credits' => 25], $order->quota_snapshot);
        $this->assertSame(OrderType::RENEWAL->value, $order->type);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=CashOrderCreationTest`
Expected: FAIL — `Unknown named parameter $snapshot`, and the paid-local test reports `success` instead of `pending`.

- [ ] **Step 3: Change `OrderService::create()`**

In `app/Services/OrderService.php`, add `use Illuminate\Support\Arr;` to the imports, add the parameter to the signature:

```php
        bool $isLocal = false,
        array $snapshot = [],
    ): Order {
```

Replace the `if ($isLocal) { $orderAttributes['status'] = OrderStatus::SUCCESS->value; ... }` block and the trailing dispatch block with:

```php
        $orderAttributes = array_merge($orderAttributes, Arr::only($snapshot, [
            'partner_tenant_id',
            'base_price_snapshot',
            'quota_snapshot',
            'subscription_id',
            'type',
        ]));

        if ($isLocal) {
            // A local order with nothing left to pay is the admin-comped grant and
            // completes immediately, exactly as it always has. A local order with
            // money still owed is a cash sale: it stays PENDING until a partner or
            // an admin confirms the cash arrived (spec §6.1).
            $amountDue = $totalAmountAfterDiscount ?? $totalAmount ?? 0;

            $orderAttributes['status'] = $amountDue > 0
                ? OrderStatus::PENDING->value
                : OrderStatus::SUCCESS->value;
        }

        $order = Order::create($orderAttributes);

        if ($orderItems) {
            $order->items()->createMany($orderItems);
        }

        if ($isLocal && $order->status === OrderStatus::SUCCESS->value) {
            // if it's a local order, dispatch the Ordered event immediately
            Ordered::dispatch($order);
        }

        if ($isLocal && $order->status === OrderStatus::PENDING->value) {
            // Cash sale awaiting confirmation — the same event the offline
            // checkout path already fires when an order lands in PENDING.
            OrderedOffline::dispatch($order);
        }

        return $order;
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=CashOrderCreationTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Snapshot the product checkout order too**

In `app/Services/CheckoutService.php`, add the imports and constructor dependencies:

```php
use App\Services\CashPayments\PurchaseSnapshotService;
```

```php
    public function __construct(
        private SubscriptionService $subscriptionService,
        private OrderService $orderService,
        private TenantCreationService $tenantCreationService,
        private PlanService $planService,
        private OneTimeProductService $oneTimeProductService,
        private PurchaseSnapshotService $purchaseSnapshotService,
    ) {}
```

In `initProductCheckout()`, replace the order creation line:

```php
        if ($order === null) {
            $order = $this->orderService->create(
                $user,
                $tenant,
                isLocal: $isLocalOrder,
                snapshot: $this->snapshotForCart($cartDto, $user),
            );
        }
```

and add the private helper at the bottom of the class:

```php
    /**
     * Product checkout is single-product in this codebase (every caller reads
     * $cartDto->items[0]), so the snapshot follows the first cart item.
     *
     * @return array{partner_tenant_id: int|null, base_price_snapshot: int|null, quota_snapshot: array<string, mixed>}|array{}
     */
    private function snapshotForCart(CartDto $cartDto, User $user): array
    {
        $firstItem = $cartDto->items[0] ?? null;

        if ($firstItem === null) {
            return [];
        }

        return $this->purchaseSnapshotService->forProduct(
            $user,
            $this->oneTimeProductService->getOneTimeProductById($firstItem->productId),
        );
    }
```

Add `use App\Models\User;` to the imports if it is not already there.

- [ ] **Step 6: Verify the checkout flows still pass**

Run: `docker compose exec laravel.test php artisan test --filter="CashOrderCreationTest|OrderServiceTest|ProductCheckoutFormTest|CheckoutService"`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
docker compose exec laravel.test vendor/bin/phpstan analyse
git add app/Services/OrderService.php app/Services/CheckoutService.php tests/Feature/Services/CashPayments/CashOrderCreationTest.php
git commit -m "feat(cash): hold paid local orders as pending and freeze their snapshot"
```

---

### Task 7: `SubscriptionService` — every new subscription carries its snapshot

**Files:**
- Modify: `app/Services/SubscriptionService.php`
- Test: `tests/Feature/Services/CashPayments/SubscriptionSnapshotOnCreateTest.php`

**Interfaces:**
- Consumes: `PurchaseSnapshotService::forPlan(User, Plan): array`.
- Produces: `SubscriptionService::create()` writes `partner_tenant_id`, `base_price_snapshot` and `quota_snapshot` onto every subscription it creates.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Services/CashPayments/SubscriptionSnapshotOnCreateTest.php`:

```php
<?php

namespace Tests\Feature\Services\CashPayments;

use App\Constants\SubscriptionStatus;
use App\Models\Currency;
use App\Models\PartnerPlanOffering;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTest;

class SubscriptionSnapshotOnCreateTest extends FeatureTest
{
    private function activePartnerTenant(): Tenant
    {
        $tenant = $this->createTenant();
        $partnerProduct = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        $partnerPlan = Plan::factory()->create(['product_id' => $partnerProduct->id]);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $partnerPlan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);

        return $tenant;
    }

    private function sellablePlan(array $metadata): Plan
    {
        $product = Product::factory()->create([
            'reseller_quota_keys' => array_keys($metadata),
            'metadata' => $metadata,
        ]);
        $plan = Plan::factory()->create([
            'product_id' => $product->id,
            'slug' => Str::random(12),
            'is_active' => true,
            'is_visible' => true,
        ]);
        PlanPrice::factory()->create([
            'plan_id' => $plan->id,
            'currency_id' => Currency::where('code', 'USD')->first()->id,
            'price' => 4900,
        ]);

        return $plan;
    }

    public function test_a_direct_subscription_freezes_the_base_metadata(): void
    {
        $plan = $this->sellablePlan(['audit_deep_ai_credits' => 2]);
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $subscription = app(SubscriptionService::class)->create(
            planSlug: $plan->slug,
            userId: $user->id,
            quantity: 1,
            tenant: $tenant,
        )->fresh();

        $this->assertNull($subscription->partner_tenant_id);
        $this->assertSame(4900, (int) $subscription->base_price_snapshot);
        $this->assertSame(['audit_deep_ai_credits' => 2], $subscription->quota_snapshot);
    }

    public function test_a_partner_attributed_subscription_freezes_the_partner_overrides(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $plan = $this->sellablePlan(['audit_deep_ai_credits' => 2]);
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $user->update(['partner_tenant_id' => $partnerTenant->id, 'partner_attributed_at' => now()]);

        PartnerPlanOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $plan->id,
            'price' => 7900,
            'quota_overrides' => ['audit_deep_ai_credits' => 5],
            'is_enabled' => true,
        ]);

        $subscription = app(SubscriptionService::class)->create(
            planSlug: $plan->slug,
            userId: $user->id,
            quantity: 1,
            tenant: $tenant,
        )->fresh();

        $this->assertSame($partnerTenant->id, $subscription->partner_tenant_id);
        $this->assertSame(['audit_deep_ai_credits' => 5], $subscription->quota_snapshot);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=SubscriptionSnapshotOnCreateTest`
Expected: FAIL — both assertions on `base_price_snapshot`/`quota_snapshot` see `null`.

- [ ] **Step 3: Write the snapshot in `create()`**

In `app/Services/SubscriptionService.php`, inside the `DB::transaction` closure of `create()`, directly after `$subscriptionAttributes` is built (before the `if ($paymentProvider)` block), insert:

```php
            // Resolved through the container rather than constructor-injected:
            // PurchaseSnapshotService -> PartnerCapabilityService ->
            // SubscriptionService is a container cycle.
            $snapshot = app(PurchaseSnapshotService::class)->forPlan(User::findOrFail($userId), $plan);

            $subscriptionAttributes['partner_tenant_id'] = $snapshot['partner_tenant_id'];
            $subscriptionAttributes['base_price_snapshot'] = $snapshot['base_price_snapshot'];
            $subscriptionAttributes['quota_snapshot'] = $snapshot['quota_snapshot'];
```

Add the import:

```php
use App\Services\CashPayments\PurchaseSnapshotService;
```

(`App\Models\User` is already imported.)

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=SubscriptionSnapshotOnCreateTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Verify no subscription regression**

Run: `docker compose exec laravel.test php artisan test --filter="SubscriptionService|SubscriptionCheckoutForm|ConvertLocalSubscription"`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
docker compose exec laravel.test vendor/bin/phpstan analyse
git add app/Services/SubscriptionService.php tests/Feature/Services/CashPayments/SubscriptionSnapshotOnCreateTest.php
git commit -m "feat(cash): freeze price and quota values on every new subscription"
```

---

### Task 8: `CashSubscriptionService` — a cash subscription starts pending

**Files:**
- Create: `app/Services/CashPayments/CashSubscriptionService.php`
- Modify: `app/Services/PaymentProviders/Offline/OfflineProvider.php`
- Test: `tests/Feature/Services/CashPayments/CashSubscriptionServiceTest.php`

**Interfaces:**
- Consumes: `OrderService::create(..., array $snapshot)` (Task 6), `SubscriptionService::updateSubscription(Subscription, array): Subscription`.
- Produces:
  - `CashSubscriptionService::isCashSubscription(Subscription): bool` — locally managed **and** on the Offline provider **and** `price > 0`.
  - `CashSubscriptionService::startPendingCashSubscription(Subscription): Order` — moves the subscription to `PENDING` and returns its `PENDING` purchase order.
  - `CashSubscriptionService::createPendingOrder(Subscription, OrderType): Order` — used again by the renewal command in Task 14.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Services/CashPayments/CashSubscriptionServiceTest.php`:

```php
<?php

namespace Tests\Feature\Services\CashPayments;

use App\Constants\OrderStatus;
use App\Constants\OrderType;
use App\Constants\PaymentProviderConstants;
use App\Constants\SubscriptionStatus;
use App\Constants\SubscriptionType;
use App\Models\Currency;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Services\CashPayments\CashSubscriptionService;
use App\Services\PaymentProviders\Offline\OfflineProvider;
use Tests\Feature\FeatureTest;

class CashSubscriptionServiceTest extends FeatureTest
{
    private function offlineProvider(): PaymentProvider
    {
        $provider = PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)->firstOrFail();
        $provider->update(['is_active' => true]);

        return $provider;
    }

    private function cashSubscription(int $price = 4900): Subscription
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $product = Product::factory()->create(['metadata' => ['audit_diagnostic_credits' => 10]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);

        return Subscription::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'price' => $price,
            'currency_id' => Currency::where('code', 'USD')->first()->id,
            'status' => SubscriptionStatus::NEW->value,
            'type' => SubscriptionType::LOCALLY_MANAGED,
            'payment_provider_id' => $this->offlineProvider()->id,
            'ends_at' => null,
            'base_price_snapshot' => 4900,
            'quota_snapshot' => ['audit_diagnostic_credits' => 10],
        ]);
    }

    public function test_a_paid_offline_subscription_is_a_cash_subscription(): void
    {
        $service = app(CashSubscriptionService::class);

        $this->assertTrue($service->isCashSubscription($this->cashSubscription()));
        $this->assertFalse($service->isCashSubscription($this->cashSubscription(0)));
    }

    public function test_starting_one_parks_the_subscription_and_opens_a_purchase_order(): void
    {
        $subscription = $this->cashSubscription();

        $order = app(CashSubscriptionService::class)->startPendingCashSubscription($subscription);

        $this->assertSame(SubscriptionStatus::PENDING->value, $subscription->fresh()->status);
        $this->assertSame(OrderStatus::PENDING->value, $order->status);
        $this->assertSame(OrderType::PURCHASE->value, $order->type);
        $this->assertSame($subscription->id, $order->subscription_id);
        $this->assertSame(4900, (int) $order->total_amount);
        $this->assertSame(4900, (int) $order->base_price_snapshot);
        $this->assertSame(['audit_diagnostic_credits' => 10], $order->fresh()->quota_snapshot);
        $this->assertTrue((bool) $order->is_local);
    }

    public function test_the_offline_provider_starts_it_during_checkout(): void
    {
        $subscription = $this->cashSubscription();
        $subscription->update(['type' => SubscriptionType::PAYMENT_PROVIDER_MANAGED, 'payment_provider_id' => null]);

        app(OfflineProvider::class)->initSubscriptionCheckout($subscription->plan, $subscription);

        $subscription->refresh();

        $this->assertSame(SubscriptionStatus::PENDING->value, $subscription->status);
        $this->assertSame(1, $subscription->orders()->where('status', OrderStatus::PENDING->value)->count());
    }

    public function test_a_free_offline_subscription_is_left_exactly_as_before(): void
    {
        $subscription = $this->cashSubscription(0);
        $subscription->update(['type' => SubscriptionType::PAYMENT_PROVIDER_MANAGED, 'payment_provider_id' => null]);

        app(OfflineProvider::class)->initSubscriptionCheckout($subscription->plan, $subscription);

        $subscription->refresh();

        $this->assertSame(SubscriptionStatus::NEW->value, $subscription->status);
        $this->assertSame(0, $subscription->orders()->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=CashSubscriptionServiceTest`
Expected: FAIL — `Target class [App\Services\CashPayments\CashSubscriptionService] does not exist`.

- [ ] **Step 3: Write the service**

Create `app/Services/CashPayments/CashSubscriptionService.php`:

```php
<?php

namespace App\Services\CashPayments;

use App\Constants\OrderType;
use App\Constants\PaymentProviderConstants;
use App\Constants\SubscriptionStatus;
use App\Constants\SubscriptionType;
use App\Models\Order;
use App\Models\Subscription;
use App\Services\OrderService;
use App\Services\SubscriptionService;

/**
 * A cash subscription never activates on its own: every cycle it wants paid is
 * represented by a PENDING order that a partner or an admin has to approve
 * (spec §6.1, §6.6).
 */
class CashSubscriptionService
{
    public function __construct(
        private OrderService $orderService,
        private SubscriptionService $subscriptionService,
    ) {}

    public function isCashSubscription(Subscription $subscription): bool
    {
        return $subscription->type === SubscriptionType::LOCALLY_MANAGED
            && $subscription->paymentProvider?->slug === PaymentProviderConstants::OFFLINE_SLUG
            && (int) $subscription->price > 0;
    }

    public function startPendingCashSubscription(Subscription $subscription): Order
    {
        $this->subscriptionService->updateSubscription($subscription, [
            'status' => SubscriptionStatus::PENDING->value,
        ]);

        return $this->createPendingOrder($subscription, OrderType::PURCHASE);
    }

    /**
     * The order copies the subscription's own locked-in values — never the
     * partner's current catalog — so a partner editing their offering after the
     * sale cannot change what an existing subscriber owes or receives (§6.6).
     */
    public function createPendingOrder(Subscription $subscription, OrderType $type): Order
    {
        return $this->orderService->create(
            user: $subscription->user,
            tenant: $subscription->tenant,
            paymentProvider: $subscription->paymentProvider,
            totalAmount: (int) $subscription->price,
            currency: $subscription->currency,
            isLocal: true,
            snapshot: [
                'partner_tenant_id' => $subscription->partner_tenant_id,
                'base_price_snapshot' => $subscription->base_price_snapshot,
                'quota_snapshot' => $subscription->quota_snapshot,
                'subscription_id' => $subscription->id,
                'type' => $type->value,
            ],
        );
    }
}
```

- [ ] **Step 4: Hook it into the Offline provider**

In `app/Services/PaymentProviders/Offline/OfflineProvider.php`, add the constructor dependency:

```php
    public function __construct(
        private OrderService $orderService,
        private SubscriptionService $subscriptionService,
        private CashSubscriptionService $cashSubscriptionService,
    ) {}
```

with `use App\Services\CashPayments\CashSubscriptionService;` added to the imports, and replace the body of `initSubscriptionCheckout()`:

```php
    public function initSubscriptionCheckout(Plan $plan, Subscription $subscription, ?Discount $discount = null, int $quantity = 1): array
    {
        $paymentProvider = $this->assertProviderIsActive();

        $this->subscriptionService->updateSubscription(
            $subscription,
            [
                'type' => SubscriptionType::LOCALLY_MANAGED,
                'payment_provider_id' => $paymentProvider->id,
            ]
        );

        $subscription->refresh();

        // Zero-price offline subscriptions are the admin-comped path and keep
        // their existing behaviour untouched.
        if ($this->cashSubscriptionService->isCashSubscription($subscription)) {
            $this->cashSubscriptionService->startPendingCashSubscription($subscription);
        }

        return [];
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=CashSubscriptionServiceTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Verify the offline provider's other users still pass**

Run: `docker compose exec laravel.test php artisan test --filter="Offline|SubscriptionCheckoutForm|ConvertLocalSubscription"`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
docker compose exec laravel.test vendor/bin/phpstan analyse
git add app/Services/CashPayments/CashSubscriptionService.php app/Services/PaymentProviders/Offline/OfflineProvider.php tests/Feature/Services/CashPayments/CashSubscriptionServiceTest.php
git commit -m "feat(cash): open a pending order when a paid offline subscription starts"
```

---

### Task 9: Entitlement reads prefer the snapshot

Without this the partner's quota overrides silently collapse to the base plan's values every time a quota is read (spec §6.3).

**Files:**
- Modify: `app/Services/AuditReport/AuditEntitlementService.php`
- Test: `tests/Feature/Services/AuditSnapshotEntitlementTest.php`

**Interfaces:**
- Produces: `AuditEntitlementService::allowance()` (and therefore `quotaFor()`/`remainingRuns()`) reads `subscription->quota_snapshot[$key]` when present, falling back to `data_get($product->metadata, $key, 0)`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Services/AuditSnapshotEntitlementTest.php`:

```php
<?php

namespace Tests\Feature\Services;

use App\Constants\AuditTier;
use App\Constants\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Services\AuditReport\AuditEntitlementService;
use Tests\Feature\FeatureTest;

class AuditSnapshotEntitlementTest extends FeatureTest
{
    public function test_the_snapshot_wins_over_the_live_plan_metadata(): void
    {
        $tenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['audit_deep_ai_credits' => 2]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);

        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
            'quota_snapshot' => ['audit_deep_ai_credits' => 7],
        ]);

        $this->assertSame(7, app(AuditEntitlementService::class)->allowance($tenant, AuditTier::DEEP_AI));
    }

    public function test_a_key_missing_from_the_snapshot_falls_back_to_plan_metadata(): void
    {
        $tenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['audit_deep_ai_credits' => 4]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);

        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
            'quota_snapshot' => ['audit_diagnostic_credits' => 30],
        ]);

        $this->assertSame(4, app(AuditEntitlementService::class)->allowance($tenant, AuditTier::DEEP_AI));
    }

    public function test_a_subscription_with_no_snapshot_behaves_exactly_as_before(): void
    {
        $tenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['audit_expert_credits' => 1]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);

        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
            'quota_snapshot' => null,
        ]);

        $this->assertSame(1, app(AuditEntitlementService::class)->allowance($tenant, AuditTier::EXPERT));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=AuditSnapshotEntitlementTest`
Expected: FAIL — the first test gets `2` instead of `7`.

- [ ] **Step 3: Prefer the snapshot**

In `app/Services/AuditReport/AuditEntitlementService.php`, replace the body of the `map()` closure inside `planMetadata()`:

```php
            ->map(function (Subscription $subscription) use ($key): int {
                // The snapshot frozen at purchase wins: a partner-granted
                // override, or the base value as it stood when the customer
                // bought, must not drift when an admin edits the product
                // afterwards (spec §6.3).
                $snapshotValue = data_get($subscription->quota_snapshot, $key);

                if ($snapshotValue !== null) {
                    return (int) $snapshotValue;
                }

                /** @var Plan|null $plan */
                $plan = $subscription->plan;

                if ($plan === null) {
                    return 0;
                }

                /** @var Product|null $product */
                $product = $plan->product;

                return $product === null ? 0 : (int) data_get($product->metadata, $key, 0);
            })
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=AuditSnapshotEntitlementTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Verify the existing entitlement suite is untouched**

Run: `docker compose exec laravel.test php artisan test --filter="AuditEntitlementServiceTest|AuditSubscriptionEntitlementTest|AuditPrepaidRunTest"`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
docker compose exec laravel.test vendor/bin/phpstan analyse
git add app/Services/AuditReport/AuditEntitlementService.php tests/Feature/Services/AuditSnapshotEntitlementTest.php
git commit -m "feat(cash): read audit quotas from the subscription snapshot first"
```

---
### Task 10: `OrderApprovalService` — the guarded state machine

**Files:**
- Create: `app/Services/CashPayments/OrderApprovalService.php`
- Test: `tests/Feature/Services/CashPayments/OrderApprovalServiceTest.php`

**Interfaces:**
- Consumes: `OrderService::updateOrder(Order, array): Order`, `SubscriptionService::updateSubscription(Subscription, array): Subscription`, `CashSubscriptionService::createPendingOrder()` (indirectly, via test fixtures).
- Produces:
  - `OrderApprovalService::approve(Order $order, OrderApprovalActor $actor, ?User $actingUser = null, ?string $note = null): bool` — `true` when this call performed the transition, `false` when the order was no longer `PENDING` (a duplicate click is a silent no-op, spec §7.2).
  - `OrderApprovalService::reject(Order $order, OrderApprovalActor $actor, ?User $actingUser = null, ?string $note = null): bool` — same contract.
  - `OrderApprovalService::isPendingCashOrder(Order $order): bool` — `PENDING` **and** `is_local` **and** amount due `> 0`.
  - `OrderApprovalService::amountDue(Order $order): int` — the amount-due rule that survives the `total_amount_after_discount DEFAULT 0` column (see Global Constraints); reused by the partner queue's price and margin columns.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Services/CashPayments/OrderApprovalServiceTest.php`:

```php
<?php

namespace Tests\Feature\Services\CashPayments;

use App\Constants\OrderApprovalActor;
use App\Constants\OrderApprovalDecision;
use App\Constants\OrderStatus;
use App\Constants\OrderType;
use App\Constants\PaymentProviderConstants;
use App\Constants\SubscriptionStatus;
use App\Constants\SubscriptionType;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderApproval;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Services\CashPayments\CashSubscriptionService;
use App\Services\CashPayments\OrderApprovalService;
use Carbon\Carbon;
use Tests\Feature\FeatureTest;

class OrderApprovalServiceTest extends FeatureTest
{
    private function offlineProviderId(): int
    {
        $provider = PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)->firstOrFail();
        $provider->update(['is_active' => true]);

        return $provider->id;
    }

    private function pendingCashSubscription(): Subscription
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $product = Product::factory()->create(['metadata' => ['audit_diagnostic_credits' => 10]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);

        return Subscription::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'price' => 4900,
            'currency_id' => Currency::where('code', 'USD')->first()->id,
            'status' => SubscriptionStatus::NEW->value,
            'type' => SubscriptionType::LOCALLY_MANAGED,
            'payment_provider_id' => $this->offlineProviderId(),
            'ends_at' => null,
            'base_price_snapshot' => 4900,
            'quota_snapshot' => ['audit_diagnostic_credits' => 10],
        ]);
    }

    private function pendingProductOrder(): Order
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        return Order::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'status' => OrderStatus::PENDING->value,
            'is_local' => true,
            'payment_provider_id' => $this->offlineProviderId(),
            'total_amount' => 9900,
            'base_price_snapshot' => 9900,
        ]);
    }

    public function test_approving_a_product_order_completes_it_and_writes_the_audit_row(): void
    {
        $order = $this->pendingProductOrder();
        $actor = $this->createUser();

        $result = app(OrderApprovalService::class)->approve($order, OrderApprovalActor::PARTNER, $actor, 'Cash received.');

        $this->assertTrue($result);
        $this->assertSame(OrderStatus::SUCCESS->value, $order->fresh()->status);

        $approval = OrderApproval::where('order_id', $order->id)->firstOrFail();
        $this->assertSame(OrderApprovalActor::PARTNER->value, $approval->actor_type);
        $this->assertSame($actor->id, $approval->actor_user_id);
        $this->assertSame(OrderApprovalDecision::APPROVED->value, $approval->decision);
        $this->assertSame('Cash received.', $approval->note);
        $this->assertNotNull($approval->decided_at);
    }

    public function test_a_second_approval_is_a_silent_no_op(): void
    {
        $order = $this->pendingProductOrder();
        $service = app(OrderApprovalService::class);

        $this->assertTrue($service->approve($order, OrderApprovalActor::ADMIN));
        $this->assertFalse($service->approve($order->fresh(), OrderApprovalActor::ADMIN));

        $this->assertSame(1, OrderApproval::where('order_id', $order->id)->count());
        $this->assertSame(OrderStatus::SUCCESS->value, $order->fresh()->status);
    }

    public function test_rejecting_marks_the_order_rejected_not_failed(): void
    {
        $order = $this->pendingProductOrder();

        $this->assertTrue(app(OrderApprovalService::class)->reject($order, OrderApprovalActor::ADMIN, null, 'No cash.'));

        $this->assertSame(OrderStatus::REJECTED->value, $order->fresh()->status);
        $this->assertSame(
            OrderApprovalDecision::REJECTED->value,
            OrderApproval::where('order_id', $order->id)->value('decision'),
        );
    }

    public function test_approving_a_purchase_order_activates_the_subscription_for_one_interval(): void
    {
        $subscription = $this->pendingCashSubscription();
        $order = app(CashSubscriptionService::class)->startPendingCashSubscription($subscription);

        app(OrderApprovalService::class)->approve($order, OrderApprovalActor::PARTNER);

        $subscription->refresh();

        $this->assertSame(SubscriptionStatus::ACTIVE->value, $subscription->status);
        $this->assertSame(
            now()->addMonth()->toDateString(),
            Carbon::parse($subscription->ends_at)->toDateString(),
        );
    }

    public function test_approving_a_renewal_extends_from_the_existing_end_date(): void
    {
        $subscription = $this->pendingCashSubscription();
        $subscription->update([
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(2),
        ]);

        $order = app(CashSubscriptionService::class)->createPendingOrder($subscription->fresh(), OrderType::RENEWAL);

        app(OrderApprovalService::class)->approve($order, OrderApprovalActor::PARTNER);

        $this->assertSame(
            now()->addDays(2)->addMonth()->toDateString(),
            Carbon::parse($subscription->fresh()->ends_at)->toDateString(),
        );
    }

    public function test_rejecting_a_purchase_order_cancels_the_pending_subscription(): void
    {
        $subscription = $this->pendingCashSubscription();
        $order = app(CashSubscriptionService::class)->startPendingCashSubscription($subscription);

        app(OrderApprovalService::class)->reject($order, OrderApprovalActor::PARTNER);

        $subscription->refresh();

        $this->assertSame(SubscriptionStatus::CANCELED->value, $subscription->status);
        $this->assertNotNull($subscription->cancelled_at);
    }

    public function test_rejecting_a_renewal_leaves_the_paid_cycle_intact(): void
    {
        $subscription = $this->pendingCashSubscription();
        $endsAt = now()->addDays(2);
        $subscription->update(['status' => SubscriptionStatus::ACTIVE->value, 'ends_at' => $endsAt]);

        $order = app(CashSubscriptionService::class)->createPendingOrder($subscription->fresh(), OrderType::RENEWAL);

        app(OrderApprovalService::class)->reject($order, OrderApprovalActor::PARTNER);

        $subscription->refresh();

        $this->assertSame(SubscriptionStatus::ACTIVE->value, $subscription->status);
        $this->assertTrue((bool) $subscription->is_canceled_at_end_of_cycle);
        $this->assertSame($endsAt->toDateString(), Carbon::parse($subscription->ends_at)->toDateString());
    }

    public function test_it_recognises_a_pending_cash_order(): void
    {
        $service = app(OrderApprovalService::class);

        $this->assertTrue($service->isPendingCashOrder($this->pendingProductOrder()));

        $comped = $this->pendingProductOrder();
        $comped->update(['status' => OrderStatus::SUCCESS->value]);
        $this->assertFalse($service->isPendingCashOrder($comped->fresh()));

        $gateway = $this->pendingProductOrder();
        $gateway->update(['is_local' => false]);
        $this->assertFalse($service->isPendingCashOrder($gateway->fresh()));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=OrderApprovalServiceTest`
Expected: FAIL — `Target class [App\Services\CashPayments\OrderApprovalService] does not exist`.

- [ ] **Step 3: Write the service**

Create `app/Services/CashPayments/OrderApprovalService.php`:

```php
<?php

namespace App\Services\CashPayments;

use App\Constants\OrderApprovalActor;
use App\Constants\OrderApprovalDecision;
use App\Constants\OrderStatus;
use App\Constants\OrderType;
use App\Constants\SubscriptionStatus;
use App\Models\Order;
use App\Models\OrderApproval;
use App\Models\Subscription;
use App\Models\User;
use App\Services\OrderService;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Every cash decision — partner, admin, or the expiry sweep — goes through here
 * so that exactly one audit row exists per order and a duplicate click can
 * never double-activate a subscription (spec §7.2).
 */
class OrderApprovalService
{
    public function __construct(
        private OrderService $orderService,
        private SubscriptionService $subscriptionService,
    ) {}

    public function isPendingCashOrder(Order $order): bool
    {
        return $order->status === OrderStatus::PENDING->value
            && (bool) $order->is_local
            && $this->amountDue($order) > 0;
    }

    /**
     * total_amount_after_discount is NOT NULL DEFAULT 0, so a null-coalesce
     * would read 0 for every order whose totals were never recalculated. Fall
     * back to total_amount when the discounted total is zero: a genuinely
     * fully-discounted order never reaches PENDING in the first place (a local
     * order with nothing owed is created SUCCESS — see OrderService::create).
     */
    public function amountDue(Order $order): int
    {
        $afterDiscount = (int) ($order->total_amount_after_discount ?? 0);

        return $afterDiscount > 0 ? $afterDiscount : (int) ($order->total_amount ?? 0);
    }

    public function approve(Order $order, OrderApprovalActor $actor, ?User $actingUser = null, ?string $note = null): bool
    {
        return DB::transaction(function () use ($order, $actor, $actingUser, $note): bool {
            $locked = $this->lockIfPending($order);

            if ($locked === null) {
                return false;
            }

            $this->recordDecision($locked, $actor, $actingUser, OrderApprovalDecision::APPROVED, $note);

            /** @var Subscription|null $subscription */
            $subscription = $locked->subscription;

            if ($subscription !== null) {
                $this->extendSubscription($subscription);
            }

            $this->orderService->updateOrder($locked, ['status' => OrderStatus::SUCCESS->value]);

            return true;
        });
    }

    public function reject(Order $order, OrderApprovalActor $actor, ?User $actingUser = null, ?string $note = null): bool
    {
        return DB::transaction(function () use ($order, $actor, $actingUser, $note): bool {
            $locked = $this->lockIfPending($order);

            if ($locked === null) {
                return false;
            }

            $this->recordDecision($locked, $actor, $actingUser, OrderApprovalDecision::REJECTED, $note);

            /** @var Subscription|null $subscription */
            $subscription = $locked->subscription;

            if ($subscription !== null) {
                if ($locked->type === OrderType::RENEWAL->value) {
                    // The cycle the customer already paid for is theirs; the
                    // expiry sweep cancels the subscription once ends_at passes.
                    $this->subscriptionService->updateSubscription($subscription, [
                        'is_canceled_at_end_of_cycle' => true,
                    ]);
                } else {
                    $this->subscriptionService->updateSubscription($subscription, [
                        'status' => SubscriptionStatus::CANCELED->value,
                        'cancelled_at' => now(),
                    ]);
                }
            }

            $this->orderService->updateOrder($locked, ['status' => OrderStatus::REJECTED->value]);

            return true;
        });
    }

    /**
     * Row lock plus a status re-read inside the transaction: two concurrent
     * approvals serialise here, and the loser sees a non-PENDING row.
     */
    private function lockIfPending(Order $order): ?Order
    {
        /** @var Order|null $locked */
        $locked = Order::whereKey($order->getKey())->lockForUpdate()->first();

        if ($locked === null || $locked->status !== OrderStatus::PENDING->value) {
            return null;
        }

        return $locked;
    }

    private function recordDecision(Order $order, OrderApprovalActor $actor, ?User $actingUser, OrderApprovalDecision $decision, ?string $note): void
    {
        OrderApproval::create([
            'order_id' => $order->id,
            'actor_type' => $actor->value,
            'actor_user_id' => $actor === OrderApprovalActor::SYSTEM ? null : $actingUser?->id,
            'decision' => $decision->value,
            'note' => $note,
            'decided_at' => now(),
        ]);
    }

    /**
     * Starts the new cycle at the current ends_at when that is still in the
     * future (an approved renewal), otherwise at now (a first purchase, or a
     * renewal approved after a lapse).
     */
    private function extendSubscription(Subscription $subscription): void
    {
        $currentEnd = $subscription->ends_at !== null ? Carbon::parse($subscription->ends_at) : null;
        $start = ($currentEnd !== null && $currentEnd->isFuture()) ? $currentEnd : now();

        $this->subscriptionService->updateSubscription($subscription, [
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => $start->copy()->add(
                $subscription->interval->date_identifier,
                (int) $subscription->interval_count,
            ),
        ]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=OrderApprovalServiceTest`
Expected: PASS (8 tests).

- [ ] **Step 5: Commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
docker compose exec laravel.test vendor/bin/phpstan analyse
git add app/Services/CashPayments/OrderApprovalService.php tests/Feature/Services/CashPayments/OrderApprovalServiceTest.php
git commit -m "feat(cash): add the idempotent, audited order approval service"
```

---

### Task 11: Partner ownership enforcement

The ownership check lives in the action itself, not only in a list-view scope, so a partner cannot reach another partner's order by guessing a URL (spec §7.4), and a lapsed Partner Plan is re-checked at the moment of the action, not only at page load (§7.5).

**Files:**
- Modify: `app/Constants/TenancyPermissionConstants.php`
- Modify: `database/seeders/RolesAndPermissionsSeeder.php`
- Modify: `app/Services/CashPayments/OrderApprovalService.php`
- Test: `tests/Feature/Services/CashPayments/PartnerOrderOwnershipTest.php`

**Interfaces:**
- Consumes: `PartnerCapabilityService::tenantIsActivePartner(Tenant): bool`, `TenantPermissionService::tenantUserHasPermissionTo(?Tenant, User, string): bool`.
- Produces:
  - `TenancyPermissionConstants::PERMISSION_MANAGE_PARTNER_ORDERS` = `'tenancy: manage partner orders'` (granted to the tenant `admin` role by the seeder).
  - `OrderApprovalService::approveAsPartner(Order $order, User $user, Tenant $actingTenant, ?string $note = null): bool`
  - `OrderApprovalService::rejectAsPartner(Order $order, User $user, Tenant $actingTenant, ?string $note = null): bool`
  - Both throw `Illuminate\Auth\Access\AuthorizationException` when the order is not this tenant's, when the Partner Plan is not currently active, or when the user lacks the permission.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Services/CashPayments/PartnerOrderOwnershipTest.php`:

```php
<?php

namespace Tests\Feature\Services\CashPayments;

use App\Constants\OrderStatus;
use App\Constants\SubscriptionStatus;
use App\Constants\TenancyPermissionConstants;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\CashPayments\OrderApprovalService;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Feature\FeatureTest;

class PartnerOrderOwnershipTest extends FeatureTest
{
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

    private function pendingOrderFor(?Tenant $partnerTenant): Order
    {
        $customerTenant = $this->createTenant();
        $customer = $this->createUser($customerTenant);

        return Order::factory()->create([
            'user_id' => $customer->id,
            'tenant_id' => $customerTenant->id,
            'partner_tenant_id' => $partnerTenant?->id,
            'status' => OrderStatus::PENDING->value,
            'is_local' => true,
            'total_amount' => 6900,
        ]);
    }

    public function test_a_permitted_partner_user_can_approve_their_own_order(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $user = $this->createUser($partnerTenant, [TenancyPermissionConstants::PERMISSION_MANAGE_PARTNER_ORDERS]);
        $order = $this->pendingOrderFor($partnerTenant);

        $this->assertTrue(app(OrderApprovalService::class)->approveAsPartner($order, $user, $partnerTenant));
        $this->assertSame(OrderStatus::SUCCESS->value, $order->fresh()->status);
    }

    public function test_a_partner_cannot_touch_another_partners_order(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $otherPartnerTenant = $this->activePartnerTenant();
        $user = $this->createUser($partnerTenant, [TenancyPermissionConstants::PERMISSION_MANAGE_PARTNER_ORDERS]);
        $order = $this->pendingOrderFor($otherPartnerTenant);

        $this->expectException(AuthorizationException::class);

        app(OrderApprovalService::class)->approveAsPartner($order, $user, $partnerTenant);
    }

    public function test_a_direct_order_with_no_partner_is_not_partner_approvable(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $user = $this->createUser($partnerTenant, [TenancyPermissionConstants::PERMISSION_MANAGE_PARTNER_ORDERS]);
        $order = $this->pendingOrderFor(null);

        $this->expectException(AuthorizationException::class);

        app(OrderApprovalService::class)->approveAsPartner($order, $user, $partnerTenant);
    }

    public function test_a_lapsed_partner_plan_blocks_the_action(): void
    {
        $partnerTenant = $this->createTenant(); // never subscribed to a partner plan
        $user = $this->createUser($partnerTenant, [TenancyPermissionConstants::PERMISSION_MANAGE_PARTNER_ORDERS]);
        $order = $this->pendingOrderFor($partnerTenant);

        $this->expectException(AuthorizationException::class);

        app(OrderApprovalService::class)->rejectAsPartner($order, $user, $partnerTenant);
    }

    public function test_a_tenant_member_without_the_permission_is_blocked(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $user = $this->createUser($partnerTenant, []);
        $order = $this->pendingOrderFor($partnerTenant);

        $this->expectException(AuthorizationException::class);

        app(OrderApprovalService::class)->approveAsPartner($order, $user, $partnerTenant);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=PartnerOrderOwnershipTest`
Expected: FAIL — `undefined constant …PERMISSION_MANAGE_PARTNER_ORDERS`.

- [ ] **Step 3: Add the permission**

In `app/Constants/TenancyPermissionConstants.php`, after `PERMISSION_MANAGE_RESELLER_CATALOG`:

```php
    public const PERMISSION_MANAGE_PARTNER_ORDERS = 'tenancy: manage partner orders';
```

In `database/seeders/RolesAndPermissionsSeeder.php`, add to the `$permissions` array inside `multiTenancyRolesAndPermissions()`, after `PERMISSION_MANAGE_RESELLER_CATALOG`:

```php
            TenancyPermissionConstants::PERMISSION_MANAGE_PARTNER_ORDERS,
```

- [ ] **Step 4: Add the partner-facing wrappers**

In `app/Services/CashPayments/OrderApprovalService.php`, extend the constructor:

```php
    public function __construct(
        private OrderService $orderService,
        private SubscriptionService $subscriptionService,
        private PartnerCapabilityService $capabilityService,
        private TenantPermissionService $permissionService,
    ) {}
```

add the imports:

```php
use App\Constants\TenancyPermissionConstants;
use App\Models\Tenant;
use App\Services\PartnerCapabilityService;
use App\Services\TenantPermissionService;
use Illuminate\Auth\Access\AuthorizationException;
```

and add the wrappers plus the guard:

```php
    public function approveAsPartner(Order $order, User $user, Tenant $actingTenant, ?string $note = null): bool
    {
        $this->assertPartnerMayAct($order, $user, $actingTenant);

        return $this->approve($order, OrderApprovalActor::PARTNER, $user, $note);
    }

    public function rejectAsPartner(Order $order, User $user, Tenant $actingTenant, ?string $note = null): bool
    {
        $this->assertPartnerMayAct($order, $user, $actingTenant);

        return $this->reject($order, OrderApprovalActor::PARTNER, $user, $note);
    }

    /**
     * @throws AuthorizationException
     */
    private function assertPartnerMayAct(Order $order, User $user, Tenant $actingTenant): void
    {
        if ($order->partner_tenant_id === null || (int) $order->partner_tenant_id !== (int) $actingTenant->getKey()) {
            throw new AuthorizationException(__('This order does not belong to your partner account.'));
        }

        // Re-checked at action time, not only at page load: a Partner Plan can
        // lapse between rendering the queue and clicking Approve (spec §7.5).
        if (! $this->capabilityService->tenantIsActivePartner($actingTenant)) {
            throw new AuthorizationException(__('Your partner plan is not active.'));
        }

        if (! $this->permissionService->tenantUserHasPermissionTo(
            $actingTenant,
            $user,
            TenancyPermissionConstants::PERMISSION_MANAGE_PARTNER_ORDERS,
        )) {
            throw new AuthorizationException(__('You do not have permission to approve partner orders.'));
        }
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter="PartnerOrderOwnershipTest|OrderApprovalServiceTest"`
Expected: PASS (13 tests).

- [ ] **Step 6: Commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
docker compose exec laravel.test vendor/bin/phpstan analyse
git add app/Constants/TenancyPermissionConstants.php database/seeders/RolesAndPermissionsSeeder.php app/Services/CashPayments/OrderApprovalService.php tests/Feature/Services/CashPayments/PartnerOrderOwnershipTest.php
git commit -m "feat(cash): enforce partner ownership and live partner status on approvals"
```

---

### Task 12: Partner Order Approvals dashboard resource

**Files:**
- Create: `app/Filament/Dashboard/Resources/PartnerOrderApprovals/PartnerOrderApprovalResource.php`
- Create: `app/Filament/Dashboard/Resources/PartnerOrderApprovals/Pages/ListPartnerOrderApprovals.php`
- Test: `tests/Feature/Filament/Dashboard/PartnerOrderApprovalResourceTest.php`

**Interfaces:**
- Consumes: `OrderApprovalService::approveAsPartner()/rejectAsPartner()`, `PartnerCapabilityService::tenantIsActivePartner()`, `TenantPermissionService`, `TenancyPermissionConstants::PERMISSION_MANAGE_PARTNER_ORDERS`.
- Produces: a tenant-scoped Filament Dashboard resource at slug `partner-order-approvals` listing this partner's `PENDING` orders with `approve` and `reject` record actions.

**Note:** this resource and `Dashboard\Resources\Orders\OrderResource` share the `Order` model, so it **must** declare its own `$slug` — otherwise the two resources collide on route names. It also sets `$isScopedToTenant = false` because the scope is `partner_tenant_id`, not Filament's `tenant_id` (same reasoning as Plan 1's catalog resources).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Filament/Dashboard/PartnerOrderApprovalResourceTest.php`:

```php
<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\OrderStatus;
use App\Constants\SubscriptionStatus;
use App\Constants\TenancyPermissionConstants;
use App\Filament\Dashboard\Resources\PartnerOrderApprovals\Pages\ListPartnerOrderApprovals;
use App\Filament\Dashboard\Resources\PartnerOrderApprovals\PartnerOrderApprovalResource;
use App\Models\Order;
use App\Models\OrderApproval;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class PartnerOrderApprovalResourceTest extends FeatureTest
{
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

    private function pendingOrderFor(Tenant $partnerTenant, string $status = OrderStatus::PENDING->value): Order
    {
        $customerTenant = $this->createTenant();
        $customer = $this->createUser($customerTenant);

        return Order::factory()->create([
            'user_id' => $customer->id,
            'tenant_id' => $customerTenant->id,
            'partner_tenant_id' => $partnerTenant->id,
            'status' => $status,
            'is_local' => true,
            'total_amount' => 6900,
            'base_price_snapshot' => 4900,
        ]);
    }

    public function test_a_non_partner_tenant_cannot_access_the_queue(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, [TenancyPermissionConstants::PERMISSION_MANAGE_PARTNER_ORDERS]);
        $this->actingAs($user);
        Filament::setTenant($tenant);

        $this->assertFalse(PartnerOrderApprovalResource::canAccess());
    }

    public function test_a_partner_tenant_member_without_the_permission_cannot_access_the_queue(): void
    {
        $tenant = $this->activePartnerTenant();
        $user = $this->createUser($tenant, []);
        $this->actingAs($user);
        Filament::setTenant($tenant);

        $this->assertFalse(PartnerOrderApprovalResource::canAccess());
    }

    public function test_a_permitted_partner_can_access_the_queue(): void
    {
        $tenant = $this->activePartnerTenant();
        $user = $this->createUser($tenant, [TenancyPermissionConstants::PERMISSION_MANAGE_PARTNER_ORDERS]);
        $this->actingAs($user);
        Filament::setTenant($tenant);

        $this->assertTrue(PartnerOrderApprovalResource::canAccess());
    }

    public function test_the_queue_lists_only_this_partners_pending_orders(): void
    {
        $tenant = $this->activePartnerTenant();
        $otherTenant = $this->activePartnerTenant();
        $user = $this->createUser($tenant, [TenancyPermissionConstants::PERMISSION_MANAGE_PARTNER_ORDERS]);

        $mine = $this->pendingOrderFor($tenant);
        $alreadyDone = $this->pendingOrderFor($tenant, OrderStatus::SUCCESS->value);
        $theirs = $this->pendingOrderFor($otherTenant);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        $ids = PartnerOrderApprovalResource::getEloquentQuery()->pluck('id')->all();

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($alreadyDone->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    public function test_the_approve_action_completes_the_order_and_logs_the_note(): void
    {
        $tenant = $this->activePartnerTenant();
        $user = $this->createUser($tenant, [TenancyPermissionConstants::PERMISSION_MANAGE_PARTNER_ORDERS]);
        $order = $this->pendingOrderFor($tenant);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::actingAs($user)
            ->test(ListPartnerOrderApprovals::class)
            ->callTableAction('approve', $order, data: ['note' => 'Cash collected on site.'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(OrderStatus::SUCCESS->value, $order->fresh()->status);
        $this->assertSame('Cash collected on site.', OrderApproval::where('order_id', $order->id)->value('note'));
    }

    public function test_the_reject_action_rejects_the_order(): void
    {
        $tenant = $this->activePartnerTenant();
        $user = $this->createUser($tenant, [TenancyPermissionConstants::PERMISSION_MANAGE_PARTNER_ORDERS]);
        $order = $this->pendingOrderFor($tenant);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::actingAs($user)
            ->test(ListPartnerOrderApprovals::class)
            ->callTableAction('reject', $order, data: ['note' => 'Customer never paid.'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(OrderStatus::REJECTED->value, $order->fresh()->status);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=PartnerOrderApprovalResourceTest`
Expected: FAIL — `Class "App\Filament\Dashboard\Resources\PartnerOrderApprovals\PartnerOrderApprovalResource" not found`.

- [ ] **Step 3: Write the resource**

Create `app/Filament/Dashboard/Resources/PartnerOrderApprovals/PartnerOrderApprovalResource.php`:

```php
<?php

namespace App\Filament\Dashboard\Resources\PartnerOrderApprovals;

use App\Constants\OrderStatus;
use App\Constants\TenancyPermissionConstants;
use App\Filament\Dashboard\Resources\PartnerOrderApprovals\Pages\ListPartnerOrderApprovals;
use App\Models\Order;
use App\Services\CashPayments\OrderApprovalService;
use App\Services\CurrencyService;
use App\Services\PartnerCapabilityService;
use App\Services\TenantPermissionService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

class PartnerOrderApprovalResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    /**
     * Distinct slug: the customer-facing OrderResource already owns the Order
     * model in this panel, and two resources on one model collide on routes.
     */
    protected static ?string $slug = 'partner-order-approvals';

    /**
     * Scoped on partner_tenant_id (who sells it), not tenant_id (who bought
     * it), so Filament's automatic tenant scoping must stay out of the way.
     */
    protected static bool $isScopedToTenant = false;

    public static function getModelLabel(): string
    {
        return __('Order Approval');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Order Approvals');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('partner_tenant_id', Filament::getTenant()?->getKey() ?? 0)
            ->where('status', OrderStatus::PENDING->value);
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
                TextColumn::make('base_price_snapshot')
                    ->label(__('Base Price'))
                    ->getStateUsing(fn (Order $record): string => self::formatMoney($record->base_price_snapshot)),
                TextColumn::make('total_amount')
                    ->label(__('Your Price'))
                    ->getStateUsing(fn (Order $record): string => self::formatMoney(self::amountDue($record))),
                TextColumn::make('margin')
                    ->label(__('Margin'))
                    ->getStateUsing(function (Order $record): string {
                        if ($record->base_price_snapshot === null) {
                            return '—';
                        }

                        return self::formatMoney(self::amountDue($record) - (int) $record->base_price_snapshot);
                    }),
                TextColumn::make('quota_snapshot')
                    ->label(__('Quotas'))
                    ->getStateUsing(function (Order $record): string {
                        $quotas = (array) ($record->quota_snapshot ?? []);

                        if ($quotas === []) {
                            return '—';
                        }

                        return collect($quotas)
                            ->map(fn ($value, string $key): string => $key.': '.$value)
                            ->implode(', ');
                    })
                    ->wrap(),
                TextColumn::make('created_at')->label(__('Created At'))->dateTime(config('app.datetime_format'))->sortable(),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label(__('Approve'))
                    ->icon('heroicon-m-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(__('Confirm you have received the cash for this order. This activates the customer immediately.'))
                    ->schema([
                        Textarea::make('note')->label(__('Internal note'))->maxLength(1000),
                    ])
                    ->action(fn (Order $record, array $data) => self::decide($record, $data, approve: true)),
                Action::make('reject')
                    ->label(__('Reject'))
                    ->icon('heroicon-m-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->schema([
                        Textarea::make('note')->label(__('Internal note'))->maxLength(1000),
                    ])
                    ->action(fn (Order $record, array $data) => self::decide($record, $data, approve: false)),
            ])
            ->defaultSort('created_at', 'asc');
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
        // One amount-due rule for the whole feature — see the note on
        // OrderApprovalService::amountDue().
        return app(OrderApprovalService::class)->amountDue($order);
    }

    private static function formatMoney(?int $amount): string
    {
        return money((int) $amount, app(CurrencyService::class)->getCurrency()->code);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPartnerOrderApprovals::route('/'),
        ];
    }
}
```

Create `app/Filament/Dashboard/Resources/PartnerOrderApprovals/Pages/ListPartnerOrderApprovals.php`:

```php
<?php

namespace App\Filament\Dashboard\Resources\PartnerOrderApprovals\Pages;

use App\Filament\Dashboard\Resources\PartnerOrderApprovals\PartnerOrderApprovalResource;
use Filament\Resources\Pages\ListRecords;

class ListPartnerOrderApprovals extends ListRecords
{
    protected static string $resource = PartnerOrderApprovalResource::class;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=PartnerOrderApprovalResourceTest`
Expected: PASS (6 tests).

- [ ] **Step 5: Verify the dashboard panel still boots**

Run: `docker compose exec laravel.test php artisan test --filter="Dashboard"`
Expected: PASS — in particular no route-name collision with the existing dashboard `OrderResource`.

- [ ] **Step 6: Commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
docker compose exec laravel.test vendor/bin/phpstan analyse
git add app/Filament/Dashboard/Resources/PartnerOrderApprovals tests/Feature/Filament/Dashboard/PartnerOrderApprovalResourceTest.php
git commit -m "feat(cash): add the partner order approval queue to the dashboard"
```

---

### Task 13: Admin approve/reject

Direct-customer cash orders have no partner to approve them, and support sometimes has to act on a partner's behalf (spec §7.3). Admin decisions land in the same audit log.

**Files:**
- Modify: `app/Filament/Admin/Resources/Orders/Pages/ViewOrder.php`
- Test: `tests/Feature/Filament/Admin/OrderApprovalActionsTest.php`

**Interfaces:**
- Consumes: `OrderApprovalService::approve()/reject()/isPendingCashOrder()`.
- Produces: header actions `approve_cash_payment` and `reject_cash_payment` on the admin View Order page, visible only for pending cash orders; the legacy free-form `update_order` action is hidden for those same orders so no status change can bypass the audit log.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Filament/Admin/OrderApprovalActionsTest.php`:

```php
<?php

namespace Tests\Feature\Filament\Admin;

use App\Constants\OrderApprovalActor;
use App\Constants\OrderStatus;
use App\Filament\Admin\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use App\Models\OrderApproval;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class OrderApprovalActionsTest extends FeatureTest
{
    private function pendingCashOrder(): Order
    {
        $tenant = $this->createTenant();
        $customer = $this->createUser($tenant);

        return Order::factory()->create([
            'user_id' => $customer->id,
            'tenant_id' => $tenant->id,
            'status' => OrderStatus::PENDING->value,
            'is_local' => true,
            'total_amount' => 4900,
        ]);
    }

    public function test_an_admin_can_approve_a_direct_cash_order(): void
    {
        $admin = $this->createAdminUser();
        $order = $this->pendingCashOrder();

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($admin)
            ->test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('approve_cash_payment', ['note' => 'Bank transfer cleared.']);

        $this->assertSame(OrderStatus::SUCCESS->value, $order->fresh()->status);
        $this->assertSame(
            OrderApprovalActor::ADMIN->value,
            OrderApproval::where('order_id', $order->id)->value('actor_type'),
        );
    }

    public function test_an_admin_can_reject_a_direct_cash_order(): void
    {
        $admin = $this->createAdminUser();
        $order = $this->pendingCashOrder();

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($admin)
            ->test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('reject_cash_payment', ['note' => 'Never paid.']);

        $this->assertSame(OrderStatus::REJECTED->value, $order->fresh()->status);
    }

    public function test_the_free_form_status_action_is_hidden_for_pending_cash_orders(): void
    {
        $admin = $this->createAdminUser();
        $order = $this->pendingCashOrder();

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($admin)
            ->test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionHidden('update_order')
            ->assertActionVisible('approve_cash_payment');
    }

    public function test_the_cash_actions_are_hidden_for_a_completed_order(): void
    {
        $admin = $this->createAdminUser();
        $order = $this->pendingCashOrder();
        $order->update(['status' => OrderStatus::SUCCESS->value]);

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::actingAs($admin)
            ->test(ViewOrder::class, ['record' => $order->fresh()->getRouteKey()])
            ->assertActionHidden('approve_cash_payment')
            ->assertActionHidden('reject_cash_payment');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=OrderApprovalActionsTest`
Expected: FAIL — no action named `approve_cash_payment` exists.

- [ ] **Step 3: Add the admin actions**

In `app/Filament/Admin/Resources/Orders/Pages/ViewOrder.php`, add the imports:

```php
use App\Constants\OrderApprovalActor;
use App\Services\CashPayments\OrderApprovalService;
use Filament\Forms\Components\Textarea;
```

Add the two actions at the top of the array returned by `getHeaderActions()`:

```php
            Action::make('approve_cash_payment')
                ->label(__('Approve Cash Payment'))
                ->icon('heroicon-m-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->schema([
                    Textarea::make('note')->label(__('Internal note'))->maxLength(1000),
                ])
                ->visible(fn (Order $record, OrderApprovalService $service): bool => $service->isPendingCashOrder($record))
                ->action(function (Order $record, array $data, OrderApprovalService $service) {
                    $changed = $service->approve($record, OrderApprovalActor::ADMIN, auth()->user(), $data['note'] ?? null);

                    if ($changed) {
                        Notification::make()->success()->title(__('Order approved.'))->send();

                        return;
                    }

                    Notification::make()->warning()->title(__('This order is no longer pending.'))->send();
                }),
            Action::make('reject_cash_payment')
                ->label(__('Reject Cash Payment'))
                ->icon('heroicon-m-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->schema([
                    Textarea::make('note')->label(__('Internal note'))->maxLength(1000),
                ])
                ->visible(fn (Order $record, OrderApprovalService $service): bool => $service->isPendingCashOrder($record))
                ->action(function (Order $record, array $data, OrderApprovalService $service) {
                    $changed = $service->reject($record, OrderApprovalActor::ADMIN, auth()->user(), $data['note'] ?? null);

                    if ($changed) {
                        Notification::make()->success()->title(__('Order rejected.'))->send();

                        return;
                    }

                    Notification::make()->warning()->title(__('This order is no longer pending.'))->send();
                }),
```

Change the existing `update_order` action's `visible()` closure so a pending cash order can only move through the audited path:

```php
                ->visible(fn (Order $record, OrderService $orderService, OrderApprovalService $approvalService): bool => $orderService->canUpdateOrder($record)
                    && ! $approvalService->isPendingCashOrder($record)),
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=OrderApprovalActionsTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
docker compose exec laravel.test vendor/bin/phpstan analyse
git add app/Filament/Admin/Resources/Orders/Pages/ViewOrder.php tests/Feature/Filament/Admin/OrderApprovalActionsTest.php
git commit -m "feat(cash): let admins approve or reject cash orders through the audit log"
```

---
### Task 14: Cash config and the renewal-order command

**Files:**
- Create: `config/cash_payments.php`
- Create: `app/Console/Commands/CashPayments/IssueCashRenewalOrders.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Console/IssueCashRenewalOrdersTest.php`

**Interfaces:**
- Consumes: `CashSubscriptionService::createPendingOrder(Subscription, OrderType): Order`.
- Produces: `config('cash_payments.pending_ttl_hours')` (default 72) and `config('cash_payments.renewal_lead_hours')` (default 72); the `app:issue-cash-renewal-orders` command, scheduled hourly.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Console/IssueCashRenewalOrdersTest.php`:

```php
<?php

namespace Tests\Feature\Console;

use App\Constants\OrderStatus;
use App\Constants\OrderType;
use App\Constants\PaymentProviderConstants;
use App\Constants\SubscriptionStatus;
use App\Constants\SubscriptionType;
use App\Models\Currency;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use Tests\Feature\FeatureTest;

class IssueCashRenewalOrdersTest extends FeatureTest
{
    private function cashSubscription(array $attributes = []): Subscription
    {
        $provider = PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)->firstOrFail();
        $provider->update(['is_active' => true]);

        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $product = Product::factory()->create(['metadata' => ['audit_diagnostic_credits' => 10]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);

        return Subscription::factory()->create(array_merge([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'price' => 4900,
            'currency_id' => Currency::where('code', 'USD')->first()->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'type' => SubscriptionType::LOCALLY_MANAGED,
            'payment_provider_id' => $provider->id,
            'ends_at' => now()->addHours(24),
            'base_price_snapshot' => 4900,
            'quota_snapshot' => ['audit_diagnostic_credits' => 25],
        ], $attributes));
    }

    public function test_it_opens_a_renewal_order_inside_the_lead_window(): void
    {
        $subscription = $this->cashSubscription();

        $this->artisan('app:issue-cash-renewal-orders')->assertSuccessful();

        $order = $subscription->orders()->firstOrFail();

        $this->assertSame(OrderType::RENEWAL->value, $order->type);
        $this->assertSame(OrderStatus::PENDING->value, $order->status);
        $this->assertSame(4900, (int) $order->total_amount);
        $this->assertSame(['audit_diagnostic_credits' => 25], $order->quota_snapshot);
    }

    public function test_it_does_not_open_a_second_renewal_order(): void
    {
        $subscription = $this->cashSubscription();

        $this->artisan('app:issue-cash-renewal-orders')->assertSuccessful();
        $this->artisan('app:issue-cash-renewal-orders')->assertSuccessful();

        $this->assertSame(1, $subscription->orders()->count());
    }

    public function test_it_ignores_a_subscription_that_is_not_due_yet(): void
    {
        $subscription = $this->cashSubscription(['ends_at' => now()->addDays(20)]);

        $this->artisan('app:issue-cash-renewal-orders')->assertSuccessful();

        $this->assertSame(0, $subscription->orders()->count());
    }

    public function test_it_ignores_a_subscription_the_customer_already_cancelled(): void
    {
        $subscription = $this->cashSubscription(['is_canceled_at_end_of_cycle' => true]);

        $this->artisan('app:issue-cash-renewal-orders')->assertSuccessful();

        $this->assertSame(0, $subscription->orders()->count());
    }

    public function test_it_ignores_a_free_comped_local_subscription(): void
    {
        $subscription = $this->cashSubscription(['price' => 0]);

        $this->artisan('app:issue-cash-renewal-orders')->assertSuccessful();

        $this->assertSame(0, $subscription->orders()->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=IssueCashRenewalOrdersTest`
Expected: FAIL — `The command "app:issue-cash-renewal-orders" does not exist.`

- [ ] **Step 3: Add the config**

Create `config/cash_payments.php`:

```php
<?php

return [
    // How long a pending cash order may sit unapproved before the system
    // rejects it. Also the grace window a lapsed cash subscription gets
    // between PAST_DUE and CANCELED (spec §6.5, §6.6).
    'pending_ttl_hours' => (int) env('CASH_PENDING_TTL_HOURS', 72),

    // How far ahead of ends_at the next renewal order is opened. Kept equal to
    // the TTL by default so a renewal order's life exactly covers the window
    // between issuance and the end of the cycle it renews.
    'renewal_lead_hours' => (int) env('CASH_RENEWAL_LEAD_HOURS', 72),
];
```

- [ ] **Step 4: Write the command**

Create `app/Console/Commands/CashPayments/IssueCashRenewalOrders.php`:

```php
<?php

namespace App\Console\Commands\CashPayments;

use App\Constants\OrderStatus;
use App\Constants\OrderType;
use App\Constants\PaymentProviderConstants;
use App\Constants\SubscriptionStatus;
use App\Constants\SubscriptionType;
use App\Models\Subscription;
use App\Services\CashPayments\CashSubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class IssueCashRenewalOrders extends Command
{
    protected $signature = 'app:issue-cash-renewal-orders';

    protected $description = 'Open a pending order for each cash subscription approaching the end of its cycle';

    public function __construct(
        private CashSubscriptionService $cashSubscriptionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $leadHours = (int) config('cash_payments.renewal_lead_hours');

        $subscriptions = Subscription::query()
            ->where('type', SubscriptionType::LOCALLY_MANAGED)
            ->where('status', SubscriptionStatus::ACTIVE->value)
            ->where('price', '>', 0)
            ->where('is_canceled_at_end_of_cycle', false)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', now()->addHours($leadHours))
            ->whereHas('paymentProvider', fn (Builder $query) => $query->where('slug', PaymentProviderConstants::OFFLINE_SLUG))
            // One open renewal order at a time. Once approved, ends_at moves
            // beyond the lead window, so the next cycle is picked up naturally.
            ->whereDoesntHave('orders', fn (Builder $query) => $query
                ->where('type', OrderType::RENEWAL->value)
                ->where('status', OrderStatus::PENDING->value))
            ->get();

        foreach ($subscriptions as $subscription) {
            $this->cashSubscriptionService->createPendingOrder($subscription, OrderType::RENEWAL);
        }

        $this->info("Opened {$subscriptions->count()} cash renewal order(s).");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Schedule it**

In `routes/console.php`, after the audit schedule block:

```php
Schedule::command('app:issue-cash-renewal-orders')->hourly()->withoutOverlapping()->onOneServer();
```

- [ ] **Step 6: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=IssueCashRenewalOrdersTest`
Expected: PASS (5 tests).

- [ ] **Step 7: Commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
docker compose exec laravel.test vendor/bin/phpstan analyse
git add config/cash_payments.php app/Console/Commands/CashPayments/IssueCashRenewalOrders.php routes/console.php tests/Feature/Console/IssueCashRenewalOrdersTest.php
git commit -m "feat(cash): open renewal orders for cash subscriptions before each cycle ends"
```

---

### Task 15: The expiry sweep

**Files:**
- Create: `app/Console/Commands/CashPayments/ExpirePendingCashOrders.php`
- Modify: `routes/console.php`
- Modify: `app/Services/SubscriptionService.php`
- Test: `tests/Feature/Console/ExpirePendingCashOrdersTest.php`

**Interfaces:**
- Consumes: `OrderApprovalService::reject(Order, OrderApprovalActor::SYSTEM, null, string)`.
- Produces: `app:expire-pending-cash-orders`, scheduled hourly; `SubscriptionService::cleanupLocalSubscriptionStatuses()` no longer touches cash subscriptions.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Console/ExpirePendingCashOrdersTest.php`:

```php
<?php

namespace Tests\Feature\Console;

use App\Constants\OrderApprovalActor;
use App\Constants\OrderStatus;
use App\Constants\OrderType;
use App\Constants\PaymentProviderConstants;
use App\Constants\SubscriptionStatus;
use App\Constants\SubscriptionType;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OrderApproval;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Services\CashPayments\CashSubscriptionService;
use App\Services\SubscriptionService;
use Tests\Feature\FeatureTest;

class ExpirePendingCashOrdersTest extends FeatureTest
{
    private function offlineProviderId(): int
    {
        $provider = PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)->firstOrFail();
        $provider->update(['is_active' => true]);

        return $provider->id;
    }

    private function cashSubscription(array $attributes = []): Subscription
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $product = Product::factory()->create(['metadata' => ['audit_diagnostic_credits' => 10]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);

        return Subscription::factory()->create(array_merge([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'price' => 4900,
            'currency_id' => Currency::where('code', 'USD')->first()->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'type' => SubscriptionType::LOCALLY_MANAGED,
            'payment_provider_id' => $this->offlineProviderId(),
            'ends_at' => now()->addDays(30),
            'base_price_snapshot' => 4900,
            'quota_snapshot' => ['audit_diagnostic_credits' => 10],
        ], $attributes));
    }

    public function test_a_stale_purchase_order_is_rejected_by_the_system(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $stale = Order::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'status' => OrderStatus::PENDING->value,
            'is_local' => true,
            'total_amount' => 4900,
            'type' => OrderType::PURCHASE->value,
            'created_at' => now()->subHours(100),
        ]);

        $fresh = Order::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'status' => OrderStatus::PENDING->value,
            'is_local' => true,
            'total_amount' => 4900,
            'type' => OrderType::PURCHASE->value,
            'created_at' => now()->subHours(2),
        ]);

        $this->artisan('app:expire-pending-cash-orders')->assertSuccessful();

        $this->assertSame(OrderStatus::REJECTED->value, $stale->fresh()->status);
        $this->assertSame(OrderStatus::PENDING->value, $fresh->fresh()->status);
        $this->assertSame(
            OrderApprovalActor::SYSTEM->value,
            OrderApproval::where('order_id', $stale->id)->value('actor_type'),
        );
    }

    public function test_expiring_a_purchase_order_cancels_its_pending_subscription(): void
    {
        $subscription = $this->cashSubscription([
            'status' => SubscriptionStatus::NEW->value,
            'ends_at' => null,
        ]);

        $order = app(CashSubscriptionService::class)->startPendingCashSubscription($subscription);
        $order->update(['created_at' => now()->subHours(100)]);

        $this->artisan('app:expire-pending-cash-orders')->assertSuccessful();

        $this->assertSame(OrderStatus::REJECTED->value, $order->fresh()->status);
        $this->assertSame(SubscriptionStatus::CANCELED->value, $subscription->fresh()->status);
    }

    public function test_a_cash_subscription_past_its_end_date_goes_past_due(): void
    {
        $subscription = $this->cashSubscription(['ends_at' => now()->subHour()]);

        $this->artisan('app:expire-pending-cash-orders')->assertSuccessful();

        $this->assertSame(SubscriptionStatus::PAST_DUE->value, $subscription->fresh()->status);
    }

    public function test_a_past_due_cash_subscription_is_cancelled_after_the_grace_window(): void
    {
        $subscription = $this->cashSubscription([
            'status' => SubscriptionStatus::PAST_DUE->value,
            'ends_at' => now()->subHours(100),
        ]);

        $renewal = app(CashSubscriptionService::class)->createPendingOrder($subscription, OrderType::RENEWAL);

        $this->artisan('app:expire-pending-cash-orders')->assertSuccessful();

        $this->assertSame(SubscriptionStatus::CANCELED->value, $subscription->fresh()->status);
        $this->assertSame(OrderStatus::REJECTED->value, $renewal->fresh()->status);
    }

    public function test_a_renewal_order_is_not_expired_on_age_alone(): void
    {
        $subscription = $this->cashSubscription(['ends_at' => now()->addDays(10)]);

        $renewal = app(CashSubscriptionService::class)->createPendingOrder($subscription, OrderType::RENEWAL);
        $renewal->update(['created_at' => now()->subHours(100)]);

        $this->artisan('app:expire-pending-cash-orders')->assertSuccessful();

        $this->assertSame(OrderStatus::PENDING->value, $renewal->fresh()->status);
        $this->assertSame(SubscriptionStatus::ACTIVE->value, $subscription->fresh()->status);
    }

    public function test_the_local_cleanup_sweep_leaves_cash_subscriptions_alone(): void
    {
        $cash = $this->cashSubscription(['ends_at' => now()->subHour()]);
        $comped = $this->cashSubscription([
            'price' => 0,
            'payment_provider_id' => null,
            'ends_at' => now()->subHour(),
        ]);

        app(SubscriptionService::class)->cleanupLocalSubscriptionStatuses();

        $this->assertSame(SubscriptionStatus::ACTIVE->value, $cash->fresh()->status);
        $this->assertSame(SubscriptionStatus::INACTIVE->value, $comped->fresh()->status);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=ExpirePendingCashOrdersTest`
Expected: FAIL — `The command "app:expire-pending-cash-orders" does not exist.`

- [ ] **Step 3: Write the command**

Create `app/Console/Commands/CashPayments/ExpirePendingCashOrders.php`:

```php
<?php

namespace App\Console\Commands\CashPayments;

use App\Constants\OrderApprovalActor;
use App\Constants\OrderStatus;
use App\Constants\OrderType;
use App\Constants\PaymentProviderConstants;
use App\Constants\SubscriptionStatus;
use App\Constants\SubscriptionType;
use App\Models\Order;
use App\Models\Subscription;
use App\Services\CashPayments\OrderApprovalService;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * The cash lifecycle's dead-man's switch (spec §6.5, §6.6):
 *
 *  1. a pending PURCHASE order older than the TTL is system-rejected;
 *  2. a cash subscription past ends_at goes PAST_DUE;
 *  3. a PAST_DUE cash subscription more than one TTL past ends_at is cancelled,
 *     along with any renewal order still waiting on it.
 *
 * Renewal orders are aged off ends_at, not created_at: they are opened before
 * the cycle they renew and would otherwise expire before that cycle ends.
 */
class ExpirePendingCashOrders extends Command
{
    protected $signature = 'app:expire-pending-cash-orders';

    protected $description = 'Reject stale pending cash orders and retire the subscriptions behind them';

    public function __construct(
        private OrderApprovalService $approvalService,
        private SubscriptionService $subscriptionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $ttlHours = (int) config('cash_payments.pending_ttl_hours');

        $expired = $this->expireStalePurchaseOrders($ttlHours);
        $pastDue = $this->markLapsedSubscriptionsPastDue();
        $cancelled = $this->cancelAbandonedSubscriptions($ttlHours);

        $this->info("Expired {$expired} purchase order(s), marked {$pastDue} subscription(s) past due, cancelled {$cancelled}.");

        return self::SUCCESS;
    }

    private function expireStalePurchaseOrders(int $ttlHours): int
    {
        $orders = Order::query()
            ->where('status', OrderStatus::PENDING->value)
            ->where('is_local', true)
            ->where('type', OrderType::PURCHASE->value)
            ->where('created_at', '<', now()->subHours($ttlHours))
            ->get();

        foreach ($orders as $order) {
            $this->approvalService->reject(
                $order,
                OrderApprovalActor::SYSTEM,
                null,
                __('Cash payment was not confirmed within :hours hours.', ['hours' => $ttlHours]),
            );
        }

        return $orders->count();
    }

    private function markLapsedSubscriptionsPastDue(): int
    {
        $subscriptions = $this->cashSubscriptions()
            ->where('status', SubscriptionStatus::ACTIVE->value)
            ->where('ends_at', '<', now())
            ->get();

        foreach ($subscriptions as $subscription) {
            $this->subscriptionService->updateSubscription($subscription, [
                'status' => SubscriptionStatus::PAST_DUE->value,
            ]);
        }

        return $subscriptions->count();
    }

    private function cancelAbandonedSubscriptions(int $ttlHours): int
    {
        $subscriptions = $this->cashSubscriptions()
            ->where('status', SubscriptionStatus::PAST_DUE->value)
            ->where('ends_at', '<', now()->subHours($ttlHours))
            ->get();

        foreach ($subscriptions as $subscription) {
            $pendingRenewals = $subscription->orders()
                ->where('type', OrderType::RENEWAL->value)
                ->where('status', OrderStatus::PENDING->value)
                ->get();

            foreach ($pendingRenewals as $renewal) {
                $this->approvalService->reject(
                    $renewal,
                    OrderApprovalActor::SYSTEM,
                    null,
                    __('Renewal was not confirmed within :hours hours of the cycle ending.', ['hours' => $ttlHours]),
                );
            }

            $this->subscriptionService->updateSubscription($subscription->fresh(), [
                'status' => SubscriptionStatus::CANCELED->value,
                'cancelled_at' => now(),
            ]);
        }

        return $subscriptions->count();
    }

    /**
     * @return Builder<Subscription>
     */
    private function cashSubscriptions(): Builder
    {
        return Subscription::query()
            ->where('type', SubscriptionType::LOCALLY_MANAGED)
            ->where('price', '>', 0)
            ->whereNotNull('ends_at')
            ->whereHas('paymentProvider', fn (Builder $query) => $query->where('slug', PaymentProviderConstants::OFFLINE_SLUG));
    }
}
```

- [ ] **Step 4: Keep the generic local sweep off cash subscriptions**

In `app/Services/SubscriptionService.php`, add `use App\Constants\PaymentProviderConstants;` (already imported) and `use Illuminate\Database\Eloquent\Builder;`, then change `cleanupLocalSubscriptionStatuses()`:

```php
    public function cleanupLocalSubscriptionStatuses()
    {
        $subscriptions = Subscription::where('type', SubscriptionType::LOCALLY_MANAGED)
            ->where('status', SubscriptionStatus::ACTIVE->value)
            ->where('ends_at', '<', now())
            // Cash subscriptions have their own PAST_DUE -> CANCELED ladder in
            // app:expire-pending-cash-orders. Flipping them to INACTIVE here
            // would race it and skip the grace window.
            ->whereNot(function (Builder $query) {
                $query->where('price', '>', 0)
                    ->whereHas('paymentProvider', fn (Builder $provider) => $provider->where('slug', PaymentProviderConstants::OFFLINE_SLUG));
            })
            ->get();

        $subscriptions->each(function (Subscription $subscription) {
            $this->updateSubscription($subscription, [
                'status' => SubscriptionStatus::INACTIVE->value,
            ]);
        });
    }
```

- [ ] **Step 5: Schedule it**

In `routes/console.php`, directly after the renewal command:

```php
Schedule::command('app:expire-pending-cash-orders')->hourly()->withoutOverlapping()->onOneServer();
```

- [ ] **Step 6: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=ExpirePendingCashOrdersTest`
Expected: PASS (6 tests).

- [ ] **Step 7: Verify the local-subscription commands still pass**

Run: `docker compose exec laravel.test php artisan test --filter="CleanupLocalSubscription|LocalSubscriptionExpiring|SubscriptionService"`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
docker compose exec laravel.test vendor/bin/phpstan analyse
git add app/Console/Commands/CashPayments/ExpirePendingCashOrders.php app/Services/SubscriptionService.php routes/console.php tests/Feature/Console/ExpirePendingCashOrdersTest.php
git commit -m "feat(cash): expire unconfirmed cash orders and retire lapsed subscriptions"
```

---

### Task 16: Emails for every cash transition

Spec §9, implemented with Mailables through `RenderSafeMailer` — the pattern every other customer email in this codebase already uses (design decision 7).

**Files:**
- Create: `app/Mail/CashPayments/PartnerNewPendingOrder.php`
- Create: `app/Mail/CashPayments/CustomerOrderApproved.php`
- Create: `app/Mail/CashPayments/CustomerOrderRejected.php`
- Create: `app/Mail/CashPayments/CustomerOrderExpired.php`
- Create: `resources/views/emails/cash-payments/partner-new-pending-order.blade.php`
- Create: `resources/views/emails/cash-payments/customer-order-approved.blade.php`
- Create: `resources/views/emails/cash-payments/customer-order-rejected.blade.php`
- Create: `resources/views/emails/cash-payments/customer-order-expired.blade.php`
- Create: `app/Listeners/Order/NotifyPartnerOfPendingCashOrder.php`
- Modify: `app/Services/CashPayments/OrderApprovalService.php`
- Test: `tests/Feature/Mail/CashPaymentMailTest.php`

**Interfaces:**
- Consumes: `RenderSafeMailer::send(Mailable, string $recipient): void`, `TenantPermissionService::tenantUserHasPermissionTo()`, the existing `App\Events\Order\OrderedOffline` event.
- Produces: one mail per transition — partner gets `PartnerNewPendingOrder` when a cash order opens; the customer gets `CustomerOrderApproved` on approval, `CustomerOrderRejected` on a human rejection, and `CustomerOrderExpired` when the actor is `SYSTEM`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Mail/CashPaymentMailTest.php`:

```php
<?php

namespace Tests\Feature\Mail;

use App\Constants\OrderApprovalActor;
use App\Constants\OrderStatus;
use App\Constants\SubscriptionStatus;
use App\Constants\TenancyPermissionConstants;
use App\Mail\CashPayments\CustomerOrderApproved;
use App\Mail\CashPayments\CustomerOrderExpired;
use App\Mail\CashPayments\CustomerOrderRejected;
use App\Mail\CashPayments\PartnerNewPendingOrder;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CashPayments\OrderApprovalService;
use App\Services\OrderService;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\FeatureTest;

class CashPaymentMailTest extends FeatureTest
{
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

    private function pendingCashOrder(?Tenant $partnerTenant = null): Order
    {
        $tenant = $this->createTenant();
        $customer = $this->createUser($tenant);

        return Order::factory()->create([
            'user_id' => $customer->id,
            'tenant_id' => $tenant->id,
            'partner_tenant_id' => $partnerTenant?->id,
            'status' => OrderStatus::PENDING->value,
            'is_local' => true,
            'total_amount' => 6900,
            'base_price_snapshot' => 4900,
            'currency_id' => Currency::where('code', 'USD')->first()->id,
        ]);
    }

    public function test_the_partner_is_told_when_a_cash_order_opens(): void
    {
        Mail::fake();

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

        Mail::assertSent(PartnerNewPendingOrder::class, fn ($mail) => $mail->hasTo($partnerUser->email));
        Mail::assertNotSent(PartnerNewPendingOrder::class, fn ($mail) => $mail->hasTo($bystander->email));
    }

    public function test_no_partner_mail_for_a_direct_cash_order(): void
    {
        Mail::fake();

        $customerTenant = $this->createTenant();
        $customer = $this->createUser($customerTenant);

        app(OrderService::class)->create(
            $customer,
            $customerTenant,
            totalAmount: 4900,
            currency: Currency::where('code', 'USD')->first(),
            isLocal: true,
        );

        Mail::assertNotSent(PartnerNewPendingOrder::class);
    }

    public function test_the_customer_is_told_the_outcome(): void
    {
        Mail::fake();

        $service = app(OrderApprovalService::class);

        $approved = $this->pendingCashOrder();
        $service->approve($approved, OrderApprovalActor::PARTNER, User::factory()->create());
        Mail::assertSent(CustomerOrderApproved::class, fn ($mail) => $mail->hasTo($approved->user->email));

        $rejected = $this->pendingCashOrder();
        $service->reject($rejected, OrderApprovalActor::ADMIN, User::factory()->create());
        Mail::assertSent(CustomerOrderRejected::class, fn ($mail) => $mail->hasTo($rejected->user->email));

        $expired = $this->pendingCashOrder();
        $service->reject($expired, OrderApprovalActor::SYSTEM, null, 'Timed out.');
        Mail::assertSent(CustomerOrderExpired::class, fn ($mail) => $mail->hasTo($expired->user->email));
        Mail::assertNotSent(CustomerOrderRejected::class, fn ($mail) => $mail->hasTo($expired->user->email));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --filter=CashPaymentMailTest`
Expected: FAIL — `Class "App\Mail\CashPayments\PartnerNewPendingOrder" not found`.

- [ ] **Step 3: Write the four mailables**

Create `app/Mail/CashPayments/PartnerNewPendingOrder.php`:

```php
<?php

namespace App\Mail\CashPayments;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PartnerNewPendingOrder extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Order $order,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('A customer is waiting for you to confirm a cash payment'),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.cash-payments.partner-new-pending-order');
    }
}
```

Create `app/Mail/CashPayments/CustomerOrderApproved.php`:

```php
<?php

namespace App\Mail\CashPayments;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomerOrderApproved extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Order $order,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Your payment was confirmed — your access is active'),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.cash-payments.customer-order-approved');
    }
}
```

Create `app/Mail/CashPayments/CustomerOrderRejected.php`:

```php
<?php

namespace App\Mail\CashPayments;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomerOrderRejected extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Order $order,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Your order could not be confirmed'),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.cash-payments.customer-order-rejected');
    }
}
```

Create `app/Mail/CashPayments/CustomerOrderExpired.php`:

```php
<?php

namespace App\Mail\CashPayments;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CustomerOrderExpired extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Order $order,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Your order expired before payment was confirmed'),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.cash-payments.customer-order-expired');
    }
}
```

- [ ] **Step 4: Write the four views**

Create `resources/views/emails/cash-payments/partner-new-pending-order.blade.php`:

```blade
<x-layouts.email>
    <x-slot name="preview">
        {{ __('A cash order is waiting for your confirmation') }}
    </x-slot>

    <tr>
        <td class="sm-px-6" style="border-radius: 4px; padding: 48px; font-size: 16px; color: #334155; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05)" bgcolor="#ffffff">
            <h1 class="sm-leading-8" style="margin: 0 0 24px; font-size: 24px; font-weight: 600; color: #000">
                {{ __('Hello,') }}
            </h1>
            <p style="margin: 0; line-height: 24px">
                {{ __('One of your customers has placed a cash order at :app.', ['app' => config('app.name')]) }}
                <br><br>
                {{ __('Order number:') }} {{ $order->uuid }}<br>
                {{ __('Customer:') }} {{ $order->user?->email }}<br>
                {{ __('Amount:') }} {{ money((int) ($order->total_amount_after_discount ?? $order->total_amount), $order->currency?->code ?? config('app.default_currency')) }}
                <br><br>
                {{ __('Approve it in your dashboard once you have received the cash.') }}
            </p>
        </td>
    </tr>
</x-layouts.email>
```

Create `resources/views/emails/cash-payments/customer-order-approved.blade.php`:

```blade
<x-layouts.email>
    <x-slot name="preview">
        {{ __('Your payment was confirmed') }}
    </x-slot>

    <tr>
        <td class="sm-px-6" style="border-radius: 4px; padding: 48px; font-size: 16px; color: #334155; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05)" bgcolor="#ffffff">
            <h1 class="sm-leading-8" style="margin: 0 0 24px; font-size: 24px; font-weight: 600; color: #000">
                {{ __('Hello,') }}
            </h1>
            <p style="margin: 0; line-height: 24px">
                {{ __('Your payment for order :uuid has been confirmed and your access is now active.', ['uuid' => $order->uuid]) }}
            </p>
        </td>
    </tr>
</x-layouts.email>
```

Create `resources/views/emails/cash-payments/customer-order-rejected.blade.php` with the same structure and the paragraph:

```blade
                {{ __('Your order :uuid could not be confirmed and has been cancelled. If you believe this is a mistake, reply to this email.', ['uuid' => $order->uuid]) }}
```

Create `resources/views/emails/cash-payments/customer-order-expired.blade.php` with the same structure and the paragraph:

```blade
                {{ __('Your order :uuid expired because payment was not confirmed in time. You can place it again at any point.', ['uuid' => $order->uuid]) }}
```

- [ ] **Step 5: Write the partner listener**

Create `app/Listeners/Order/NotifyPartnerOfPendingCashOrder.php`:

```php
<?php

namespace App\Listeners\Order;

use App\Constants\TenancyPermissionConstants;
use App\Events\Order\OrderedOffline;
use App\Mail\CashPayments\PartnerNewPendingOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Mail\RenderSafeMailer;
use App\Services\TenantPermissionService;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * OrderedOffline fires on every path that puts a local Offline order into
 * PENDING — product checkout, a cash subscription starting, and a renewal
 * order — so one listener covers all three (design decision 8).
 */
class NotifyPartnerOfPendingCashOrder implements ShouldQueue
{
    public function __construct(
        private RenderSafeMailer $mailer,
        private TenantPermissionService $permissionService,
    ) {}

    public function handle(OrderedOffline $event): void
    {
        /** @var Tenant|null $partnerTenant */
        $partnerTenant = $event->order->partnerTenant;

        if ($partnerTenant === null) {
            return; // A direct sale: an admin approves it, no partner to tell.
        }

        $recipients = $partnerTenant->users->filter(fn (User $user): bool => $this->permissionService->tenantUserHasPermissionTo(
            $partnerTenant,
            $user,
            TenancyPermissionConstants::PERMISSION_MANAGE_PARTNER_ORDERS,
        ));

        foreach ($recipients as $recipient) {
            $this->mailer->send(new PartnerNewPendingOrder($event->order), $recipient->email);
        }
    }
}
```

- [ ] **Step 6: Send the customer mail from the approval service**

In `app/Services/CashPayments/OrderApprovalService.php`, add the constructor dependency `private RenderSafeMailer $mailer,` plus the imports:

```php
use App\Mail\CashPayments\CustomerOrderApproved;
use App\Mail\CashPayments\CustomerOrderExpired;
use App\Mail\CashPayments\CustomerOrderRejected;
use App\Services\Mail\RenderSafeMailer;
```

Restructure `approve()` and `reject()` so the mail is sent **after** the transaction commits — a rolled-back transition must not email anyone:

```php
    public function approve(Order $order, OrderApprovalActor $actor, ?User $actingUser = null, ?string $note = null): bool
    {
        $changed = DB::transaction(function () use ($order, $actor, $actingUser, $note): bool {
            // ... unchanged body ...
        });

        if ($changed) {
            $this->notifyCustomer($order->fresh(), OrderApprovalDecision::APPROVED, $actor);
        }

        return $changed;
    }
```

(and the same `$changed` / `notifyCustomer($order->fresh(), OrderApprovalDecision::REJECTED, $actor)` wrapping for `reject()`), then add:

```php
    private function notifyCustomer(Order $order, OrderApprovalDecision $decision, OrderApprovalActor $actor): void
    {
        $email = $order->user?->email;

        if ($email === null) {
            return;
        }

        $mailable = match (true) {
            $decision === OrderApprovalDecision::APPROVED => new CustomerOrderApproved($order),
            $actor === OrderApprovalActor::SYSTEM => new CustomerOrderExpired($order),
            default => new CustomerOrderRejected($order),
        };

        $this->mailer->send($mailable, $email);
    }
```

- [ ] **Step 7: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --filter=CashPaymentMailTest`
Expected: PASS (3 tests). Note `RenderSafeMailer` renders each mailable for real before sending, so a broken blade fails the test rather than silently sending nothing.

- [ ] **Step 8: Verify the approval suites still pass**

Run: `docker compose exec laravel.test php artisan test --filter="OrderApprovalServiceTest|PartnerOrderOwnershipTest|ExpirePendingCashOrdersTest|CashOrderCreationTest"`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
docker compose exec laravel.test vendor/bin/pint --format agent
docker compose exec laravel.test vendor/bin/phpstan analyse
git add app/Mail/CashPayments resources/views/emails/cash-payments app/Listeners/Order/NotifyPartnerOfPendingCashOrder.php app/Services/CashPayments/OrderApprovalService.php tests/Feature/Mail/CashPaymentMailTest.php
git commit -m "feat(cash): email the partner and the customer on every cash transition"
```

---

### Task 17: Full regression pass

**Files:** none (verification only).

- [ ] **Step 1: Run the full backend test suite**

Run: `docker compose exec laravel.test php artisan test --compact`
Expected: all tests pass except any pre-existing, already-known flake unrelated to this plan (`MetricServiceTest`/`SubscriptionCheckoutFormTest` test-isolation flakes have appeared in prior work on this codebase). Confirm any failure is pre-existing by checking it fails identically on the merge base, not introduced here.

- [ ] **Step 2: Run static analysis and formatting gates**

```bash
docker compose exec laravel.test vendor/bin/phpstan analyse
docker compose exec laravel.test vendor/bin/pint --test
```

Expected: both clean. (`pint --test` over ~1000 files takes well over a minute — let it finish rather than assuming a timeout means failure.)

- [ ] **Step 3: Confirm the two new commands are scheduled**

```bash
docker compose exec laravel.test php artisan schedule:list
```

Expected: `app:issue-cash-renewal-orders` and `app:expire-pending-cash-orders` both listed, hourly.

- [ ] **Step 4: Commit if anything was fixed during this pass**

If Steps 1–3 required fixes, stage and commit them with a message describing the regression fixed. If everything was already clean, no commit is needed.

---

## Spec coverage

| Spec section | Covered by |
| --- | --- |
| §6.1 Offline provider pending mode, gated on amount due | Tasks 6, 8 |
| §6.2 Snapshot fields on `Order` and `Subscription` | Tasks 2, 3, 5, 6, 7, 8 |
| §6.3 Entitlement reads prefer the snapshot | Task 9 |
| §6.4 `OrderStatus::REJECTED`; rejected/expired subscriptions reuse `CANCELED` | Tasks 1, 10, 15 |
| §6.5 `pending_ttl_hours` + `app:expire-pending-cash-orders` + system audit rows | Tasks 14, 15 |
| §6.6 Cash renewal cycle, values copied from the subscription, `PAST_DUE` then `CANCELED` | Tasks 8, 10, 14, 15 |
| §7.1 Partner Order Approvals resource with base price, partner price, margin, quotas, notes | Task 12 |
| §7.2 Idempotent, locked, audited transitions with a unique DB backstop | Tasks 4, 10 |
| §7.3 Admin parallel capability writing to the same log | Task 13 |
| §7.4 Ownership enforced in the action, not only the list scope | Task 11 |
| §7.5 Partner Plan lapse re-checked at action time; live orders untouched | Tasks 5, 11 |
| §9 Partner and customer notifications | Task 16 |
| §13 Testing strategy | every task (TDD), Task 17 (gates) |

Deliberately **not** covered here (Plan 3): §8 storefront pricing and checkout re-derivation, §10 reporting columns and the attribution-history tab, §11 dashboard navigation restoration.
