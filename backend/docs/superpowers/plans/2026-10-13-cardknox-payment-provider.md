# Cardknox Payment Provider Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Cardknox (Sola) as a payment provider with full Stripe parity, using app-driven billing on top of the Cardknox card gateway.

**Architecture:** Cardknox only tokenizes cards (iFields → `cc:save` → reusable `xToken`) and executes charges. The app owns billing: `CardknoxPricing` computes amounts, `CardknoxChargeLedger` opens/sends/settles every gateway charge under a unique invoice key, `CardknoxBillingService` decides what to charge (first period, renewals, prorations, orders). `CardknoxProvider` implements `PaymentProviderInterface` as a redirect provider whose redirect target is our own iFields pay page. Webhooks and an hourly reconciliation command only *confirm* charges through the same `settle()` method.

**Tech Stack:** Laravel 13, PHP 8.4, Filament 5, Livewire 4, PHPUnit 11, Laravel HTTP client (`Http::fake`), Spatie Health.

**Spec:** `backend/docs/superpowers/specs/2026-10-13-cardknox-payment-provider-design.md` (read §12 "Revisions" — it supersedes earlier sections).

## Global Constraints

- All commands run from the repo root through Docker: `docker compose exec -T laravel.test <cmd>` (host PHP cannot reach MySQL).
- Tests: PHPUnit classes extending `Tests\Feature\FeatureTest`. The test DB is **migrated once and never rolled back** — every test creates its own uniquely-keyed rows and never asserts on whole-table counts unless it deletes the relevant rows first.
- `FeatureTest` calls `withoutExceptionHandling()`; tests asserting 403/redirect-on-error call `$this->withExceptionHandling()` first.
- Money is integer cents everywhere. Gateway amounts are sent as `number_format($cents / 100, 2, '.', '')`.
- Cardknox subscriptions are `SubscriptionType::PAYMENT_PROVIDER_MANAGED` (never `LOCALLY_MANAGED`).
- Provider slug: `cardknox`. Config namespace: `services.cardknox.*`.
- Only USD is accepted.
- Retry ladder: `config('services.cardknox.retry_days')` = `[1, 3, 5]` days after the period end; the 4th consecutive decline cancels.
- A gateway timeout is `CardknoxOutcome::Unknown` and never changes local state.
- Before committing each task: `docker compose exec -T laravel.test vendor/bin/pint` on touched files, then `git add` **explicit paths only** (other sessions share the working tree).
- Commit messages end with:
  ```
  Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_012hUo4jRthi1knV9fTU5HEL
  ```

## Review Focus

1. **Process dies after the gateway approved but before `settle()`** — the pending transaction must survive (it is committed *before* the HTTP call) and reconciliation must settle it, advancing the period exactly once. Pinned in Task 4 (`test_settle_is_idempotent_and_late_settle_does_not_double_advance`) and Task 11.
2. **Double-submit of the pay form / two renewal runs at once** — exactly one gateway sale per billing attempt. Pinned in Task 4 (`test_open_refuses_while_an_attempt_is_pending_or_succeeded`) and Task 6 (`test_running_twice_charges_once`, `test_existing_pending_charge_blocks_a_new_one`).
3. **One-time purchase with "save card" unchecked must not replace the subscription card on file** — Pinned in Task 8 (`test_order_without_save_card_keeps_existing_card`).
4. **Convert-local-subscription flow with a declined card** — the subscription must stay `LOCALLY_MANAGED` and untouched. Pinned in Task 5 (`test_declined_first_charge_leaves_subscription_unchanged`).
5. **Month-end anchoring** — a subscription ending Jan 31 renews to Feb 28/29, not Mar 3. Pinned in Task 3 (`test_add_interval_does_not_overflow_month_end`).

---

### Task 1: Foundation — constants, config, seeder, schema, models, test helpers

**Files:**
- Modify: `backend/app/Constants/PaymentProviderConstants.php`
- Modify: `backend/config/services.php`
- Modify: `backend/app/Constants/ConfigConstants.php`
- Modify: `backend/.env.example`
- Modify: `backend/database/seeders/PaymentProvidersSeeder.php`
- Create: `backend/public/images/payment-providers/cardknox.png`
- Create: `backend/database/migrations/2026_10_13_000001_create_user_cardknox_data_table.php`
- Create: `backend/database/migrations/2026_10_13_000002_create_cardknox_charges_table.php`
- Create: `backend/app/Models/UserCardknoxData.php`
- Create: `backend/app/Models/CardknoxCharge.php`
- Create: `backend/app/Services/PaymentProviders/Cardknox/CardknoxChargePurpose.php`
- Create: `backend/tests/Support/CardknoxTestHelpers.php`
- Test: `backend/tests/Feature/Services/PaymentProviders/Cardknox/CardknoxFoundationTest.php`

**Interfaces:**
- Produces: `PaymentProviderConstants::CARDKNOX_SLUG = 'cardknox'`; models `UserCardknoxData` (`tenant_id`, `user_id`, `card_token` encrypted, `card_brand`, `card_last4`, `card_exp`) and `CardknoxCharge` (`transaction_id`, `invoice_base`, `invoice` unique, `ref_num`, `purpose`, `period_end` datetime, `meta` array; `transaction(): BelongsTo`); enum `CardknoxChargePurpose` (`First`, `Renewal`, `Proration`, `Order`); trait `Tests\Support\CardknoxTestHelpers` used by every later test.

- [ ] **Step 1: Write the failing test**

`backend/tests/Feature/Services/PaymentProviders/Cardknox/CardknoxFoundationTest.php`:

```php
<?php

namespace Tests\Feature\Services\PaymentProviders\Cardknox;

use App\Constants\PaymentProviderConstants;
use App\Models\CardknoxCharge;
use App\Models\PaymentProvider;
use App\Models\UserCardknoxData;
use Database\Seeders\PaymentProvidersSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTest;
use Tests\Support\CardknoxTestHelpers;

class CardknoxFoundationTest extends FeatureTest
{
    use CardknoxTestHelpers;

    public function test_seeder_registers_cardknox_inactive(): void
    {
        $this->seed(PaymentProvidersSeeder::class);

        $provider = PaymentProvider::where('slug', PaymentProviderConstants::CARDKNOX_SLUG)->firstOrFail();

        $this->assertSame('Cardknox', $provider->name);
    }

    public function test_card_token_is_encrypted_at_rest(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $card = UserCardknoxData::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'card_token' => 'plain-token-123',
            'card_brand' => 'Visa',
            'card_last4' => '1111',
            'card_exp' => '1230',
        ]);

        $raw = DB::table('user_cardknox_data')->where('id', $card->id)->value('card_token');

        $this->assertNotSame('plain-token-123', $raw);
        $this->assertSame('plain-token-123', $card->fresh()->card_token);
    }

    public function test_invoice_is_unique(): void
    {
        $this->configureCardknox();
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan());
        $transaction = $this->pendingTransactionFor($subscription);
        $invoice = 'S'.Str::random(8).'-1';

        CardknoxCharge::create(['transaction_id' => $transaction->id, 'invoice_base' => 'x', 'invoice' => $invoice, 'purpose' => 'renewal']);

        $this->expectException(UniqueConstraintViolationException::class);

        CardknoxCharge::create(['transaction_id' => $transaction->id, 'invoice_base' => 'x', 'invoice' => $invoice, 'purpose' => 'renewal']);
    }
}
```

- [ ] **Step 2: Write the shared test helper trait**

`backend/tests/Support/CardknoxTestHelpers.php`:

```php
<?php

namespace Tests\Support;

use App\Constants\PaymentProviderConstants;
use App\Constants\PlanType;
use App\Constants\SubscriptionStatus;
use App\Constants\SubscriptionType;
use App\Constants\TransactionStatus;
use App\Models\Currency;
use App\Models\Interval;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\UserCardknoxData;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Shared fixtures for Cardknox tests. FeatureTest never rolls back, so every
 * helper creates uniquely-keyed rows, and tearDownCardknoxTestHelpers() (called
 * by Laravel's trait lifecycle) puts the provider back to inactive so other
 * suites' checkout pages are unaffected.
 */
trait CardknoxTestHelpers
{
    protected function tearDownCardknoxTestHelpers(): void
    {
        PaymentProvider::where('slug', PaymentProviderConstants::CARDKNOX_SLUG)
            ->update(['is_active' => false, 'is_enabled_for_new_payments' => false]);
    }

    protected function configureCardknox(): void
    {
        config([
            'services.cardknox.transaction_key' => 'test_transaction_key',
            'services.cardknox.ifields_key' => 'ifields_test_key',
            'services.cardknox.webhook_pin' => 'abcdefghijklmnop1',
            'services.cardknox.gateway_url' => 'https://x1.cardknox.com',
            'services.cardknox.retry_days' => [1, 3, 5],
        ]);

        PaymentProvider::firstOrCreate(
            ['slug' => PaymentProviderConstants::CARDKNOX_SLUG],
            ['name' => 'Cardknox', 'type' => 'multi', 'sort' => 7],
        )->update(['is_active' => true, 'is_enabled_for_new_payments' => true]);
    }

    protected function cardknoxProviderModel(): PaymentProvider
    {
        return PaymentProvider::where('slug', PaymentProviderConstants::CARDKNOX_SLUG)->firstOrFail();
    }

    protected function gatewayApproves(array $overrides = []): array
    {
        return array_merge([
            'xResult' => 'A',
            'xStatus' => 'Approved',
            'xRefNum' => (string) random_int(100000000, 999999999),
            'xToken' => 'tok_'.Str::random(12),
            'xMaskedCardNumber' => '4xxxxxxxxxxx1111',
            'xCardType' => 'Visa',
            'xExp' => '1230',
            'xError' => '',
        ], $overrides);
    }

    protected function gatewayDeclines(string $error = 'Insufficient funds'): array
    {
        return [
            'xResult' => 'D',
            'xStatus' => 'Declined',
            'xRefNum' => (string) random_int(100000000, 999999999),
            'xError' => $error,
        ];
    }

    /** Queue gateway responses in order; any extra request fails the test loudly. */
    protected function fakeGateway(array ...$responses): void
    {
        $sequence = Http::sequence();

        foreach ($responses as $response) {
            $sequence->push($response);
        }

        $sequence->whenEmpty(Http::response(['unexpected' => 'extra gateway call'], 500));

        Http::fake(['x1.cardknox.com/*' => $sequence]);
    }

    protected function cardknoxPlan(array $plan = [], array $price = []): Plan
    {
        $model = Plan::factory()->create(array_merge([
            'slug' => 'ckx-'.Str::random(10),
            'type' => PlanType::FLAT_RATE->value,
            'interval_id' => Interval::where('slug', 'month')->firstOrFail()->id,
            'interval_count' => 1,
            'has_trial' => false,
            'is_active' => true,
        ], $plan));

        PlanPrice::factory()->create(array_merge([
            'plan_id' => $model->id,
            'currency_id' => Currency::where('code', 'USD')->firstOrFail()->id,
            'price' => 2000,
            'setup_fee' => 0,
        ], $price));

        return $model;
    }

    /** A Cardknox subscription with a card on file, due by default in 30 days. */
    protected function cardknoxSubscription(Plan $plan, array $attributes = [], bool $withCard = true): Subscription
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);

        $subscription = Subscription::factory()->create(array_merge([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'price' => $plan->prices()->first()->price,
            'interval_id' => $plan->interval_id,
            'interval_count' => $plan->interval_count,
            'quantity' => 1,
            'status' => SubscriptionStatus::ACTIVE->value,
            'type' => SubscriptionType::PAYMENT_PROVIDER_MANAGED,
            'payment_provider_id' => $this->cardknoxProviderModel()->id,
            'payment_provider_subscription_id' => 'ckx_sub_'.Str::uuid(),
            'ends_at' => now()->addDays(30),
            'extra_payment_provider_data' => [],
        ], $attributes));

        if ($withCard) {
            UserCardknoxData::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'card_token' => 'tok_on_file_'.Str::random(8),
                'card_brand' => 'Visa',
                'card_last4' => '1111',
                'card_exp' => '1230',
            ]);
        }

        return $subscription->fresh();
    }

    protected function pendingTransactionFor(Subscription $subscription): Transaction
    {
        return Transaction::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $subscription->user_id,
            'tenant_id' => $subscription->tenant_id,
            'subscription_id' => $subscription->id,
            'currency_id' => $subscription->currency_id,
            'amount' => 2000,
            'total_tax' => 0,
            'total_discount' => 0,
            'total_fees' => 0,
            'status' => TransactionStatus::PENDING->value,
            'payment_provider_id' => $this->cardknoxProviderModel()->id,
            'payment_provider_status' => 'pending',
            'payment_provider_transaction_id' => 'ckx-'.Str::random(10),
        ]);
    }
}
```

Note: `configureCardknox()` uses `firstOrCreate` so the helpers work regardless of whether the seeder row exists yet. Any test that calls `cardknoxSubscription()` must call `configureCardknox()` first.

- [ ] **Step 3: Run test to verify it fails**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=CardknoxFoundationTest`
Expected: FAIL — `Class "App\Models\UserCardknoxData" not found` / undefined constant `CARDKNOX_SLUG`.

- [ ] **Step 4: Add the constant, config, env and config-override keys**

`backend/app/Constants/PaymentProviderConstants.php` — add after `POLAR_SLUG`:

```php
    public const CARDKNOX_SLUG = 'cardknox';
```

`backend/config/services.php` — add a block after the `'polar'` block:

```php
    'cardknox' => [
        'transaction_key' => env('CARDKNOX_TRANSACTION_KEY'),
        'ifields_key' => env('CARDKNOX_IFIELDS_KEY'),
        'webhook_pin' => env('CARDKNOX_WEBHOOK_PIN'),
        'gateway_url' => env('CARDKNOX_GATEWAY_URL', 'https://x1.cardknox.com'),
        // Pinned iFields release; confirm the current one in the sandbox pass (Task 14).
        'ifields_version' => env('CARDKNOX_IFIELDS_VERSION', '2.15.2401.3101'),
        // Days after the period end at which a declined renewal is retried.
        'retry_days' => [1, 3, 5],
    ],
```

`backend/app/Constants/ConfigConstants.php` — append to `ENCRYPTED_CONFIGS` (after `'services.polar.webhook_secret',`):

```php
        'services.cardknox.transaction_key',
        'services.cardknox.webhook_pin',
```

and to `OVERRIDABLE_CONFIGS` (after `'services.polar.is_sandbox',`):

```php
        'services.cardknox.transaction_key',
        'services.cardknox.ifields_key',
        'services.cardknox.webhook_pin',
```

`backend/.env.example` — add after the Paddle block:

```
CARDKNOX_TRANSACTION_KEY=""
CARDKNOX_IFIELDS_KEY=""
CARDKNOX_WEBHOOK_PIN=""
```

- [ ] **Step 5: Seeder row and logo**

`backend/database/seeders/PaymentProvidersSeeder.php` — add to `$providers` after the Offline entry:

```php
            [
                'name' => 'Cardknox',
                'slug' => PaymentProviderConstants::CARDKNOX_SLUG,
                'type' => 'multi',
                'is_active' => false,
                'sort' => 7,
            ],
```

(Deploy runs `artisan:db:seed` after migrate — `deploy.php:166` — and the seeder is `firstOrCreate`, so production gets the row on the next deploy.)

Logo (the admin table renders `images/payment-providers/{slug}.png`):

Run:
```bash
docker compose exec -T laravel.test php -r '$i=imagecreatetruecolor(240,64);$bg=imagecolorallocate($i,255,255,255);$fg=imagecolorallocate($i,17,24,39);imagefill($i,0,0,$bg);imagestring($i,5,72,24,"Cardknox",$fg);imagepng($i,"public/images/payment-providers/cardknox.png");'
```
Expected: file exists (`ls backend/public/images/payment-providers/cardknox.png`). If GD is missing, copy `offline.png` to `cardknox.png` instead; the real logo is swapped in later by the user.

- [ ] **Step 6: Migrations**

`backend/database/migrations/2026_10_13_000001_create_user_cardknox_data_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_cardknox_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Cardknox's reusable xToken (from cc:save). Encrypted cast; never raw card data.
            $table->text('card_token');
            $table->string('card_brand')->nullable();
            $table->string('card_last4', 4)->nullable();
            $table->string('card_exp', 4)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_cardknox_data');
    }
};
```

`backend/database/migrations/2026_10_13_000002_create_cardknox_charges_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cardknox_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            // One billing attempt = one invoice. The unique index is what makes a
            // double charge impossible under concurrent runs.
            $table->string('invoice_base', 40)->index();
            $table->string('invoice', 48)->unique();
            $table->string('ref_num')->nullable()->index();
            $table->string('purpose', 20);
            $table->dateTime('period_end')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cardknox_charges');
    }
};
```

- [ ] **Step 7: Models and purpose enum**

`backend/app/Models/UserCardknoxData.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserCardknoxData extends Model
{
    protected $table = 'user_cardknox_data';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'card_token',
        'card_brand',
        'card_last4',
        'card_exp',
    ];

    protected $hidden = [
        'card_token',
    ];

    protected $casts = [
        'card_token' => 'encrypted',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
```

`backend/app/Models/CardknoxCharge.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CardknoxCharge extends Model
{
    protected $fillable = [
        'transaction_id',
        'invoice_base',
        'invoice',
        'ref_num',
        'purpose',
        'period_end',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'period_end' => 'datetime',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
```

`backend/app/Services/PaymentProviders/Cardknox/CardknoxChargePurpose.php`:

```php
<?php

namespace App\Services\PaymentProviders\Cardknox;

enum CardknoxChargePurpose: string
{
    case First = 'first';
    case Renewal = 'renewal';
    case Proration = 'proration';
    case Order = 'order';
}
```

- [ ] **Step 8: Migrate and run the test**

Run: `docker compose exec -T laravel.test php artisan migrate && docker compose exec -T laravel.test php artisan test --compact --filter=CardknoxFoundationTest`
Expected: 3 passed. (FeatureTest runs `migrate:fresh` once per process, so the new tables exist in the test DB.)

- [ ] **Step 9: Commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint app/Constants/PaymentProviderConstants.php app/Constants/ConfigConstants.php config/services.php database/seeders/PaymentProvidersSeeder.php database/migrations/2026_10_13_000001_create_user_cardknox_data_table.php database/migrations/2026_10_13_000002_create_cardknox_charges_table.php app/Models/UserCardknoxData.php app/Models/CardknoxCharge.php app/Services/PaymentProviders/Cardknox/CardknoxChargePurpose.php tests/Support/CardknoxTestHelpers.php tests/Feature/Services/PaymentProviders/Cardknox/CardknoxFoundationTest.php
git add backend/app/Constants/PaymentProviderConstants.php backend/app/Constants/ConfigConstants.php backend/config/services.php backend/.env.example backend/database/seeders/PaymentProvidersSeeder.php backend/public/images/payment-providers/cardknox.png backend/database/migrations/2026_10_13_000001_create_user_cardknox_data_table.php backend/database/migrations/2026_10_13_000002_create_cardknox_charges_table.php backend/app/Models/UserCardknoxData.php backend/app/Models/CardknoxCharge.php backend/app/Services/PaymentProviders/Cardknox/CardknoxChargePurpose.php backend/tests/Support/CardknoxTestHelpers.php backend/tests/Feature/Services/PaymentProviders/Cardknox/CardknoxFoundationTest.php
git commit -m "feat(payments): Cardknox provider foundation — config, seeder, card and charge tables"
```
(Append the two attribution lines from Global Constraints to every commit message.)

---
### Task 2: Gateway client, result object, configuration

**Files:**
- Create: `backend/app/Services/PaymentProviders/Cardknox/CardknoxConfiguration.php`
- Create: `backend/app/Services/PaymentProviders/Cardknox/CardknoxOutcome.php`
- Create: `backend/app/Services/PaymentProviders/Cardknox/CardknoxResult.php`
- Create: `backend/app/Client/CardknoxClient.php`
- Test: `backend/tests/Feature/Services/PaymentProviders/Cardknox/CardknoxClientTest.php`

**Interfaces:**
- Consumes: `services.cardknox.*` config (Task 1).
- Produces:
  - `CardknoxConfiguration::transactionKey(): ?string`, `ifieldsKey(): ?string`, `webhookPin(): ?string`, `gatewayUrl(): string`, `ifieldsVersion(): string`, `isComplete(): bool`, `static isValidPin(?string $pin): bool`.
  - `enum CardknoxOutcome: string { Approved, Declined, Error, Unknown }`.
  - `CardknoxResult` readonly props `outcome`, `refNum`, `token`, `maskedCard`, `cardType`, `exp`, `error`; `isApproved(): bool`; statics `fromResponse(array $fields)`, `unknown(string $error)`, `error(string $error)`, `approvedWithoutCharge()`, `inFlight()`.
  - `CardknoxClient::save(string $cardSut, string $cvvSut, string $exp): CardknoxResult`, `sale(string $token, int $amountCents, string $invoice, ?string $recurringIndicator = null): CardknoxResult`, `refund(string $refNum, ?int $amountCents = null): CardknoxResult`, `void(string $refNum): CardknoxResult`, `findByInvoice(string $invoice, Carbon $since): ?CardknoxResult` (null = no gateway record; `Unknown` outcome = lookup failed), constant `RECURRING = 'Recurring'`.

- [ ] **Step 1: Write the failing test**

`backend/tests/Feature/Services/PaymentProviders/Cardknox/CardknoxClientTest.php`:

```php
<?php

namespace Tests\Feature\Services\PaymentProviders\Cardknox;

use App\Client\CardknoxClient;
use App\Services\PaymentProviders\Cardknox\CardknoxConfiguration;
use App\Services\PaymentProviders\Cardknox\CardknoxOutcome;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTest;
use Tests\Support\CardknoxTestHelpers;

class CardknoxClientTest extends FeatureTest
{
    use CardknoxTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureCardknox();
    }

    public function test_sale_sends_the_envelope_and_maps_approval(): void
    {
        $this->fakeGateway($this->gatewayApproves(['xRefNum' => '555']));

        $result = app(CardknoxClient::class)->sale('tok_1', 1234, 'S1-20261113-1', CardknoxClient::RECURRING);

        $this->assertSame(CardknoxOutcome::Approved, $result->outcome);
        $this->assertSame('555', $result->refNum);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://x1.cardknox.com/gatewayjson'
                && $request['xKey'] === 'test_transaction_key'
                && $request['xVersion'] === '5.0.0'
                && $request['xCommand'] === 'cc:Sale'
                && $request['xToken'] === 'tok_1'
                && $request['xAmount'] === '12.34'
                && $request['xInvoice'] === 'S1-20261113-1'
                && $request['xRecurringIndicator'] === 'Recurring';
        });
    }

    public function test_customer_initiated_sale_omits_recurring_indicator(): void
    {
        $this->fakeGateway($this->gatewayApproves());

        app(CardknoxClient::class)->sale('tok_1', 100, 'O1-1');

        Http::assertSent(fn (Request $request): bool => ! isset($request['xRecurringIndicator']));
    }

    public function test_decline_and_error_are_mapped(): void
    {
        $this->fakeGateway($this->gatewayDeclines('Do not honor'), ['xResult' => 'E', 'xError' => 'Invalid token']);

        $declined = app(CardknoxClient::class)->sale('tok', 100, 'a-1');
        $error = app(CardknoxClient::class)->sale('tok', 100, 'a-2');

        $this->assertSame(CardknoxOutcome::Declined, $declined->outcome);
        $this->assertSame('Do not honor', $declined->error);
        $this->assertSame(CardknoxOutcome::Error, $error->outcome);
    }

    // One Http::fake() per test: later fakes are appended and the first match wins.
    public function test_timeout_is_unknown_never_declined(): void
    {
        Http::fake(['x1.cardknox.com/*' => Http::failedConnection()]);

        $this->assertSame(CardknoxOutcome::Unknown, app(CardknoxClient::class)->sale('tok', 100, 'a-1')->outcome);
    }

    public function test_server_error_is_unknown_never_declined(): void
    {
        Http::fake(['x1.cardknox.com/*' => Http::response('oops', 502)]);

        $this->assertSame(CardknoxOutcome::Unknown, app(CardknoxClient::class)->sale('tok', 100, 'a-2')->outcome);
    }

    public function test_save_passes_ifields_single_use_tokens(): void
    {
        $this->fakeGateway($this->gatewayApproves(['xToken' => 'reusable_tok']));

        $result = app(CardknoxClient::class)->save('sut_card', 'sut_cvv', '1230');

        $this->assertSame('reusable_tok', $result->token);
        Http::assertSent(fn (Request $request): bool => $request['xCommand'] === 'cc:Save'
            && $request['xCardNum'] === 'sut_card'
            && $request['xCVV'] === 'sut_cvv'
            && $request['xExp'] === '1230');
    }

    public function test_find_by_invoice_distinguishes_missing_from_failed_lookup(): void
    {
        $this->fakeGateway(['xResult' => 'A', 'xReportData' => [
            ['xInvoice' => 'other-1', 'xCommand' => 'cc:Sale', 'xResponseResult' => 'Approved', 'xRefNum' => '1'],
            ['xInvoice' => 'S9-F-1', 'xCommand' => 'cc:Sale', 'xResponseResult' => 'Approved', 'xRefNum' => '2'],
        ]], ['xResult' => 'A', 'xReportData' => []]);

        $found = app(CardknoxClient::class)->findByInvoice('S9-F-1', now()->subHour());
        $missing = app(CardknoxClient::class)->findByInvoice('S9-F-1', now()->subHour());

        $this->assertSame(CardknoxOutcome::Approved, $found->outcome);
        $this->assertSame('2', $found->refNum);
        $this->assertNull($missing);
    }

    public function test_failed_report_lookup_is_unknown(): void
    {
        Http::fake(['x1.cardknox.com/*' => Http::failedConnection()]);

        $this->assertSame(CardknoxOutcome::Unknown, app(CardknoxClient::class)->findByInvoice('S9-F-1', now()->subHour())->outcome);
    }

    public function test_pin_validation_and_completeness(): void
    {
        $this->assertTrue(CardknoxConfiguration::isValidPin('abcdefghijklmnop1'));
        $this->assertFalse(CardknoxConfiguration::isValidPin('short1'));
        $this->assertFalse(CardknoxConfiguration::isValidPin('has-dashes-in-it-123'));
        $this->assertTrue(app(CardknoxConfiguration::class)->isComplete());

        config(['services.cardknox.webhook_pin' => null]);
        $this->assertFalse(app(CardknoxConfiguration::class)->isComplete());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=CardknoxClientTest`
Expected: FAIL — `Class "App\Client\CardknoxClient" not found`.

- [ ] **Step 3: Configuration, outcome, result**

`backend/app/Services/PaymentProviders/Cardknox/CardknoxConfiguration.php`:

```php
<?php

namespace App\Services\PaymentProviders\Cardknox;

class CardknoxConfiguration
{
    public function transactionKey(): ?string
    {
        return $this->value('transaction_key');
    }

    public function ifieldsKey(): ?string
    {
        return $this->value('ifields_key');
    }

    public function webhookPin(): ?string
    {
        return $this->value('webhook_pin');
    }

    public function gatewayUrl(): string
    {
        return rtrim($this->value('gateway_url') ?? 'https://x1.cardknox.com', '/');
    }

    public function ifieldsVersion(): string
    {
        return $this->value('ifields_version') ?? '2.15.2401.3101';
    }

    /**
     * Cardknox cannot be switched on without all three secrets: a missing PIN
     * would leave the webhook endpoint unable to authenticate anything.
     */
    public function isComplete(): bool
    {
        return $this->transactionKey() !== null
            && $this->ifieldsKey() !== null
            && self::isValidPin($this->webhookPin());
    }

    /** Cardknox requires a letters-and-digits PIN of at least 15 characters. */
    public static function isValidPin(?string $pin): bool
    {
        return $pin !== null && preg_match('/^[A-Za-z0-9]{15,}$/', $pin) === 1;
    }

    private function value(string $key): ?string
    {
        $value = config('services.cardknox.'.$key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
```

`backend/app/Services/PaymentProviders/Cardknox/CardknoxOutcome.php`:

```php
<?php

namespace App\Services\PaymentProviders\Cardknox;

enum CardknoxOutcome: string
{
    case Approved = 'approved';
    case Declined = 'declined';
    case Error = 'error';
    // Timeout, network failure, malformed response: the card may or may not
    // have been charged, so nothing local may change until it is confirmed.
    case Unknown = 'unknown';
}
```

`backend/app/Services/PaymentProviders/Cardknox/CardknoxResult.php`:

```php
<?php

namespace App\Services\PaymentProviders\Cardknox;

final class CardknoxResult
{
    public function __construct(
        public readonly CardknoxOutcome $outcome,
        public readonly ?string $refNum = null,
        public readonly ?string $token = null,
        public readonly ?string $maskedCard = null,
        public readonly ?string $cardType = null,
        public readonly ?string $exp = null,
        public readonly ?string $error = null,
    ) {}

    /** Gateway responses and webhook bodies share the same x-field names. */
    public static function fromResponse(array $fields): self
    {
        $outcome = match ($fields['xResult'] ?? null) {
            'A' => CardknoxOutcome::Approved,
            'D' => CardknoxOutcome::Declined,
            'E' => CardknoxOutcome::Error,
            default => CardknoxOutcome::Unknown,
        };

        return new self(
            outcome: $outcome,
            refNum: self::stringOrNull($fields['xRefNum'] ?? null),
            token: self::stringOrNull($fields['xToken'] ?? null),
            maskedCard: self::stringOrNull($fields['xMaskedCardNumber'] ?? null),
            cardType: self::stringOrNull($fields['xCardType'] ?? null),
            exp: self::stringOrNull($fields['xExp'] ?? null),
            error: self::stringOrNull($fields['xError'] ?? null),
        );
    }

    public static function unknown(string $error): self
    {
        return new self(CardknoxOutcome::Unknown, error: $error);
    }

    public static function error(string $error): self
    {
        return new self(CardknoxOutcome::Error, error: $error);
    }

    /** Trials and fully-discounted charges succeed without a gateway call. */
    public static function approvedWithoutCharge(): self
    {
        return new self(CardknoxOutcome::Approved);
    }

    /** An attempt for this item is already pending or has succeeded. */
    public static function inFlight(): self
    {
        return new self(CardknoxOutcome::Unknown, error: __('A payment for this is already in progress.'));
    }

    public function isApproved(): bool
    {
        return $this->outcome === CardknoxOutcome::Approved;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
```

- [ ] **Step 4: The client**

`backend/app/Client/CardknoxClient.php`:

```php
<?php

namespace App\Client;

use App\Services\PaymentProviders\Cardknox\CardknoxConfiguration;
use App\Services\PaymentProviders\Cardknox\CardknoxResult;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper over the Cardknox (Sola) Transaction API JSON endpoint.
 * No business logic: every call returns a CardknoxResult, and anything other
 * than a well-formed gateway answer is CardknoxOutcome::Unknown.
 *
 * Field names not covered by public docs (report rows, xRecurringIndicator
 * values) are confirmed in the sandbox pass — see Task 14.
 */
class CardknoxClient
{
    public const RECURRING = 'Recurring';

    private const VERSION = '5.0.0';

    public function __construct(private CardknoxConfiguration $configuration) {}

    public function save(string $cardSut, string $cvvSut, string $exp): CardknoxResult
    {
        return $this->send([
            'xCommand' => 'cc:Save',
            'xCardNum' => $cardSut,
            'xCVV' => $cvvSut,
            'xExp' => $exp,
        ]);
    }

    public function sale(string $token, int $amountCents, string $invoice, ?string $recurringIndicator = null): CardknoxResult
    {
        $fields = [
            'xCommand' => 'cc:Sale',
            'xToken' => $token,
            'xAmount' => self::formatAmount($amountCents),
            'xInvoice' => $invoice,
        ];

        if ($recurringIndicator !== null) {
            $fields['xRecurringIndicator'] = $recurringIndicator;
        }

        return $this->send($fields);
    }

    public function refund(string $refNum, ?int $amountCents = null): CardknoxResult
    {
        $fields = ['xCommand' => 'cc:Refund', 'xRefNum' => $refNum];

        if ($amountCents !== null) {
            $fields['xAmount'] = self::formatAmount($amountCents);
        }

        return $this->send($fields);
    }

    public function void(string $refNum): CardknoxResult
    {
        return $this->send(['xCommand' => 'cc:Void', 'xRefNum' => $refNum]);
    }

    /**
     * @return CardknoxResult|null null when the gateway has no sale with this
     *                             invoice; an Unknown result when the lookup itself failed
     */
    public function findByInvoice(string $invoice, Carbon $since): ?CardknoxResult
    {
        $body = $this->post([
            'xCommand' => 'report:all',
            'xBeginDate' => $since->format('Y-m-d H:i:s'),
            'xEndDate' => now()->format('Y-m-d H:i:s'),
            'xMaxRecords' => 1000,
        ]);

        if ($body === null) {
            return CardknoxResult::unknown('Report lookup failed');
        }

        foreach ((array) ($body['xReportData'] ?? []) as $row) {
            if (($row['xInvoice'] ?? null) !== $invoice || strtolower((string) ($row['xCommand'] ?? '')) !== 'cc:sale') {
                continue;
            }

            $result = match (strtolower((string) ($row['xResponseResult'] ?? ''))) {
                'approved' => 'A',
                'declined' => 'D',
                'error' => 'E',
                default => null,
            };

            return CardknoxResult::fromResponse(array_merge($row, ['xResult' => $result]));
        }

        return null;
    }

    public static function formatAmount(int $amountCents): string
    {
        return number_format($amountCents / 100, 2, '.', '');
    }

    private function send(array $fields): CardknoxResult
    {
        $body = $this->post($fields);

        return $body === null
            ? CardknoxResult::unknown('No usable gateway response')
            : CardknoxResult::fromResponse($body);
    }

    private function post(array $fields): ?array
    {
        $key = $this->configuration->transactionKey()
            ?? throw new RuntimeException('Cardknox is not configured: missing transaction key.');

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->connectTimeout(10)
                ->timeout(45)
                ->post($this->configuration->gatewayUrl().'/gatewayjson', array_merge([
                    'xKey' => $key,
                    'xVersion' => self::VERSION,
                    'xSoftwareName' => (string) config('app.name'),
                    'xSoftwareVersion' => '1.0',
                ], $fields));
        } catch (ConnectionException) {
            return null;
        }

        $json = $response->json();

        return $response->successful() && is_array($json) ? $json : null;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=CardknoxClientTest`
Expected: 9 passed.

- [ ] **Step 6: Commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint app/Client/CardknoxClient.php app/Services/PaymentProviders/Cardknox tests/Feature/Services/PaymentProviders/Cardknox
git add backend/app/Client/CardknoxClient.php backend/app/Services/PaymentProviders/Cardknox/CardknoxConfiguration.php backend/app/Services/PaymentProviders/Cardknox/CardknoxOutcome.php backend/app/Services/PaymentProviders/Cardknox/CardknoxResult.php backend/tests/Feature/Services/PaymentProviders/Cardknox/CardknoxClientTest.php
git commit -m "feat(payments): Cardknox gateway client with approved/declined/error/unknown outcomes"
```

---
### Task 3: Pricing — period amounts, usage tiers, discounts, credit, proration, interval math

**Files:**
- Create: `backend/app/Services/PaymentProviders/Cardknox/CardknoxAmount.php`
- Create: `backend/app/Services/PaymentProviders/Cardknox/CardknoxPricing.php`
- Test: `backend/tests/Feature/Services/PaymentProviders/Cardknox/CardknoxPricingTest.php`

**Interfaces:**
- Consumes: `Plan`, `PlanPrice`, `Subscription`, `SubscriptionDiscount`, `Discount`, `SubscriptionUsage` (existing models); `PlanPriceType`, `PlanPriceTierConstants`, `DiscountConstants`, `PlanType`.
- Produces (`CardknoxPricing`):
  - `planPrice(Plan $plan, int $currencyId): PlanPrice`
  - `recurringSubtotal(Plan $plan, int $currencyId, int $quantity): int` — flat price, seat price, or the fixed fee of a usage plan; excludes usage.
  - `usageAmount(PlanPrice $price, int $units): int`
  - `discountFor(string $type, int|float|string $amount, int $subtotal): int`
  - `renewalAmount(Subscription $subscription, Carbon $periodStart, Carbon $periodEnd): CardknoxAmount`
  - `prorationAmount(Subscription $subscription, int $oldSubtotal, int $newSubtotal, Carbon $at): int` (signed)
  - `addInterval(Subscription $subscription, Carbon $from): Carbon`, `subInterval(Subscription $subscription, Carbon $from): Carbon` (no month overflow)
- Produces (`CardknoxAmount`): readonly `subtotal`, `usage`, `discount`, `credit`, `total`, `discountIdsUsed` (int[]).
- `extra_payment_provider_data` keys read here: `pending_credit` (int cents), `discount_uses` (`{discount_id: count}`).

- [ ] **Step 1: Write the failing test**

`backend/tests/Feature/Services/PaymentProviders/Cardknox/CardknoxPricingTest.php`:

```php
<?php

namespace Tests\Feature\Services\PaymentProviders\Cardknox;

use App\Constants\DiscountConstants;
use App\Constants\PlanPriceTierConstants as T;
use App\Constants\PlanPriceType;
use App\Constants\PlanType;
use App\Models\Discount;
use App\Models\SubscriptionDiscount;
use App\Models\SubscriptionUsage;
use App\Services\PaymentProviders\Cardknox\CardknoxPricing;
use Carbon\Carbon;
use Tests\Feature\FeatureTest;
use Tests\Support\CardknoxTestHelpers;

class CardknoxPricingTest extends FeatureTest
{
    use CardknoxTestHelpers;

    private CardknoxPricing $pricing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureCardknox();
        $this->pricing = app(CardknoxPricing::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private const TIERS = [
        [T::UNTIL_UNIT => '10', T::PER_UNIT => '100', T::FLAT_FEE => '0'],
        [T::UNTIL_UNIT => '∞', T::PER_UNIT => '50', T::FLAT_FEE => '200'],
    ];

    public function test_flat_rate_subtotal(): void
    {
        $plan = $this->cardknoxPlan(price: ['price' => 2500]);

        $this->assertSame(2500, $this->pricing->recurringSubtotal($plan, $plan->prices()->first()->currency_id, 1));
    }

    public function test_seat_based_subtotals(): void
    {
        $perSeat = $this->cardknoxPlan(['type' => PlanType::SEAT_BASED->value], ['price' => 1000]);
        $included = $this->cardknoxPlan(['type' => PlanType::SEAT_BASED->value], [
            'price' => 5000,
            'type' => PlanPriceType::SEAT_BASED_WITH_INCLUDED_SEATS->value,
            'included_seats' => 3,
            'extra_seat_price' => 700,
        ]);
        $usd = $perSeat->prices()->first()->currency_id;

        $this->assertSame(4000, $this->pricing->recurringSubtotal($perSeat, $usd, 4));
        $this->assertSame(5000, $this->pricing->recurringSubtotal($included, $usd, 2));
        $this->assertSame(5000 + 2 * 700, $this->pricing->recurringSubtotal($included, $usd, 5));
    }

    public function test_usage_per_unit_volume_and_graduated(): void
    {
        $perUnit = $this->cardknoxPlan(['type' => PlanType::USAGE_BASED->value], ['type' => PlanPriceType::USAGE_BASED_PER_UNIT->value, 'price_per_unit' => '25']);
        $volume = $this->cardknoxPlan(['type' => PlanType::USAGE_BASED->value], ['type' => PlanPriceType::USAGE_BASED_TIERED_VOLUME->value, 'tiers' => self::TIERS]);
        $graduated = $this->cardknoxPlan(['type' => PlanType::USAGE_BASED->value], ['type' => PlanPriceType::USAGE_BASED_TIERED_GRADUATED->value, 'tiers' => self::TIERS]);

        $this->assertSame(250, $this->pricing->usageAmount($perUnit->prices()->first(), 10));
        $this->assertSame(800, $this->pricing->usageAmount($volume->prices()->first(), 8));
        $this->assertSame(15 * 50 + 200, $this->pricing->usageAmount($volume->prices()->first(), 15));
        $this->assertSame(10 * 100 + 5 * 50 + 200, $this->pricing->usageAmount($graduated->prices()->first(), 15));
        $this->assertSame(0, $this->pricing->usageAmount($graduated->prices()->first(), 0));
    }

    public function test_renewal_adds_usage_of_the_period_only(): void
    {
        $plan = $this->cardknoxPlan(['type' => PlanType::USAGE_BASED->value], ['price' => 1000, 'type' => PlanPriceType::USAGE_BASED_PER_UNIT->value, 'price_per_unit' => '10']);
        $subscription = $this->cardknoxSubscription($plan);
        $start = now()->subMonth();
        $end = now();

        SubscriptionUsage::create(['subscription_id' => $subscription->id, 'unit_count' => 7])->forceFill(['created_at' => $start->copy()->addDay()])->save();
        SubscriptionUsage::create(['subscription_id' => $subscription->id, 'unit_count' => 99])->forceFill(['created_at' => $start->copy()->subDay()])->save();

        $amount = $this->pricing->renewalAmount($subscription, $start, $end);

        $this->assertSame(1000, $amount->subtotal);
        $this->assertSame(70, $amount->usage);
        $this->assertSame(1070, $amount->total);
    }

    public function test_discount_rules(): void
    {
        $plan = $this->cardknoxPlan(price: ['price' => 2000]);
        $subscription = $this->cardknoxSubscription($plan);
        $percent = Discount::create(['name' => 'p', 'type' => DiscountConstants::TYPE_PERCENTAGE, 'amount' => 25, 'is_active' => true, 'is_recurring' => true, 'maximum_recurring_intervals' => 2]);
        $once = Discount::create(['name' => 'o', 'type' => DiscountConstants::TYPE_FIXED, 'amount' => 300, 'is_active' => true, 'is_recurring' => false]);

        foreach ([$percent, $once] as $discount) {
            SubscriptionDiscount::create(['subscription_id' => $subscription->id, 'discount_id' => $discount->id, 'type' => $discount->type, 'amount' => $discount->amount, 'is_recurring' => $discount->is_recurring]);
        }

        $first = $this->pricing->renewalAmount($subscription, now()->subMonth(), now());
        $this->assertSame(500 + 300, $first->discount);
        $this->assertEqualsCanonicalizing([$percent->id, $once->id], $first->discountIdsUsed);

        $subscription->update(['extra_payment_provider_data' => ['discount_uses' => [$percent->id => 1, $once->id => 1]]]);
        $second = $this->pricing->renewalAmount($subscription->fresh(), now()->subMonth(), now());
        $this->assertSame(500, $second->discount, 'one-time discount is spent, recurring one has one interval left');

        $subscription->update(['extra_payment_provider_data' => ['discount_uses' => [$percent->id => 2, $once->id => 1]]]);
        $this->assertSame(0, $this->pricing->renewalAmount($subscription->fresh(), now()->subMonth(), now())->discount);
    }

    public function test_discount_duration_and_valid_until_expire(): void
    {
        $plan = $this->cardknoxPlan(price: ['price' => 2000]);
        $subscription = $this->cardknoxSubscription($plan);
        $months = Discount::create(['name' => 'm', 'type' => DiscountConstants::TYPE_FIXED, 'amount' => 100, 'is_active' => true, 'is_recurring' => true, 'duration_in_months' => 1]);
        $expired = Discount::create(['name' => 'e', 'type' => DiscountConstants::TYPE_FIXED, 'amount' => 100, 'is_active' => true, 'is_recurring' => true]);

        SubscriptionDiscount::create(['subscription_id' => $subscription->id, 'discount_id' => $months->id, 'type' => 'fixed', 'amount' => 100, 'is_recurring' => true]);
        SubscriptionDiscount::create(['subscription_id' => $subscription->id, 'discount_id' => $expired->id, 'type' => 'fixed', 'amount' => 100, 'is_recurring' => true, 'valid_until' => now()->subDay()]);

        $this->assertSame(100, $this->pricing->renewalAmount($subscription, now(), now()->addMonth())->discount);
        $this->assertSame(0, $this->pricing->renewalAmount($subscription, now()->addMonths(2), now()->addMonths(3))->discount);
    }

    public function test_pending_credit_is_deducted_but_never_below_zero(): void
    {
        $plan = $this->cardknoxPlan(price: ['price' => 2000]);
        $subscription = $this->cardknoxSubscription($plan, ['extra_payment_provider_data' => ['pending_credit' => 500]]);

        $amount = $this->pricing->renewalAmount($subscription, now()->subMonth(), now());
        $this->assertSame(500, $amount->credit);
        $this->assertSame(1500, $amount->total);

        $subscription->update(['extra_payment_provider_data' => ['pending_credit' => 5000]]);
        $big = $this->pricing->renewalAmount($subscription->fresh(), now()->subMonth(), now());
        $this->assertSame(2000, $big->credit);
        $this->assertSame(0, $big->total);
    }

    public function test_proration_is_signed_and_proportional(): void
    {
        Carbon::setTestNow('2026-11-16 00:00:00');
        $plan = $this->cardknoxPlan(price: ['price' => 3000]);
        // Period 2026-11-01 → 2026-12-01 (30 days); 15 days remain.
        $subscription = $this->cardknoxSubscription($plan, ['ends_at' => '2026-12-01 00:00:00']);

        $this->assertSame(1000, $this->pricing->prorationAmount($subscription, 3000, 5000, now()));
        $this->assertSame(-500, $this->pricing->prorationAmount($subscription, 3000, 2000, now()));
    }

    public function test_add_interval_does_not_overflow_month_end(): void
    {
        $plan = $this->cardknoxPlan();
        $subscription = $this->cardknoxSubscription($plan);

        $this->assertSame('2027-02-28', $this->pricing->addInterval($subscription, Carbon::parse('2027-01-31'))->toDateString());
        $this->assertSame('2027-02-28', $this->pricing->subInterval($subscription, Carbon::parse('2027-03-31'))->toDateString());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=CardknoxPricingTest`
Expected: FAIL — `Class "App\Services\PaymentProviders\Cardknox\CardknoxPricing" not found`.

- [ ] **Step 3: Implement**

`backend/app/Services/PaymentProviders/Cardknox/CardknoxAmount.php`:

```php
<?php

namespace App\Services\PaymentProviders\Cardknox;

final class CardknoxAmount
{
    /**
     * @param  list<int>  $discountIdsUsed  discounts applied to this charge, counted
     *                                      into discount_uses only when it succeeds
     */
    public function __construct(
        public readonly int $subtotal,
        public readonly int $usage,
        public readonly int $discount,
        public readonly int $credit,
        public readonly int $total,
        public readonly array $discountIdsUsed = [],
    ) {}
}
```

`backend/app/Services/PaymentProviders/Cardknox/CardknoxPricing.php`:

```php
<?php

namespace App\Services\PaymentProviders\Cardknox;

use App\Constants\DiscountConstants;
use App\Constants\PlanPriceTierConstants;
use App\Constants\PlanPriceType;
use App\Constants\PlanType;
use App\Models\Discount;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\SubscriptionDiscount;
use App\Models\SubscriptionUsage;
use Carbon\Carbon;

/**
 * Every amount Cardknox charges comes from here. Mirrors the pricing rules
 * CalculationService and Stripe's price objects apply, so a plan costs the
 * same whichever provider bills it.
 */
class CardknoxPricing
{
    public function planPrice(Plan $plan, int $currencyId): PlanPrice
    {
        return $plan->prices()->where('currency_id', $currencyId)->firstOrFail();
    }

    public function recurringSubtotal(Plan $plan, int $currencyId, int $quantity): int
    {
        $price = $this->planPrice($plan, $currencyId);

        if ($plan->type === PlanType::SEAT_BASED->value) {
            if ($price->type === PlanPriceType::SEAT_BASED_WITH_INCLUDED_SEATS->value) {
                $extraSeats = max(0, $quantity - (int) $price->included_seats);

                return (int) $price->price + $extraSeats * (int) $price->extra_seat_price;
            }

            return (int) $price->price * $quantity;
        }

        // Flat-rate plans, and the fixed fee of usage-based plans (usage is billed in arrears).
        return (int) $price->price;
    }

    public function usageAmount(PlanPrice $price, int $units): int
    {
        if ($units <= 0) {
            return 0;
        }

        if ($price->type === PlanPriceType::USAGE_BASED_PER_UNIT->value) {
            return (int) round($units * (float) $price->price_per_unit);
        }

        $tiers = (array) ($price->tiers ?? []);

        if ($price->type === PlanPriceType::USAGE_BASED_TIERED_VOLUME->value) {
            foreach ($tiers as $tier) {
                if ($this->upperBound($tier) >= $units) {
                    return (int) round($units * (float) $tier[PlanPriceTierConstants::PER_UNIT] + (float) $tier[PlanPriceTierConstants::FLAT_FEE]);
                }
            }

            return 0;
        }

        // Graduated: each tier prices only the units that fall inside it.
        $total = 0.0;
        $lower = 0;

        foreach ($tiers as $tier) {
            $upper = $this->upperBound($tier);
            $inTier = min($units, $upper) - $lower;

            if ($inTier <= 0) {
                break;
            }

            $total += $inTier * (float) $tier[PlanPriceTierConstants::PER_UNIT] + (float) $tier[PlanPriceTierConstants::FLAT_FEE];
            $lower = $upper;
        }

        return (int) round($total);
    }

    /** Same formula as DiscountService::getDiscountAmount(), capped at the subtotal. */
    public function discountFor(string $type, int|float|string $amount, int $subtotal): int
    {
        return match ($type) {
            DiscountConstants::TYPE_FIXED => min($subtotal, (int) $amount),
            DiscountConstants::TYPE_PERCENTAGE => (int) ($subtotal * ((float) $amount / 100)),
            default => 0,
        };
    }

    public function renewalAmount(Subscription $subscription, Carbon $periodStart, Carbon $periodEnd): CardknoxAmount
    {
        $plan = $subscription->plan;
        $subtotal = $this->recurringSubtotal($plan, $subscription->currency_id, max(1, (int) $subscription->quantity));
        $usage = 0;

        if ($plan->type === PlanType::USAGE_BASED->value) {
            $units = (int) SubscriptionUsage::query()
                ->where('subscription_id', $subscription->id)
                ->where('created_at', '>=', $periodStart)
                ->where('created_at', '<', $periodEnd)
                ->sum('unit_count');

            $usage = $this->usageAmount($this->planPrice($plan, $subscription->currency_id), $units);
        }

        [$discount, $discountIds] = $this->activeDiscount($subscription, $subtotal + $usage, $periodStart);

        $due = $subtotal + $usage - $discount;
        $credit = min((int) ($subscription->extra_payment_provider_data['pending_credit'] ?? 0), $due);

        return new CardknoxAmount($subtotal, $usage, $discount, $credit, $due - $credit, $discountIds);
    }

    public function prorationAmount(Subscription $subscription, int $oldSubtotal, int $newSubtotal, Carbon $at): int
    {
        $end = Carbon::parse($subscription->ends_at);
        $start = $this->subInterval($subscription, $end);
        $period = max(1, (int) $start->diffInSeconds($end, true));
        $remaining = max(0, min($period, (int) $at->diffInSeconds($end, false)));

        return (int) round(($newSubtotal - $oldSubtotal) * ($remaining / $period));
    }

    public function addInterval(Subscription $subscription, Carbon $from): Carbon
    {
        // overflow=false: Jan 31 + 1 month is Feb 28/29, never Mar 3.
        return $from->copy()->add((string) $subscription->interval->date_identifier, (int) $subscription->interval_count, false);
    }

    public function subInterval(Subscription $subscription, Carbon $from): Carbon
    {
        return $from->copy()->sub((string) $subscription->interval->date_identifier, (int) $subscription->interval_count, false);
    }

    /** @return array{0: int, 1: list<int>} */
    private function activeDiscount(Subscription $subscription, int $base, Carbon $periodStart): array
    {
        $uses = (array) ($subscription->extra_payment_provider_data['discount_uses'] ?? []);
        $total = 0;
        $used = [];

        $rows = SubscriptionDiscount::query()->where('subscription_id', $subscription->id)->get();

        foreach ($rows as $row) {
            if ($row->valid_until !== null && Carbon::parse($row->valid_until)->lte($periodStart)) {
                continue;
            }

            $discount = Discount::query()->find($row->discount_id);
            $count = (int) ($uses[$row->discount_id] ?? 0);

            if (! $row->is_recurring && $count > 0) {
                continue;
            }

            if ($row->is_recurring && (int) ($discount?->maximum_recurring_intervals) > 0 && $count >= (int) $discount->maximum_recurring_intervals) {
                continue;
            }

            if ((int) ($discount?->duration_in_months) > 0 && $periodStart->gte(Carbon::parse($row->created_at)->addMonths((int) $discount->duration_in_months))) {
                continue;
            }

            $total += $this->discountFor((string) $row->type, $row->amount, $base - $total);
            $used[] = (int) $row->discount_id;
        }

        return [$total, $used];
    }

    private function upperBound(array $tier): int
    {
        $until = $tier[PlanPriceTierConstants::UNTIL_UNIT];

        return $until === '∞' ? PHP_INT_MAX : (int) $until;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=CardknoxPricingTest`
Expected: 9 passed. If `Discount::create` fails on a non-nullable column, add that column's value to the `create()` arrays in the test (check `database/migrations/2023_07_23_134740_create_discounts_table.php`), not a default in the model.

- [ ] **Step 5: Commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint app/Services/PaymentProviders/Cardknox tests/Feature/Services/PaymentProviders/Cardknox
git add backend/app/Services/PaymentProviders/Cardknox/CardknoxAmount.php backend/app/Services/PaymentProviders/Cardknox/CardknoxPricing.php backend/tests/Feature/Services/PaymentProviders/Cardknox/CardknoxPricingTest.php
git commit -m "feat(payments): Cardknox pricing — seats, usage tiers, discounts, credit, proration"
```

---
### Task 4: Charge ledger — open, execute, settle (the single state transition)

**Files:**
- Create: `backend/app/Services/PaymentProviders/Cardknox/CardknoxChargeLedger.php`
- Test: `backend/tests/Feature/Services/PaymentProviders/Cardknox/CardknoxChargeLedgerTest.php`

**Interfaces:**
- Consumes: `CardknoxClient::sale()`, `CardknoxResult`, `CardknoxOutcome`, `CardknoxPricing::addInterval()`, `CardknoxChargePurpose`, `CardknoxCharge`, `TransactionService::createForSubscription/createForOrder/updateTransaction`, `SubscriptionService::updateSubscription/handleInvoicePaymentFailed`, `OrderService::updateOrder`.
- Produces (`CardknoxChargeLedger`):
  - `provider(): PaymentProvider`
  - `open(string $base, CardknoxChargePurpose $purpose, Subscription|Order $payable, int $amount, int $discount = 0, ?Carbon $periodEnd = null, array $meta = []): ?CardknoxCharge` — null when an attempt for `$base` is pending/succeeded or a concurrent run won the race. Commits on its own; call it **outside** any transaction that will also span the HTTP call.
  - `execute(CardknoxCharge $charge, string $token, bool $merchantInitiated): CardknoxResult`
  - `settle(CardknoxCharge $charge, CardknoxResult $result): void` — idempotent; no-op for `Unknown` or a non-pending transaction.
  - `markRefunded(CardknoxCharge $charge): void`, `markDisputed(Transaction $transaction): void`
  - `static mergeData(Subscription $subscription, array $values): array`
- Charge `meta` contract consumed by `settle()`:
  - `First`: `activate` (array of subscription attributes to set on success), `discount_ids` (int[]).
  - `Renewal`: `credit` (int), `discount_ids` (int[]); `period_end` column set.
  - `Proration`: `change` (array of subscription attributes to set on success).
  - `Order`: none.

- [ ] **Step 1: Write the failing test**

`backend/tests/Feature/Services/PaymentProviders/Cardknox/CardknoxChargeLedgerTest.php`:

```php
<?php

namespace Tests\Feature\Services\PaymentProviders\Cardknox;

use App\Constants\OrderStatus;
use App\Constants\SubscriptionStatus;
use App\Constants\TransactionStatus;
use App\Events\Order\Ordered;
use App\Events\Order\OrderRefunded;
use App\Events\Subscription\InvoicePaymentFailed;
use App\Events\Subscription\SubscriptionRenewed;
use App\Models\Order;
use App\Services\PaymentProviders\Cardknox\CardknoxChargeLedger;
use App\Services\PaymentProviders\Cardknox\CardknoxChargePurpose;
use App\Services\PaymentProviders\Cardknox\CardknoxOutcome;
use App\Services\PaymentProviders\Cardknox\CardknoxResult;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTest;
use Tests\Support\CardknoxTestHelpers;

class CardknoxChargeLedgerTest extends FeatureTest
{
    use CardknoxTestHelpers;

    private CardknoxChargeLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureCardknox();
        $this->ledger = app(CardknoxChargeLedger::class);
    }

    public function test_open_creates_a_pending_transaction_under_attempt_one(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan());

        $charge = $this->ledger->open('S'.$subscription->id.'-F', CardknoxChargePurpose::First, $subscription, 2000, 100);

        $this->assertSame('S'.$subscription->id.'-F-1', $charge->invoice);
        $this->assertSame(TransactionStatus::PENDING->value, $charge->transaction->status);
        $this->assertSame(2000, (int) $charge->transaction->amount);
        $this->assertSame(100, (int) $charge->transaction->total_discount);
        $this->assertSame($charge->invoice, $charge->transaction->payment_provider_transaction_id);
    }

    public function test_open_refuses_while_an_attempt_is_pending_or_succeeded(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan());
        $base = 'S'.$subscription->id.'-F';

        $first = $this->ledger->open($base, CardknoxChargePurpose::First, $subscription, 2000);
        $this->assertNull($this->ledger->open($base, CardknoxChargePurpose::First, $subscription, 2000), 'pending blocks');

        $this->ledger->settle($first, CardknoxResult::fromResponse($this->gatewayDeclines()));
        $second = $this->ledger->open($base, CardknoxChargePurpose::First, $subscription, 2000);
        $this->assertSame($base.'-2', $second->invoice, 'a failed attempt allows a new one');

        $this->ledger->settle($second, CardknoxResult::fromResponse($this->gatewayApproves()));
        $this->assertNull($this->ledger->open($base, CardknoxChargePurpose::First, $subscription, 2000), 'success blocks');
    }

    public function test_execute_sends_a_sale_and_settles_first_charge(): void
    {
        Carbon::setTestNow('2026-11-01 10:00:00');
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['status' => SubscriptionStatus::NEW->value]);
        $this->fakeGateway($this->gatewayApproves(['xRefNum' => '777']));

        $charge = $this->ledger->open('S'.$subscription->id.'-F', CardknoxChargePurpose::First, $subscription, 2000, meta: [
            'activate' => ['quantity' => 3],
            'discount_ids' => [42],
        ]);
        $result = $this->ledger->execute($charge, 'tok_x', merchantInitiated: false);

        $this->assertTrue($result->isApproved());
        $fresh = $subscription->fresh();
        $this->assertSame(SubscriptionStatus::ACTIVE->value, $fresh->status);
        $this->assertSame(3, (int) $fresh->quantity);
        $this->assertSame('2026-12-01 10:00:00', Carbon::parse($fresh->ends_at)->toDateTimeString());
        $this->assertSame([42 => 1], $fresh->extra_payment_provider_data['discount_uses']);
        $this->assertSame('777', $charge->fresh()->ref_num);
        $this->assertSame(TransactionStatus::SUCCESS->value, $charge->transaction->fresh()->status);
        Carbon::setTestNow();
    }

    public function test_zero_amount_settles_without_a_gateway_call(): void
    {
        Http::fake();
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['status' => SubscriptionStatus::NEW->value]);

        $charge = $this->ledger->open('S'.$subscription->id.'-F', CardknoxChargePurpose::First, $subscription, 0);
        $this->ledger->execute($charge, 'tok_x', merchantInitiated: false);

        Http::assertNothingSent();
        $this->assertSame(SubscriptionStatus::ACTIVE->value, $subscription->fresh()->status);
    }

    public function test_unknown_result_changes_nothing(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['status' => SubscriptionStatus::NEW->value]);
        Http::fake(['x1.cardknox.com/*' => Http::failedConnection()]);

        $charge = $this->ledger->open('S'.$subscription->id.'-F', CardknoxChargePurpose::First, $subscription, 2000);
        $result = $this->ledger->execute($charge, 'tok_x', merchantInitiated: false);

        $this->assertSame(CardknoxOutcome::Unknown, $result->outcome);
        $this->assertSame(TransactionStatus::PENDING->value, $charge->transaction->fresh()->status);
        $this->assertSame(SubscriptionStatus::NEW->value, $subscription->fresh()->status);
    }

    public function test_renewal_success_advances_once_and_consumes_credit(): void
    {
        Event::fake([SubscriptionRenewed::class]);
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), [
            'ends_at' => '2026-11-01 00:00:00',
            'extra_payment_provider_data' => ['pending_credit' => 700, 'failed_attempts' => 2, 'next_retry_at' => '2026-11-04 00:00:00'],
        ]);
        $periodEnd = Carbon::parse('2026-11-01 00:00:00');

        $charge = $this->ledger->open('S'.$subscription->id.'-20261101', CardknoxChargePurpose::Renewal, $subscription, 1300, 0, $periodEnd, ['credit' => 700, 'discount_ids' => []]);
        $this->ledger->settle($charge, CardknoxResult::fromResponse($this->gatewayApproves()));

        $fresh = $subscription->fresh();
        $this->assertSame('2026-12-01 00:00:00', Carbon::parse($fresh->ends_at)->toDateTimeString());
        $this->assertSame(0, $fresh->extra_payment_provider_data['pending_credit']);
        $this->assertSame(0, $fresh->extra_payment_provider_data['failed_attempts']);
        $this->assertNull($fresh->extra_payment_provider_data['next_retry_at']);
        Event::assertDispatched(SubscriptionRenewed::class);
    }

    public function test_settle_is_idempotent_and_late_settle_does_not_double_advance(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['ends_at' => '2026-11-01 00:00:00']);
        $charge = $this->ledger->open('S'.$subscription->id.'-20261101', CardknoxChargePurpose::Renewal, $subscription, 2000, 0, Carbon::parse('2026-11-01 00:00:00'));
        $approved = CardknoxResult::fromResponse($this->gatewayApproves());

        $this->ledger->settle($charge, $approved);
        $this->ledger->settle($charge->fresh(), $approved);

        $this->assertSame('2026-12-01 00:00:00', Carbon::parse($subscription->fresh()->ends_at)->toDateTimeString());
    }

    public function test_renewal_decline_walks_the_retry_ladder_then_cancels(): void
    {
        Event::fake([InvoicePaymentFailed::class]);
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['ends_at' => '2026-11-01 00:00:00']);
        $base = 'S'.$subscription->id.'-20261101';
        $periodEnd = Carbon::parse('2026-11-01 00:00:00');
        $expectedRetries = ['2026-11-02 00:00:00', '2026-11-04 00:00:00', '2026-11-06 00:00:00'];

        foreach ($expectedRetries as $attempt => $retryAt) {
            $charge = $this->ledger->open($base, CardknoxChargePurpose::Renewal, $subscription->fresh(), 2000, 0, $periodEnd);
            $this->ledger->settle($charge, CardknoxResult::fromResponse($this->gatewayDeclines()));

            $fresh = $subscription->fresh();
            $this->assertSame(SubscriptionStatus::PAST_DUE->value, $fresh->status);
            $this->assertSame($attempt + 1, $fresh->extra_payment_provider_data['failed_attempts']);
            $this->assertSame($retryAt, $fresh->extra_payment_provider_data['next_retry_at']);
        }

        $charge = $this->ledger->open($base, CardknoxChargePurpose::Renewal, $subscription->fresh(), 2000, 0, $periodEnd);
        $this->ledger->settle($charge, CardknoxResult::fromResponse($this->gatewayDeclines()));

        $this->assertSame(SubscriptionStatus::CANCELED->value, $subscription->fresh()->status);
        Event::assertDispatchedTimes(InvoicePaymentFailed::class, 4);
    }

    public function test_proration_success_applies_the_deferred_change(): void
    {
        $plan = $this->cardknoxPlan();
        $target = $this->cardknoxPlan(price: ['price' => 5000]);
        $subscription = $this->cardknoxSubscription($plan);

        $charge = $this->ledger->open('S'.$subscription->id.'-P1', CardknoxChargePurpose::Proration, $subscription, 900, meta: ['change' => ['plan_id' => $target->id, 'price' => 5000]]);
        $this->ledger->settle($charge, CardknoxResult::fromResponse($this->gatewayApproves()));

        $this->assertSame($target->id, $subscription->fresh()->plan_id);
    }

    public function test_order_success_and_refund(): void
    {
        Event::fake([Ordered::class, OrderRefunded::class]);
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan());
        $order = Order::factory()->create(['user_id' => $subscription->user_id, 'tenant_id' => $subscription->tenant_id, 'total_amount' => 4900, 'total_amount_after_discount' => 4900, 'total_discount_amount' => 0]);

        $charge = $this->ledger->open('O'.$order->id, CardknoxChargePurpose::Order, $order, 4900);
        $this->ledger->settle($charge, CardknoxResult::fromResponse($this->gatewayApproves()));

        $this->assertSame(OrderStatus::SUCCESS->value, $order->fresh()->status);
        $this->assertSame($this->cardknoxProviderModel()->id, $order->fresh()->payment_provider_id);
        Event::assertDispatched(Ordered::class);

        $this->ledger->markRefunded($charge->fresh());
        $this->ledger->markRefunded($charge->fresh());

        $this->assertSame(OrderStatus::REFUNDED->value, $order->fresh()->status);
        $this->assertSame(TransactionStatus::REFUNDED->value, $charge->transaction->fresh()->status);
        Event::assertDispatchedTimes(OrderRefunded::class, 1);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=CardknoxChargeLedgerTest`
Expected: FAIL — `Class "App\Services\PaymentProviders\Cardknox\CardknoxChargeLedger" not found`.

- [ ] **Step 3: Implement**

`backend/app/Services/PaymentProviders/Cardknox/CardknoxChargeLedger.php`:

```php
<?php

namespace App\Services\PaymentProviders\Cardknox;

use App\Client\CardknoxClient;
use App\Constants\OrderStatus;
use App\Constants\PaymentProviderConstants;
use App\Constants\SubscriptionStatus;
use App\Constants\TransactionStatus;
use App\Models\CardknoxCharge;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Services\OrderService;
use App\Services\SubscriptionService;
use App\Services\TransactionService;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Owns the lifecycle of one gateway charge: open a pending transaction under
 * a unique invoice key (committed before any HTTP call, so a crash after the
 * gateway approves still leaves a record to reconcile), send it, settle it.
 *
 * settle() is the only place a gateway result changes local state. The
 * synchronous path, the webhook job and reconciliation all call it, in any
 * order and any number of times.
 */
class CardknoxChargeLedger
{
    public function __construct(
        private CardknoxClient $client,
        private CardknoxPricing $pricing,
        private SubscriptionService $subscriptionService,
        private TransactionService $transactionService,
        private OrderService $orderService,
    ) {}

    public function provider(): PaymentProvider
    {
        return PaymentProvider::where('slug', PaymentProviderConstants::CARDKNOX_SLUG)->firstOrFail();
    }

    public function open(
        string $base,
        CardknoxChargePurpose $purpose,
        Subscription|Order $payable,
        int $amount,
        int $discount = 0,
        ?Carbon $periodEnd = null,
        array $meta = [],
    ): ?CardknoxCharge {
        $previous = CardknoxCharge::query()->where('invoice_base', $base)->with('transaction')->get();

        $blocked = $previous->contains(fn (CardknoxCharge $charge): bool => in_array(
            $charge->transaction?->status,
            [TransactionStatus::PENDING->value, TransactionStatus::SUCCESS->value],
            true,
        ));

        if ($blocked) {
            return null;
        }

        $invoice = $base.'-'.($previous->count() + 1);
        $provider = $this->provider();

        try {
            return DB::transaction(function () use ($invoice, $base, $purpose, $payable, $amount, $discount, $periodEnd, $meta, $provider): CardknoxCharge {
                $transaction = $payable instanceof Order
                    ? $this->transactionService->createForOrder($payable, $amount, 0, $discount, 0, $payable->currency, $provider, $invoice, 'pending', TransactionStatus::PENDING)
                    : $this->transactionService->createForSubscription($payable, $amount, 0, $discount, 0, $payable->currency, $provider, $invoice, 'pending', TransactionStatus::PENDING);

                return CardknoxCharge::create([
                    'transaction_id' => $transaction->id,
                    'invoice_base' => $base,
                    'invoice' => $invoice,
                    'purpose' => $purpose->value,
                    'period_end' => $periodEnd,
                    'meta' => $meta,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent run opened this exact attempt first.
            return null;
        }
    }

    public function execute(CardknoxCharge $charge, string $token, bool $merchantInitiated): CardknoxResult
    {
        $amount = (int) $charge->transaction->amount;

        $result = $amount <= 0
            ? CardknoxResult::approvedWithoutCharge()
            : $this->client->sale($token, $amount, $charge->invoice, $merchantInitiated ? CardknoxClient::RECURRING : null);

        $this->settle($charge, $result);

        return $result;
    }

    public function settle(CardknoxCharge $charge, CardknoxResult $result): void
    {
        if ($result->outcome === CardknoxOutcome::Unknown) {
            return;
        }

        DB::transaction(function () use ($charge, $result): void {
            $transaction = Transaction::query()->whereKey($charge->transaction_id)->lockForUpdate()->firstOrFail();

            if ($transaction->status !== TransactionStatus::PENDING->value) {
                return;
            }

            if ($result->refNum !== null) {
                $charge->update(['ref_num' => $result->refNum]);
            }

            if ($result->isApproved()) {
                $this->transactionService->updateTransaction($transaction, 'approved', TransactionStatus::SUCCESS);
                $this->applyApproved($charge, $transaction);

                return;
            }

            $this->transactionService->updateTransaction(
                $transaction,
                $result->outcome->value,
                TransactionStatus::FAILED,
                $result->error ?? __('Payment declined'),
            );
            $this->applyFailed($charge, $transaction);
        });
    }

    public function markRefunded(CardknoxCharge $charge): void
    {
        DB::transaction(function () use ($charge): void {
            $transaction = Transaction::query()->whereKey($charge->transaction_id)->lockForUpdate()->firstOrFail();

            if ($transaction->status !== TransactionStatus::SUCCESS->value) {
                return;
            }

            $this->transactionService->updateTransaction($transaction, 'refunded', TransactionStatus::REFUNDED);

            if ($transaction->order_id !== null) {
                $this->orderService->updateOrder(Order::query()->findOrFail($transaction->order_id), [
                    'status' => OrderStatus::REFUNDED->value,
                ]);
            }
        });
    }

    public function markDisputed(Transaction $transaction): void
    {
        DB::transaction(function () use ($transaction): void {
            $locked = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === TransactionStatus::DISPUTED->value) {
                return;
            }

            $this->transactionService->updateTransaction($locked, 'disputed', TransactionStatus::DISPUTED);

            if ($locked->order_id !== null) {
                $this->orderService->updateOrder(Order::query()->findOrFail($locked->order_id), [
                    'status' => OrderStatus::DISPUTED->value,
                ]);
            }
        });
    }

    public static function mergeData(Subscription $subscription, array $values): array
    {
        return array_merge($subscription->extra_payment_provider_data ?? [], $values);
    }

    private function applyApproved(CardknoxCharge $charge, Transaction $transaction): void
    {
        $purpose = CardknoxChargePurpose::from($charge->purpose);

        if ($purpose === CardknoxChargePurpose::Order) {
            $this->orderService->updateOrder(Order::query()->findOrFail($transaction->order_id), [
                'status' => OrderStatus::SUCCESS->value,
                'payment_provider_id' => $transaction->payment_provider_id,
                'payment_provider_order_id' => $charge->invoice,
            ]);

            return;
        }

        $subscription = Subscription::query()->whereKey($transaction->subscription_id)->lockForUpdate()->firstOrFail();
        $meta = $charge->meta ?? [];

        if ($purpose === CardknoxChargePurpose::First) {
            $this->subscriptionService->updateSubscription($subscription, array_merge((array) ($meta['activate'] ?? []), [
                'status' => SubscriptionStatus::ACTIVE->value,
                'ends_at' => $this->pricing->addInterval($subscription, Carbon::parse($charge->created_at)),
                'extra_payment_provider_data' => self::mergeData($subscription, [
                    'discount_uses' => self::countUses([], (array) ($meta['discount_ids'] ?? [])),
                ]),
            ]));
        } elseif ($purpose === CardknoxChargePurpose::Renewal) {
            $this->applyRenewal($subscription, $charge);
        } elseif ($purpose === CardknoxChargePurpose::Proration) {
            $this->subscriptionService->updateSubscription($subscription, (array) ($meta['change'] ?? []));
        }
    }

    private function applyRenewal(Subscription $subscription, CardknoxCharge $charge): void
    {
        $periodEnd = Carbon::parse($charge->period_end);

        // A late settle (webhook, reconciliation) must never advance a period twice.
        if (! Carbon::parse($subscription->ends_at)->equalTo($periodEnd)) {
            return;
        }

        $data = $subscription->extra_payment_provider_data ?? [];
        $meta = $charge->meta ?? [];

        $this->subscriptionService->updateSubscription($subscription, [
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => $this->pricing->addInterval($subscription, $periodEnd),
            'extra_payment_provider_data' => self::mergeData($subscription, [
                'failed_attempts' => 0,
                'next_retry_at' => null,
                'pending_credit' => max(0, (int) ($data['pending_credit'] ?? 0) - (int) ($meta['credit'] ?? 0)),
                'discount_uses' => self::countUses((array) ($data['discount_uses'] ?? []), (array) ($meta['discount_ids'] ?? [])),
            ]),
        ]);
    }

    private function applyFailed(CardknoxCharge $charge, Transaction $transaction): void
    {
        // First, proration and order failures leave the customer free to retry
        // on the pay page; only renewals walk the retry ladder.
        if ($charge->purpose !== CardknoxChargePurpose::Renewal->value) {
            return;
        }

        $subscription = Subscription::query()->whereKey($transaction->subscription_id)->lockForUpdate()->firstOrFail();
        $failedAttempts = (int) ($subscription->extra_payment_provider_data['failed_attempts'] ?? 0) + 1;
        $retryDays = array_values((array) config('services.cardknox.retry_days'));

        $this->subscriptionService->handleInvoicePaymentFailed($subscription);

        if ($failedAttempts > count($retryDays)) {
            $this->subscriptionService->updateSubscription($subscription, [
                'status' => SubscriptionStatus::CANCELED->value,
                'cancelled_at' => now(),
                'extra_payment_provider_data' => self::mergeData($subscription, [
                    'failed_attempts' => $failedAttempts,
                    'next_retry_at' => null,
                ]),
            ]);

            return;
        }

        $this->subscriptionService->updateSubscription($subscription, [
            'status' => SubscriptionStatus::PAST_DUE->value,
            'extra_payment_provider_data' => self::mergeData($subscription, [
                'failed_attempts' => $failedAttempts,
                'next_retry_at' => Carbon::parse($charge->period_end)->addDays((int) $retryDays[$failedAttempts - 1])->toDateTimeString(),
            ]),
        ]);
    }

    /** @param  list<int|string>  $discountIds */
    private static function countUses(array $uses, array $discountIds): array
    {
        foreach ($discountIds as $id) {
            $uses[$id] = (int) ($uses[$id] ?? 0) + 1;
        }

        return $uses;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=CardknoxChargeLedgerTest`
Expected: 10 passed.

- [ ] **Step 5: Commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint app/Services/PaymentProviders/Cardknox/CardknoxChargeLedger.php tests/Feature/Services/PaymentProviders/Cardknox/CardknoxChargeLedgerTest.php
git add backend/app/Services/PaymentProviders/Cardknox/CardknoxChargeLedger.php backend/tests/Feature/Services/PaymentProviders/Cardknox/CardknoxChargeLedgerTest.php
git commit -m "feat(payments): Cardknox charge ledger with idempotent settle and retry ladder"
```

---
### Task 5: Card vault and billing — first charge, trial, one-time order

**Files:**
- Create: `backend/app/Services/PaymentProviders/Cardknox/CardknoxCardVault.php`
- Create: `backend/app/Services/PaymentProviders/Cardknox/CardknoxBillingService.php`
- Test: `backend/tests/Feature/Services/PaymentProviders/Cardknox/CardknoxBillingStartTest.php`

**Interfaces:**
- Consumes: `CardknoxClient::save()`, `CardknoxChargeLedger::open/execute/provider`, `CardknoxPricing::recurringSubtotal/discountFor/planPrice`, `SubscriptionService::shouldSkipTrial/calculateSubscriptionTrialDays/updateSubscription`.
- Produces:
  - `CardknoxCardVault::tokenize(string $cardSut, string $cvvSut, string $exp): CardknoxResult` (no persistence; `Error` if approved without a token), `remember(User $user, ?Tenant $tenant, CardknoxResult $tokenized, string $exp): UserCardknoxData`, `cardFor(?int $tenantId, int $userId): ?UserCardknoxData`.
  - `CardknoxBillingService::quoteFirstCharge(Subscription $subscription, int $quantity, ?Discount $discount): int`, `startSubscription(Subscription $subscription, int $quantity, ?Discount $discount): CardknoxResult`, `payOrder(Order $order, string $token): CardknoxResult`.

- [ ] **Step 1: Write the failing test**

`backend/tests/Feature/Services/PaymentProviders/Cardknox/CardknoxBillingStartTest.php`:

```php
<?php

namespace Tests\Feature\Services\PaymentProviders\Cardknox;

use App\Constants\DiscountConstants;
use App\Constants\OrderStatus;
use App\Constants\SubscriptionStatus;
use App\Constants\SubscriptionType;
use App\Constants\TransactionStatus;
use App\Models\Currency;
use App\Models\Discount;
use App\Models\Interval;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\UserCardknoxData;
use App\Services\PaymentProviders\Cardknox\CardknoxBillingService;
use App\Services\PaymentProviders\Cardknox\CardknoxCardVault;
use App\Services\PaymentProviders\Cardknox\CardknoxOutcome;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTest;
use Tests\Support\CardknoxTestHelpers;

class CardknoxBillingStartTest extends FeatureTest
{
    use CardknoxTestHelpers;

    private CardknoxBillingService $billing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureCardknox();
        $this->billing = app(CardknoxBillingService::class);
    }

    public function test_vault_tokenizes_and_remembers_one_card_per_tenant_user(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), withCard: false);
        $this->fakeGateway($this->gatewayApproves(['xToken' => 'tok_a']), $this->gatewayApproves(['xToken' => 'tok_b', 'xMaskedCardNumber' => '5xxxxxxxxxxx4444']));
        $vault = app(CardknoxCardVault::class);

        $vault->remember($subscription->user, $subscription->tenant, $vault->tokenize('sut', 'sut', '1230'), '1230');
        $vault->remember($subscription->user, $subscription->tenant, $vault->tokenize('sut', 'sut', '0131'), '0131');

        $card = $vault->cardFor($subscription->tenant_id, $subscription->user_id);
        $this->assertSame('tok_b', $card->card_token);
        $this->assertSame('4444', $card->card_last4);
        $this->assertSame(1, UserCardknoxData::where('user_id', $subscription->user_id)->count());
    }

    public function test_trial_saves_card_and_charges_nothing(): void
    {
        Http::fake();
        $trialDay = Interval::where('slug', 'day')->firstOrFail();
        $plan = $this->cardknoxPlan(['has_trial' => true, 'trial_interval_id' => $trialDay->id, 'trial_interval_count' => 14]);
        $subscription = $this->cardknoxSubscription($plan, ['status' => SubscriptionStatus::NEW->value, 'ends_at' => null]);

        $result = $this->billing->startSubscription($subscription, 1, null);

        $this->assertTrue($result->isApproved());
        Http::assertNothingSent();
        $fresh = $subscription->fresh();
        $this->assertSame(SubscriptionStatus::ACTIVE->value, $fresh->status);
        $this->assertNotNull($fresh->trial_ends_at);
        $this->assertEquals($fresh->trial_ends_at, $fresh->ends_at);
        $this->assertSame(0, Transaction::where('subscription_id', $subscription->id)->count());
    }

    public function test_first_charge_includes_quantity_discount_and_setup_fee(): void
    {
        $plan = $this->cardknoxPlan(price: ['price' => 2000, 'setup_fee' => 500]);
        $subscription = $this->cardknoxSubscription($plan, ['status' => SubscriptionStatus::NEW->value]);
        $discount = Discount::create(['name' => 'ten', 'type' => DiscountConstants::TYPE_PERCENTAGE, 'amount' => 10, 'is_active' => true, 'is_recurring' => false]);
        $this->fakeGateway($this->gatewayApproves());

        $this->assertSame(2000 - 200 + 500, $this->billing->quoteFirstCharge($subscription, 1, $discount));

        $result = $this->billing->startSubscription($subscription, 1, $discount);

        $this->assertTrue($result->isApproved());
        Http::assertSent(fn (Request $request): bool => $request['xAmount'] === '23.00' && $request['xInvoice'] === 'S'.$subscription->id.'-F-1');
        $fresh = $subscription->fresh();
        $this->assertSame(SubscriptionStatus::ACTIVE->value, $fresh->status);
        $this->assertSame('ckx_sub_'.$subscription->uuid, $fresh->payment_provider_subscription_id);
        $this->assertSame([$discount->id => 1], $fresh->extra_payment_provider_data['discount_uses']);
        $this->assertSame(200, (int) Transaction::where('subscription_id', $subscription->id)->value('total_discount'));
    }

    public function test_declined_first_charge_leaves_subscription_unchanged(): void
    {
        $plan = $this->cardknoxPlan();
        $subscription = $this->cardknoxSubscription($plan, [
            'status' => SubscriptionStatus::ACTIVE->value,
            'type' => SubscriptionType::LOCALLY_MANAGED,
            'payment_provider_id' => null,
            'payment_provider_subscription_id' => null,
        ]);
        $this->fakeGateway($this->gatewayDeclines());

        $result = $this->billing->startSubscription($subscription, 1, null);

        $this->assertSame(CardknoxOutcome::Declined, $result->outcome);
        $fresh = $subscription->fresh();
        $this->assertSame(SubscriptionType::LOCALLY_MANAGED, $fresh->type);
        $this->assertNull($fresh->payment_provider_id);
        $this->assertSame(TransactionStatus::FAILED->value, Transaction::where('subscription_id', $subscription->id)->value('status'));
    }

    public function test_convert_local_subscription_is_charged_and_becomes_provider_managed(): void
    {
        $plan = $this->cardknoxPlan(['has_trial' => true, 'trial_interval_id' => Interval::where('slug', 'day')->firstOrFail()->id, 'trial_interval_count' => 14]);
        $subscription = $this->cardknoxSubscription($plan, [
            'type' => SubscriptionType::LOCALLY_MANAGED,
            'payment_provider_id' => null,
            'payment_provider_subscription_id' => null,
        ]);
        $this->fakeGateway($this->gatewayApproves());

        $this->billing->startSubscription($subscription, 1, null);

        Http::assertSentCount(1);
        $fresh = $subscription->fresh();
        $this->assertSame(SubscriptionType::PAYMENT_PROVIDER_MANAGED, $fresh->type);
        $this->assertSame($this->cardknoxProviderModel()->id, $fresh->payment_provider_id);
    }

    public function test_pay_order_charges_the_order_total(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan());
        $order = Order::factory()->create(['user_id' => $subscription->user_id, 'tenant_id' => $subscription->tenant_id, 'total_amount' => 11900, 'total_amount_after_discount' => 9900, 'total_discount_amount' => 2000]);
        $this->fakeGateway($this->gatewayApproves());

        $result = $this->billing->payOrder($order, 'tok_once');

        $this->assertTrue($result->isApproved());
        Http::assertSent(fn (Request $request): bool => $request['xToken'] === 'tok_once' && $request['xAmount'] === '99.00' && $request['xInvoice'] === 'O'.$order->id.'-1');
        $this->assertSame(OrderStatus::SUCCESS->value, $order->fresh()->status);
    }

    public function test_non_usd_is_refused_before_the_gateway(): void
    {
        Http::fake();
        $eur = Currency::where('code', 'EUR')->first() ?? Currency::factory()->create(['code' => 'EUR']);
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan());
        $order = Order::factory()->create(['user_id' => $subscription->user_id, 'tenant_id' => $subscription->tenant_id, 'currency_id' => $eur->id, 'total_amount' => 100, 'total_amount_after_discount' => 100, 'total_discount_amount' => 0]);

        $this->assertSame(CardknoxOutcome::Error, $this->billing->payOrder($order, 'tok')->outcome);
        Http::assertNothingSent();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=CardknoxBillingStartTest`
Expected: FAIL — `Class "App\Services\PaymentProviders\Cardknox\CardknoxBillingService" not found`.

- [ ] **Step 3: Implement the vault**

`backend/app/Services/PaymentProviders/Cardknox/CardknoxCardVault.php`:

```php
<?php

namespace App\Services\PaymentProviders\Cardknox;

use App\Client\CardknoxClient;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserCardknoxData;

/**
 * Turns iFields single-use tokens into a reusable Cardknox token. Tokenizing
 * and remembering are separate so a one-time purchase can pay with a card
 * without replacing the card a subscription renews on.
 */
class CardknoxCardVault
{
    public function __construct(private CardknoxClient $client) {}

    public function tokenize(string $cardSut, string $cvvSut, string $exp): CardknoxResult
    {
        $result = $this->client->save($cardSut, $cvvSut, $exp);

        if ($result->isApproved() && $result->token === null) {
            return CardknoxResult::error(__('Your card could not be saved.'));
        }

        return $result;
    }

    public function remember(User $user, ?Tenant $tenant, CardknoxResult $tokenized, string $exp): UserCardknoxData
    {
        return UserCardknoxData::query()->updateOrCreate(
            ['tenant_id' => $tenant?->id, 'user_id' => $user->id],
            [
                'card_token' => (string) $tokenized->token,
                'card_brand' => $tokenized->cardType,
                'card_last4' => $tokenized->maskedCard === null ? null : substr($tokenized->maskedCard, -4),
                'card_exp' => $tokenized->exp ?? $exp,
            ],
        );
    }

    public function cardFor(?int $tenantId, int $userId): ?UserCardknoxData
    {
        return UserCardknoxData::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->first();
    }
}
```

- [ ] **Step 4: Implement the billing service (first version)**

`backend/app/Services/PaymentProviders/Cardknox/CardknoxBillingService.php`:

```php
<?php

namespace App\Services\PaymentProviders\Cardknox;

use App\Constants\SubscriptionStatus;
use App\Constants\SubscriptionType;
use App\Models\Discount;
use App\Models\Order;
use App\Models\Subscription;
use App\Services\SubscriptionService;

/**
 * Decides what to charge and when. Every gateway call goes through
 * CardknoxChargeLedger, which owns idempotency and state transitions.
 */
class CardknoxBillingService
{
    public function __construct(
        private CardknoxChargeLedger $ledger,
        private CardknoxPricing $pricing,
        private CardknoxCardVault $vault,
        private SubscriptionService $subscriptionService,
    ) {}

    public function quoteFirstCharge(Subscription $subscription, int $quantity, ?Discount $discount): int
    {
        return $this->startsWithTrial($subscription) ? 0 : $this->firstCharge($subscription, $quantity, $discount)['amount'];
    }

    /** The card must already be remembered in the vault. */
    public function startSubscription(Subscription $subscription, int $quantity, ?Discount $discount): CardknoxResult
    {
        if ($subscription->currency->code !== 'USD') {
            return CardknoxResult::error(__('Cardknox only accepts payments in USD.'));
        }

        if ($this->vault->cardFor($subscription->tenant_id, $subscription->user_id) === null) {
            return CardknoxResult::error(__('No card on file.'));
        }

        // Read before anything changes: shouldSkipTrial() inspects the current type.
        $withTrial = $this->startsWithTrial($subscription);

        // Applied only once the first charge succeeds, so a declined
        // convert-local checkout leaves the local subscription untouched.
        $activate = [
            'type' => SubscriptionType::PAYMENT_PROVIDER_MANAGED,
            'quantity' => $quantity,
            'payment_provider_id' => $this->ledger->provider()->id,
            'payment_provider_subscription_id' => 'ckx_sub_'.$subscription->uuid,
        ];

        if ($withTrial) {
            $trialEndsAt = now()->addDays($this->subscriptionService->calculateSubscriptionTrialDays($subscription->plan));

            $this->subscriptionService->updateSubscription($subscription, $activate + [
                'status' => SubscriptionStatus::ACTIVE->value,
                'trial_ends_at' => $trialEndsAt,
                'ends_at' => $trialEndsAt,
            ]);

            return CardknoxResult::approvedWithoutCharge();
        }

        $first = $this->firstCharge($subscription, $quantity, $discount);

        $charge = $this->ledger->open(
            'S'.$subscription->id.'-F',
            CardknoxChargePurpose::First,
            $subscription,
            $first['amount'],
            $first['discount'],
            meta: ['activate' => $activate, 'discount_ids' => $discount === null ? [] : [$discount->id]],
        );

        if ($charge === null) {
            return CardknoxResult::inFlight();
        }

        $card = $this->vault->cardFor($subscription->tenant_id, $subscription->user_id);

        return $this->ledger->execute($charge, (string) $card?->card_token, merchantInitiated: false);
    }

    public function payOrder(Order $order, string $token): CardknoxResult
    {
        if ($order->currency->code !== 'USD') {
            return CardknoxResult::error(__('Cardknox only accepts payments in USD.'));
        }

        $charge = $this->ledger->open(
            'O'.$order->id,
            CardknoxChargePurpose::Order,
            $order,
            (int) $order->total_amount_after_discount,
            (int) $order->total_discount_amount,
        );

        if ($charge === null) {
            return CardknoxResult::inFlight();
        }

        return $this->ledger->execute($charge, $token, merchantInitiated: false);
    }

    private function startsWithTrial(Subscription $subscription): bool
    {
        return (bool) $subscription->plan->has_trial && ! $this->subscriptionService->shouldSkipTrial($subscription);
    }

    /** @return array{amount: int, discount: int} */
    private function firstCharge(Subscription $subscription, int $quantity, ?Discount $discount): array
    {
        $plan = $subscription->plan;
        $subtotal = $this->pricing->recurringSubtotal($plan, $subscription->currency_id, $quantity);
        $discountAmount = $discount === null ? 0 : $this->pricing->discountFor($discount->type, $discount->amount, $subtotal);
        $setupFee = (int) ($this->pricing->planPrice($plan, $subscription->currency_id)->setup_fee ?? 0);

        return ['amount' => $subtotal - $discountAmount + $setupFee, 'discount' => $discountAmount];
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=CardknoxBillingStartTest`
Expected: 7 passed. If `test_convert_local_subscription_is_charged_and_becomes_provider_managed` sends no request, `shouldSkipTrial()` is returning false: it is true for `LOCALLY_MANAGED` + `has_trial` (see `SubscriptionService::shouldSkipTrial`) — confirm `startsWithTrial()` is evaluated before `$activate` is applied.

- [ ] **Step 6: Commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint app/Services/PaymentProviders/Cardknox tests/Feature/Services/PaymentProviders/Cardknox
git add backend/app/Services/PaymentProviders/Cardknox/CardknoxCardVault.php backend/app/Services/PaymentProviders/Cardknox/CardknoxBillingService.php backend/tests/Feature/Services/PaymentProviders/Cardknox/CardknoxBillingStartTest.php
git commit -m "feat(payments): Cardknox card vault, first charge, trial and order payment"
```

---
### Task 6: Renewals — `chargeRenewal()`, `retryOutstanding()`, hourly command

**Files:**
- Modify: `backend/app/Services/PaymentProviders/Cardknox/CardknoxBillingService.php`
- Create: `backend/app/Console/Commands/Cardknox/ChargeCardknoxRenewals.php`
- Modify: `backend/routes/console.php`
- Test: `backend/tests/Feature/Console/Cardknox/ChargeCardknoxRenewalsTest.php`

**Interfaces:**
- Consumes: `CardknoxPricing::renewalAmount/subInterval`, `CardknoxChargeLedger::open/execute/settle`, `CardknoxCardVault::cardFor`.
- Produces: `CardknoxBillingService::chargeRenewal(Subscription $subscription, bool $force = false): ?CardknoxResult` (null = not due / already in flight / cancelled at end of cycle), `retryOutstanding(Subscription $subscription): ?CardknoxResult`; command `app:charge-cardknox-renewals {--subscription=}`.

- [ ] **Step 1: Write the failing test**

`backend/tests/Feature/Console/Cardknox/ChargeCardknoxRenewalsTest.php`:

```php
<?php

namespace Tests\Feature\Console\Cardknox;

use App\Constants\SubscriptionStatus;
use App\Constants\TransactionStatus;
use App\Events\Subscription\SubscriptionRenewed;
use App\Models\CardknoxCharge;
use App\Models\Transaction;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTest;
use Tests\Support\CardknoxTestHelpers;

class ChargeCardknoxRenewalsTest extends FeatureTest
{
    use CardknoxTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureCardknox();
        Carbon::setTestNow('2026-11-01 01:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function runFor(string $uuid): void
    {
        // --subscription scopes the run to this test's row; other tests share the DB.
        $this->artisan('app:charge-cardknox-renewals', ['--subscription' => $uuid])->assertSuccessful();
    }

    public function test_due_subscription_is_charged_with_the_recurring_indicator_and_advanced(): void
    {
        Event::fake([SubscriptionRenewed::class]);
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['ends_at' => '2026-11-01 00:00:00']);
        $this->fakeGateway($this->gatewayApproves());

        $this->runFor($subscription->uuid);

        Http::assertSent(fn (Request $request): bool => $request['xRecurringIndicator'] === 'Recurring'
            && $request['xInvoice'] === 'S'.$subscription->id.'-20261101-1'
            && $request['xAmount'] === '20.00');
        $this->assertSame('2026-12-01 00:00:00', Carbon::parse($subscription->fresh()->ends_at)->toDateTimeString());
        Event::assertDispatched(SubscriptionRenewed::class);
    }

    public function test_running_twice_charges_once(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['ends_at' => '2026-11-01 00:00:00']);
        $this->fakeGateway($this->gatewayApproves());

        $this->runFor($subscription->uuid);
        $this->runFor($subscription->uuid);

        Http::assertSentCount(1);
    }

    public function test_existing_pending_charge_blocks_a_new_one(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['ends_at' => '2026-11-01 00:00:00']);
        $transaction = $this->pendingTransactionFor($subscription);
        CardknoxCharge::create(['transaction_id' => $transaction->id, 'invoice_base' => 'S'.$subscription->id.'-20261101', 'invoice' => 'S'.$subscription->id.'-20261101-1', 'purpose' => 'renewal', 'period_end' => '2026-11-01 00:00:00']);
        Http::fake();

        $this->runFor($subscription->uuid);

        Http::assertNothingSent();
    }

    public function test_not_yet_due_is_skipped(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['ends_at' => '2026-11-02 00:00:00']);
        Http::fake();

        $this->runFor($subscription->uuid);

        Http::assertNothingSent();
    }

    public function test_past_due_waits_for_next_retry_at(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), [
            'status' => SubscriptionStatus::PAST_DUE->value,
            'ends_at' => '2026-10-31 00:00:00',
            'extra_payment_provider_data' => ['failed_attempts' => 1, 'next_retry_at' => '2026-11-01 00:00:00'],
        ]);
        $this->fakeGateway($this->gatewayApproves());

        Carbon::setTestNow('2026-10-31 12:00:00');
        $this->runFor($subscription->uuid);
        Http::assertNothingSent();

        Carbon::setTestNow('2026-11-01 01:00:00');
        $this->runFor($subscription->uuid);
        Http::assertSentCount(1);
        $this->assertSame(SubscriptionStatus::ACTIVE->value, $subscription->fresh()->status);
    }

    public function test_cancel_at_end_of_cycle_cancels_instead_of_charging(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['ends_at' => '2026-11-01 00:00:00', 'is_canceled_at_end_of_cycle' => true]);
        Http::fake();

        $this->runFor($subscription->uuid);

        Http::assertNothingSent();
        $this->assertSame(SubscriptionStatus::CANCELED->value, $subscription->fresh()->status);
    }

    public function test_missing_card_counts_as_a_failed_attempt(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['ends_at' => '2026-11-01 00:00:00'], withCard: false);
        Http::fake();

        $this->runFor($subscription->uuid);

        Http::assertNothingSent();
        $this->assertSame(SubscriptionStatus::PAST_DUE->value, $subscription->fresh()->status);
        $this->assertSame(TransactionStatus::FAILED->value, Transaction::where('subscription_id', $subscription->id)->value('status'));
    }

    public function test_timeout_stays_pending_and_is_not_retried(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['ends_at' => '2026-11-01 00:00:00']);
        Http::fake(['x1.cardknox.com/*' => Http::failedConnection()]);

        $this->runFor($subscription->uuid);
        $this->runFor($subscription->uuid);

        Http::assertSentCount(1);
        $this->assertSame(TransactionStatus::PENDING->value, Transaction::where('subscription_id', $subscription->id)->value('status'));
        $this->assertSame(SubscriptionStatus::ACTIVE->value, $subscription->fresh()->status);
    }

    public function test_retry_outstanding_skips_the_wait(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), [
            'status' => SubscriptionStatus::PAST_DUE->value,
            'ends_at' => '2026-10-31 00:00:00',
            'extra_payment_provider_data' => ['failed_attempts' => 1, 'next_retry_at' => '2026-11-05 00:00:00'],
        ]);
        $this->fakeGateway($this->gatewayApproves());

        $result = app(\App\Services\PaymentProviders\Cardknox\CardknoxBillingService::class)->retryOutstanding($subscription);

        $this->assertTrue($result->isApproved());
        $this->assertSame(SubscriptionStatus::ACTIVE->value, $subscription->fresh()->status);
    }

    public function test_local_subscription_sweep_never_touches_cardknox_subscriptions(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['ends_at' => '2026-10-01 00:00:00']);

        app(SubscriptionService::class)->cleanupLocalSubscriptionStatuses();
        $this->artisan('app:issue-cash-renewal-orders')->assertSuccessful();

        $this->assertSame(SubscriptionStatus::ACTIVE->value, $subscription->fresh()->status);
        $this->assertSame(0, $subscription->orders()->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=ChargeCardknoxRenewalsTest`
Expected: FAIL — `The command "app:charge-cardknox-renewals" does not exist.`

- [ ] **Step 3: Add renewal methods to the billing service**

In `backend/app/Services/PaymentProviders/Cardknox/CardknoxBillingService.php` add imports:

```php
use App\Constants\PaymentProviderConstants;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
```

and add these public methods after `payOrder()`:

```php
    /**
     * Phase 1 (locked, committed): decide and open the pending charge.
     * Phase 2 (no transaction): call the gateway and settle. Splitting them
     * means a crash after the gateway approves still leaves a committed
     * pending transaction for reconciliation to settle.
     */
    public function chargeRenewal(Subscription $subscription, bool $force = false): ?CardknoxResult
    {
        $charge = DB::transaction(function () use ($subscription, $force) {
            $locked = Subscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();

            if (! $this->isDue($locked, $force)) {
                return null;
            }

            if ($locked->is_canceled_at_end_of_cycle) {
                $this->subscriptionService->updateSubscription($locked, [
                    'status' => SubscriptionStatus::CANCELED->value,
                    'cancelled_at' => now(),
                ]);

                return null;
            }

            $periodEnd = Carbon::parse($locked->ends_at);
            $amount = $this->pricing->renewalAmount($locked, $this->pricing->subInterval($locked, $periodEnd), $periodEnd);

            return $this->ledger->open(
                'S'.$locked->id.'-'.$periodEnd->format('Ymd'),
                CardknoxChargePurpose::Renewal,
                $locked,
                $amount->total,
                $amount->discount,
                $periodEnd,
                ['credit' => $amount->credit, 'discount_ids' => $amount->discountIdsUsed],
            );
        });

        if ($charge === null) {
            return null;
        }

        $card = $this->vault->cardFor($subscription->tenant_id, $subscription->user_id);

        if ($card === null) {
            $result = CardknoxResult::error(__('No card on file.'));
            $this->ledger->settle($charge, $result);

            return $result;
        }

        return $this->ledger->execute($charge, $card->card_token, merchantInitiated: true);
    }

    /** Used after the customer updates their card: retry an overdue renewal now. */
    public function retryOutstanding(Subscription $subscription): ?CardknoxResult
    {
        if ($subscription->status !== SubscriptionStatus::PAST_DUE->value) {
            return null;
        }

        return $this->chargeRenewal($subscription, force: true);
    }
```

and this private method next to `startsWithTrial()`:

```php
    private function isDue(Subscription $subscription, bool $force): bool
    {
        if ($subscription->type !== SubscriptionType::PAYMENT_PROVIDER_MANAGED
            || $subscription->ends_at === null
            || $subscription->paymentProvider?->slug !== PaymentProviderConstants::CARDKNOX_SLUG
            || Carbon::parse($subscription->ends_at)->isFuture()) {
            return false;
        }

        if ($subscription->status === SubscriptionStatus::ACTIVE->value) {
            return true;
        }

        if ($subscription->status !== SubscriptionStatus::PAST_DUE->value) {
            return false;
        }

        $nextRetryAt = $subscription->extra_payment_provider_data['next_retry_at'] ?? null;

        return $force || $nextRetryAt === null || Carbon::parse($nextRetryAt)->lte(now());
    }
```

Note: `chargeRenewal()` nests `ledger->open()`'s transaction inside its own (a savepoint). That is intended — the HTTP call happens only after the outer transaction commits.

- [ ] **Step 4: The command and schedule**

`backend/app/Console/Commands/Cardknox/ChargeCardknoxRenewals.php`:

```php
<?php

namespace App\Console\Commands\Cardknox;

use App\Constants\PaymentProviderConstants;
use App\Constants\SubscriptionStatus;
use App\Constants\SubscriptionType;
use App\Models\Subscription;
use App\Services\PaymentProviders\Cardknox\CardknoxBillingService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class ChargeCardknoxRenewals extends Command
{
    protected $signature = 'app:charge-cardknox-renewals {--subscription= : Only this subscription UUID}';

    protected $description = 'Charge Cardknox subscriptions whose billing period has ended, and retry past-due ones';

    public function handle(CardknoxBillingService $billing): int
    {
        $query = Subscription::query()
            ->where('type', SubscriptionType::PAYMENT_PROVIDER_MANAGED)
            ->whereHas('paymentProvider', fn (Builder $q) => $q->where('slug', PaymentProviderConstants::CARDKNOX_SLUG))
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $active) => $active->where('status', SubscriptionStatus::ACTIVE->value)->where('ends_at', '<=', now()))
                // Retry timing lives in JSON; CardknoxBillingService::isDue() applies it.
                ->orWhere('status', SubscriptionStatus::PAST_DUE->value));

        if ($uuid = $this->option('subscription')) {
            $query->where('uuid', $uuid);
        }

        $charged = 0;

        foreach ($query->lazyById() as $subscription) {
            try {
                if ($billing->chargeRenewal($subscription) !== null) {
                    $charged++;
                }
            } catch (Throwable $e) {
                // One broken subscription must not stop everyone else's renewal.
                report($e);
            }
        }

        $this->info("Processed {$charged} Cardknox renewal attempt(s).");

        return self::SUCCESS;
    }
}
```

`backend/routes/console.php` — add after the `app:expire-pending-cash-orders` line:

```php
Schedule::command('app:charge-cardknox-renewals')->hourly()->withoutOverlapping()->onOneServer();
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=ChargeCardknoxRenewalsTest`
Expected: 10 passed.

- [ ] **Step 6: Commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint app/Services/PaymentProviders/Cardknox/CardknoxBillingService.php app/Console/Commands/Cardknox routes/console.php tests/Feature/Console/Cardknox
git add backend/app/Services/PaymentProviders/Cardknox/CardknoxBillingService.php backend/app/Console/Commands/Cardknox/ChargeCardknoxRenewals.php backend/routes/console.php backend/tests/Feature/Console/Cardknox/ChargeCardknoxRenewalsTest.php
git commit -m "feat(payments): hourly Cardknox renewal charging with retry ladder"
```

---
### Task 7: Plan and seat changes with proration

**Files:**
- Modify: `backend/app/Services/PaymentProviders/Cardknox/CardknoxBillingService.php`
- Test: `backend/tests/Feature/Services/PaymentProviders/Cardknox/CardknoxPlanChangeTest.php`

**Interfaces:**
- Consumes: `CardknoxPricing::recurringSubtotal/planPrice/prorationAmount`, `CardknoxChargeLedger::open/execute/mergeData`.
- Produces: `CardknoxBillingService::changePlan(Subscription $subscription, Plan $newPlan, bool $prorate): bool`, `changeQuantity(Subscription $subscription, int $quantity, bool $prorate): bool`.

- [ ] **Step 1: Write the failing test**

`backend/tests/Feature/Services/PaymentProviders/Cardknox/CardknoxPlanChangeTest.php`:

```php
<?php

namespace Tests\Feature\Services\PaymentProviders\Cardknox;

use App\Constants\PlanType;
use App\Services\PaymentProviders\Cardknox\CardknoxBillingService;
use Carbon\Carbon;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTest;
use Tests\Support\CardknoxTestHelpers;

class CardknoxPlanChangeTest extends FeatureTest
{
    use CardknoxTestHelpers;

    private CardknoxBillingService $billing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureCardknox();
        $this->billing = app(CardknoxBillingService::class);
        // Period 2026-11-01 → 2026-12-01 (30 days), 15 days remain.
        Carbon::setTestNow('2026-11-16 00:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_prorated_upgrade_charges_the_difference_now_and_switches(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(price: ['price' => 3000]), ['ends_at' => '2026-12-01 00:00:00']);
        $target = $this->cardknoxPlan(price: ['price' => 5000]);
        $this->fakeGateway($this->gatewayApproves());

        $this->assertTrue($this->billing->changePlan($subscription, $target, prorate: true));

        Http::assertSent(fn (Request $request): bool => $request['xAmount'] === '10.00' && ! isset($request['xRecurringIndicator']));
        $fresh = $subscription->fresh();
        $this->assertSame($target->id, $fresh->plan_id);
        $this->assertSame('2026-12-01 00:00:00', Carbon::parse($fresh->ends_at)->toDateTimeString(), 'billing period unchanged');
    }

    public function test_declined_upgrade_keeps_the_old_plan(): void
    {
        $plan = $this->cardknoxPlan(price: ['price' => 3000]);
        $subscription = $this->cardknoxSubscription($plan, ['ends_at' => '2026-12-01 00:00:00']);
        $this->fakeGateway($this->gatewayDeclines());

        $this->assertFalse($this->billing->changePlan($subscription, $this->cardknoxPlan(price: ['price' => 5000]), prorate: true));

        $this->assertSame($plan->id, $subscription->fresh()->plan_id);
    }

    public function test_prorated_downgrade_banks_credit_without_a_refund(): void
    {
        Http::fake();
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(price: ['price' => 3000]), ['ends_at' => '2026-12-01 00:00:00']);
        $target = $this->cardknoxPlan(price: ['price' => 2000]);

        $this->assertTrue($this->billing->changePlan($subscription, $target, prorate: true));

        Http::assertNothingSent();
        $fresh = $subscription->fresh();
        $this->assertSame($target->id, $fresh->plan_id);
        $this->assertSame(500, $fresh->extra_payment_provider_data['pending_credit']);
    }

    public function test_unprorated_change_switches_immediately_without_charging(): void
    {
        Http::fake();
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(price: ['price' => 3000]), ['ends_at' => '2026-12-01 00:00:00']);
        $target = $this->cardknoxPlan(price: ['price' => 9000]);

        $this->assertTrue($this->billing->changePlan($subscription, $target, prorate: false));

        Http::assertNothingSent();
        $this->assertSame($target->id, $subscription->fresh()->plan_id);
        $this->assertSame(9000, (int) $subscription->fresh()->price);
    }

    public function test_changes_during_a_trial_are_never_prorated(): void
    {
        Http::fake();
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(price: ['price' => 3000]), ['ends_at' => '2026-11-20 00:00:00', 'trial_ends_at' => '2026-11-20 00:00:00']);

        $this->assertTrue($this->billing->changePlan($subscription, $this->cardknoxPlan(price: ['price' => 9000]), prorate: true));

        Http::assertNothingSent();
    }

    public function test_prorated_seat_increase_charges_for_the_added_seats(): void
    {
        $plan = $this->cardknoxPlan(['type' => PlanType::SEAT_BASED->value], ['price' => 1000]);
        $subscription = $this->cardknoxSubscription($plan, ['ends_at' => '2026-12-01 00:00:00', 'quantity' => 2]);
        $this->fakeGateway($this->gatewayApproves());

        $this->assertTrue($this->billing->changeQuantity($subscription, 4, prorate: true));

        Http::assertSent(fn (Request $request): bool => $request['xAmount'] === '10.00');
        $this->assertSame(4, (int) $subscription->fresh()->quantity);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=CardknoxPlanChangeTest`
Expected: FAIL — `Call to undefined method ...CardknoxBillingService::changePlan()`.

- [ ] **Step 3: Implement**

In `CardknoxBillingService.php` add imports:

```php
use App\Constants\PlanType;
use App\Models\Plan;
```

Add after `retryOutstanding()`:

```php
    public function changePlan(Subscription $subscription, Plan $newPlan, bool $prorate): bool
    {
        // Seat plans bill the tenant's member count, as CalculationService::calculateNewPlanTotals() does.
        $quantity = $newPlan->type === PlanType::SEAT_BASED->value
            ? max(1, $subscription->tenant->users()->count())
            : max(1, (int) $subscription->quantity);

        $newPrice = $this->pricing->planPrice($newPlan, $subscription->currency_id);
        $oldSubtotal = $this->pricing->recurringSubtotal($subscription->plan, $subscription->currency_id, max(1, (int) $subscription->quantity));
        $newSubtotal = $this->pricing->recurringSubtotal($newPlan, $subscription->currency_id, $quantity);

        return $this->applyChange($subscription, $oldSubtotal, $newSubtotal, $prorate, [
            'plan_id' => $newPlan->id,
            'price' => $newPrice->price,
            'quantity' => $quantity,
            'interval_id' => $newPlan->interval_id,
            'interval_count' => $newPlan->interval_count,
            'price_type' => $newPrice->type,
            'price_tiers' => $newPrice->tiers,
            'price_per_unit' => $newPrice->price_per_unit,
        ]);
    }

    public function changeQuantity(Subscription $subscription, int $quantity, bool $prorate): bool
    {
        $oldSubtotal = $this->pricing->recurringSubtotal($subscription->plan, $subscription->currency_id, max(1, (int) $subscription->quantity));
        $newSubtotal = $this->pricing->recurringSubtotal($subscription->plan, $subscription->currency_id, $quantity);

        return $this->applyChange($subscription, $oldSubtotal, $newSubtotal, $prorate, ['quantity' => $quantity]);
    }
```

Add private method after `isDue()`:

```php
    /**
     * Upgrade: charge the prorated difference now; the change is applied by
     * the ledger only when that charge settles approved. Downgrade: apply now
     * and bank the difference as credit for the next renewal (no card refund).
     */
    private function applyChange(Subscription $subscription, int $oldSubtotal, int $newSubtotal, bool $prorate, array $change): bool
    {
        $onTrial = $subscription->trial_ends_at !== null && Carbon::parse($subscription->trial_ends_at)->isFuture();

        if (! $prorate || $onTrial) {
            $this->subscriptionService->updateSubscription($subscription, $change);

            return true;
        }

        $delta = $this->pricing->prorationAmount($subscription, $oldSubtotal, $newSubtotal, now());

        if ($delta <= 0) {
            $credit = (int) ($subscription->extra_payment_provider_data['pending_credit'] ?? 0) + abs($delta);

            $this->subscriptionService->updateSubscription($subscription, $change + [
                'extra_payment_provider_data' => CardknoxChargeLedger::mergeData($subscription, ['pending_credit' => $credit]),
            ]);

            return true;
        }

        $card = $this->vault->cardFor($subscription->tenant_id, $subscription->user_id);

        if ($card === null) {
            return false;
        }

        $charge = $this->ledger->open(
            'S'.$subscription->id.'-P'.now()->timestamp,
            CardknoxChargePurpose::Proration,
            $subscription,
            $delta,
            meta: ['change' => $change],
        );

        if ($charge === null) {
            return false;
        }

        return $this->ledger->execute($charge, $card->card_token, merchantInitiated: false)->isApproved();
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=CardknoxPlanChangeTest`
Expected: 6 passed.

- [ ] **Step 5: Commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint app/Services/PaymentProviders/Cardknox/CardknoxBillingService.php tests/Feature/Services/PaymentProviders/Cardknox/CardknoxPlanChangeTest.php
git add backend/app/Services/PaymentProviders/Cardknox/CardknoxBillingService.php backend/tests/Feature/Services/PaymentProviders/Cardknox/CardknoxPlanChangeTest.php
git commit -m "feat(payments): Cardknox plan and seat changes with proration and downgrade credit"
```

---
### Task 8: Hosted pay page (iFields) — subscription, order, update card

**Files:**
- Create: `backend/app/Http/Requests/CardknoxCardRequest.php`
- Create: `backend/app/Http/Controllers/PaymentProviders/CardknoxPaymentController.php`
- Create: `backend/resources/views/payment-providers/cardknox/pay.blade.php`
- Modify: `backend/routes/web.php`
- Test: `backend/tests/Feature/Http/Controllers/PaymentProviders/CardknoxPaymentControllerTest.php`

**Interfaces:**
- Consumes: `CardknoxCardVault::tokenize/remember/cardFor`, `CardknoxBillingService::quoteFirstCharge/startSubscription/payOrder/retryOutstanding`, `CardknoxConfiguration::ifieldsKey/ifieldsVersion`, global `money(int, string)` helper (used in `resources/views/livewire/checkout/*.blade.php`).
- Produces route names (all `auth` + `signed`, URLs built with `URL::temporarySignedRoute`):
  - `payments-providers.cardknox.pay.subscription` / `.pay.subscription.submit` — params `subscriptionUuid`, optional query `quantity`, `discount` (Discount id).
  - `payments-providers.cardknox.pay.order` / `.pay.order.submit` — param `orderUuid`.
  - `payments-providers.cardknox.card` / `.card.submit` — param `subscriptionUuid`.
- Form fields posted: `xCardNum` (iFields card SUT), `xCVV` (iFields CVV SUT), `exp_month` (2 digits), `exp_year` (2 digits), `save_card` (orders only).

- [ ] **Step 1: Write the failing test**

`backend/tests/Feature/Http/Controllers/PaymentProviders/CardknoxPaymentControllerTest.php`:

```php
<?php

namespace Tests\Feature\Http\Controllers\PaymentProviders;

use App\Constants\SubscriptionStatus;
use App\Models\Order;
use App\Models\UserCardknoxData;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\Feature\FeatureTest;
use Tests\Support\CardknoxTestHelpers;

class CardknoxPaymentControllerTest extends FeatureTest
{
    use CardknoxTestHelpers;

    private const CARD = ['xCardNum' => 'sut_card', 'xCVV' => 'sut_cvv', 'exp_month' => '12', 'exp_year' => '30'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureCardknox();
    }

    private function signed(string $route, array $parameters): string
    {
        return URL::temporarySignedRoute($route, now()->addHour(), $parameters);
    }

    public function test_page_requires_a_valid_signature(): void
    {
        $this->withExceptionHandling();
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['status' => SubscriptionStatus::NEW->value], withCard: false);

        $this->actingAs($subscription->user)
            ->get(route('payments-providers.cardknox.pay.subscription', ['subscriptionUuid' => $subscription->uuid]))
            ->assertForbidden();
    }

    public function test_page_is_forbidden_to_another_user(): void
    {
        $this->withExceptionHandling();
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['status' => SubscriptionStatus::NEW->value], withCard: false);
        $intruder = $this->createUser($this->createTenant());

        $this->actingAs($intruder)
            ->get($this->signed('payments-providers.cardknox.pay.subscription', ['subscriptionUuid' => $subscription->uuid]))
            ->assertForbidden();
    }

    public function test_page_renders_ifields_and_the_amount(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(price: ['price' => 4900]), ['status' => SubscriptionStatus::NEW->value], withCard: false);

        $this->actingAs($subscription->user)
            ->get($this->signed('payments-providers.cardknox.pay.subscription', ['subscriptionUuid' => $subscription->uuid, 'quantity' => 1]))
            ->assertOk()
            ->assertSee('data-ifields-id="card-number"', false)
            ->assertSee('ifields_test_key')
            ->assertSee('49.00');
    }

    public function test_paying_activates_the_subscription_and_redirects_to_success(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['status' => SubscriptionStatus::NEW->value], withCard: false);
        $this->fakeGateway($this->gatewayApproves(['xToken' => 'tok_saved']), $this->gatewayApproves());

        $this->actingAs($subscription->user)
            ->post($this->signed('payments-providers.cardknox.pay.subscription.submit', ['subscriptionUuid' => $subscription->uuid, 'quantity' => 1]), self::CARD)
            ->assertRedirect(route('checkout.subscription.success'));

        $this->assertSame(SubscriptionStatus::ACTIVE->value, $subscription->fresh()->status);
        $this->assertSame('tok_saved', UserCardknoxData::where('user_id', $subscription->user_id)->first()->card_token);
    }

    public function test_decline_returns_to_the_form_with_the_reason(): void
    {
        $this->withExceptionHandling();
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['status' => SubscriptionStatus::NEW->value], withCard: false);
        $this->fakeGateway($this->gatewayApproves(), $this->gatewayDeclines('Do not honor'));
        $page = $this->signed('payments-providers.cardknox.pay.subscription', ['subscriptionUuid' => $subscription->uuid]);

        $this->actingAs($subscription->user)
            ->from($page)
            ->post($this->signed('payments-providers.cardknox.pay.subscription.submit', ['subscriptionUuid' => $subscription->uuid]), self::CARD)
            ->assertRedirect($page)
            ->assertSessionHasErrors(['card' => 'Do not honor']);

        $this->assertSame(SubscriptionStatus::NEW->value, $subscription->fresh()->status);
    }

    public function test_order_without_save_card_keeps_existing_card(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan());
        $existing = UserCardknoxData::where('user_id', $subscription->user_id)->first()->card_token;
        $order = Order::factory()->create(['user_id' => $subscription->user_id, 'tenant_id' => $subscription->tenant_id, 'total_amount' => 4900, 'total_amount_after_discount' => 4900, 'total_discount_amount' => 0]);
        $this->fakeGateway($this->gatewayApproves(['xToken' => 'tok_one_time']), $this->gatewayApproves());

        $this->actingAs($subscription->user)
            ->post($this->signed('payments-providers.cardknox.pay.order.submit', ['orderUuid' => $order->uuid]), self::CARD)
            ->assertRedirect(route('checkout.product.success'));

        $this->assertSame($existing, UserCardknoxData::where('user_id', $subscription->user_id)->first()->card_token);
        Http::assertSent(fn ($request): bool => ($request['xToken'] ?? null) === 'tok_one_time');
    }

    public function test_order_with_save_card_replaces_the_card(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan());
        $order = Order::factory()->create(['user_id' => $subscription->user_id, 'tenant_id' => $subscription->tenant_id, 'total_amount' => 4900, 'total_amount_after_discount' => 4900, 'total_discount_amount' => 0]);
        $this->fakeGateway($this->gatewayApproves(['xToken' => 'tok_new']), $this->gatewayApproves());

        $this->actingAs($subscription->user)
            ->post($this->signed('payments-providers.cardknox.pay.order.submit', ['orderUuid' => $order->uuid]), self::CARD + ['save_card' => '1']);

        $this->assertSame('tok_new', UserCardknoxData::where('user_id', $subscription->user_id)->first()->card_token);
    }

    public function test_updating_the_card_retries_a_past_due_renewal(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), [
            'status' => SubscriptionStatus::PAST_DUE->value,
            'ends_at' => now()->subDay(),
            'extra_payment_provider_data' => ['failed_attempts' => 1, 'next_retry_at' => now()->addDays(2)->toDateTimeString()],
        ]);
        $this->fakeGateway($this->gatewayApproves(['xToken' => 'tok_fresh']), $this->gatewayApproves());

        $this->actingAs($subscription->user)
            ->post($this->signed('payments-providers.cardknox.card.submit', ['subscriptionUuid' => $subscription->uuid]), self::CARD)
            ->assertRedirect();

        $this->assertSame(SubscriptionStatus::ACTIVE->value, $subscription->fresh()->status);
        $this->assertSame('tok_fresh', UserCardknoxData::where('user_id', $subscription->user_id)->first()->card_token);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=CardknoxPaymentControllerTest`
Expected: FAIL — `Route [payments-providers.cardknox.pay.subscription] not defined.`

- [ ] **Step 3: Form request**

`backend/app/Http/Requests/CardknoxCardRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CardknoxCardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            // iFields single-use tokens, not card data.
            'xCardNum' => ['required', 'string', 'max:4096'],
            'xCVV' => ['required', 'string', 'max:4096'],
            'exp_month' => ['required', 'digits:2'],
            'exp_year' => ['required', 'digits:2'],
            'save_card' => ['nullable', 'boolean'],
        ];
    }

    /** MMYY, the format Cardknox expects in xExp. */
    public function exp(): string
    {
        return $this->string('exp_month')->toString().$this->string('exp_year')->toString();
    }
}
```

- [ ] **Step 4: Controller**

`backend/app/Http/Controllers/PaymentProviders/CardknoxPaymentController.php`:

```php
<?php

namespace App\Http\Controllers\PaymentProviders;

use App\Constants\OrderStatus;
use App\Constants\SubscriptionStatus;
use App\Constants\SubscriptionType;
use App\Filament\Dashboard\Resources\Subscriptions\SubscriptionResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\CardknoxCardRequest;
use App\Models\Discount;
use App\Models\Order;
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
        $price = (string) money($amount, $subscription->currency->code);

        return $this->page(
            title: $subscription->plan->name,
            summary: $amount === 0
                ? __('Nothing is charged today. Your card is saved for future renewals.')
                : __('You will be charged :amount today.', ['amount' => $price]),
            action: $this->signedAction('payments-providers.cardknox.pay.subscription.submit', $request, ['subscriptionUuid' => $subscription->uuid]),
            buttonLabel: $amount === 0 ? __('Save card') : __('Pay :amount', ['amount' => $price]),
            cancelUrl: $subscription->type === SubscriptionType::LOCALLY_MANAGED
                ? route('checkout.convert-local-subscription', ['subscriptionUuid' => $subscription->uuid])
                : route('checkout.subscription', ['planSlug' => $subscription->plan->slug]),
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
        $price = (string) money((int) $order->total_amount_after_discount, $order->currency->code);

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
```

- [ ] **Step 5: View**

`backend/resources/views/payment-providers/cardknox/pay.blade.php`:

```blade
<x-layouts.app>
    @push('head')
        <script src="https://cdn.cardknox.com/ifields/{{ $ifieldsVersion }}/ifields.min.js"></script>
    @endpush

    <div class="mx-auto w-full max-w-md px-4 py-16">
        <h1 class="text-2xl font-semibold text-cream-100">{{ $title }}</h1>
        <p class="mt-2 text-cream-300">{{ $summary }}</p>

        @if ($errors->any())
            <div class="mt-6 rounded-md border border-red-500/40 bg-red-500/10 p-3 text-sm text-red-200" role="alert">
                {{ $errors->first() }}
            </div>
        @endif

        <form id="cardknox-form" method="POST" action="{{ $action }}" class="mt-8 space-y-4">
            @csrf

            <div>
                <span class="block text-sm text-cream-300">{{ __('Card number') }}</span>
                <iframe data-ifields-id="card-number" data-ifields-placeholder="{{ __('Card number') }}"
                        src="https://cdn.cardknox.com/ifields/{{ $ifieldsVersion }}/ifield.htm"
                        title="{{ __('Card number') }}" class="mt-1 h-12 w-full rounded-md bg-cream-50"></iframe>
                <input data-ifields-id="card-number-token" name="xCardNum" type="hidden">
            </div>

            <div class="grid grid-cols-3 gap-3">
                <label class="block text-sm text-cream-300">{{ __('Month') }}
                    <input name="exp_month" inputmode="numeric" maxlength="2" placeholder="MM" required
                           class="mt-1 h-12 w-full rounded-md bg-cream-50 px-3 text-ink">
                </label>
                <label class="block text-sm text-cream-300">{{ __('Year') }}
                    <input name="exp_year" inputmode="numeric" maxlength="2" placeholder="YY" required
                           class="mt-1 h-12 w-full rounded-md bg-cream-50 px-3 text-ink">
                </label>
                <div>
                    <span class="block text-sm text-cream-300">{{ __('CVV') }}</span>
                    <iframe data-ifields-id="cvv" data-ifields-placeholder="CVV"
                            src="https://cdn.cardknox.com/ifields/{{ $ifieldsVersion }}/ifield.htm"
                            title="{{ __('CVV') }}" class="mt-1 h-12 w-full rounded-md bg-cream-50"></iframe>
                    <input data-ifields-id="cvv-token" name="xCVV" type="hidden">
                </div>
            </div>

            @if ($offerSaveCard)
                <label class="flex items-center gap-2 text-sm text-cream-300">
                    <input type="checkbox" name="save_card" value="1">
                    {{ __('Save this card for future payments') }}
                </label>
            @endif

            <p data-ifields-id="card-data-error" class="text-sm text-red-300" role="alert"></p>

            <div class="flex items-center gap-4 pt-2">
                <button id="cardknox-submit" type="submit"
                        class="rounded-md bg-amber-400 px-5 py-3 font-semibold text-ink disabled:opacity-50">
                    {{ $buttonLabel }}
                </button>
                <a href="{{ $cancelUrl }}" class="text-sm text-cream-300 underline">{{ __('Cancel') }}</a>
            </div>
        </form>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            setAccount(@js($ifieldsKey), @js(config('app.name')), '1.0');

            const form = document.getElementById('cardknox-form');
            const button = document.getElementById('cardknox-submit');

            form.addEventListener('submit', function (event) {
                event.preventDefault();
                button.disabled = true;

                // Swaps the iframe contents for single-use tokens in the hidden inputs.
                getTokens(
                    function () { form.submit(); },
                    function () { button.disabled = false; },
                    30000
                );
            });
        });
    </script>
</x-layouts.app>
```

- [ ] **Step 6: Routes**

`backend/routes/web.php` — add the import next to the other payment-provider controller import:

```php
use App\Http\Controllers\PaymentProviders\CardknoxPaymentController;
```

and add after the `payment-link.paddle` route:

```php
Route::middleware(['auth', 'signed'])->prefix('/payments-providers/cardknox')->group(function () {
    Route::get('/pay/subscription/{subscriptionUuid}', [CardknoxPaymentController::class, 'showSubscription'])->name('payments-providers.cardknox.pay.subscription');
    Route::post('/pay/subscription/{subscriptionUuid}', [CardknoxPaymentController::class, 'paySubscription'])->name('payments-providers.cardknox.pay.subscription.submit');
    Route::get('/pay/order/{orderUuid}', [CardknoxPaymentController::class, 'showOrder'])->name('payments-providers.cardknox.pay.order');
    Route::post('/pay/order/{orderUuid}', [CardknoxPaymentController::class, 'payOrder'])->name('payments-providers.cardknox.pay.order.submit');
    Route::get('/card/{subscriptionUuid}', [CardknoxPaymentController::class, 'showUpdateCard'])->name('payments-providers.cardknox.card');
    Route::post('/card/{subscriptionUuid}', [CardknoxPaymentController::class, 'updateCard'])->name('payments-providers.cardknox.card.submit');
});
```

- [ ] **Step 7: Run test to verify it passes**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=CardknoxPaymentControllerTest`
Expected: 8 passed. If `test_updating_the_card_retries_a_past_due_renewal` fails inside `SubscriptionResource::getUrl(tenant: ...)`, call `Filament::setCurrentPanel(Filament::getPanel('dashboard'))` before building the URL in the controller (the dashboard panel id is in `app/Providers/Filament/DashboardPanelProvider.php`).

- [ ] **Step 8: Commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint app/Http/Requests/CardknoxCardRequest.php app/Http/Controllers/PaymentProviders/CardknoxPaymentController.php routes/web.php tests/Feature/Http/Controllers/PaymentProviders/CardknoxPaymentControllerTest.php
git add backend/app/Http/Requests/CardknoxCardRequest.php backend/app/Http/Controllers/PaymentProviders/CardknoxPaymentController.php backend/resources/views/payment-providers/cardknox/pay.blade.php backend/routes/web.php backend/tests/Feature/Http/Controllers/PaymentProviders/CardknoxPaymentControllerTest.php
git commit -m "feat(payments): hosted Cardknox iFields pay page for subscriptions, orders and card updates"
```

---
### Task 9: `CardknoxProvider` and registration

**Files:**
- Create: `backend/app/Services/PaymentProviders/Cardknox/CardknoxProvider.php`
- Modify: `backend/app/Providers/AppServiceProvider.php` (tagged provider list, lines ~47-53)
- Test: `backend/tests/Feature/Services/PaymentProviders/Cardknox/CardknoxProviderTest.php`

**Interfaces:**
- Consumes: `CardknoxBillingService::changePlan/changeQuantity`, route names from Task 8, `CurrencyService::getCurrency()`.
- Produces: `CardknoxProvider implements PaymentProviderInterface`, resolvable through `PaymentService::getPaymentProviderBySlug('cardknox')`.

- [ ] **Step 1: Write the failing test**

`backend/tests/Feature/Services/PaymentProviders/Cardknox/CardknoxProviderTest.php`:

```php
<?php

namespace Tests\Feature\Services\PaymentProviders\Cardknox;

use App\Constants\PlanType;
use App\Models\Order;
use App\Services\PaymentProviders\Cardknox\CardknoxProvider;
use App\Services\PaymentProviders\PaymentService;
use App\Services\SubscriptionUsageService;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTest;
use Tests\Support\CardknoxTestHelpers;

class CardknoxProviderTest extends FeatureTest
{
    use CardknoxTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureCardknox();
    }

    private function provider(): CardknoxProvider
    {
        return app(PaymentService::class)->getPaymentProviderBySlug('cardknox');
    }

    public function test_it_is_registered_as_a_redirect_provider_with_full_capabilities(): void
    {
        $provider = $this->provider();

        $this->assertInstanceOf(CardknoxProvider::class, $provider);
        $this->assertTrue($provider->isRedirectProvider());
        $this->assertFalse($provider->isOverlayProvider());
        $this->assertTrue($provider->supportsSkippingTrial());
        $this->assertTrue($provider->supportsSeatBasedWithIncludedSeats());
        $this->assertTrue($provider->supportsOneTimePurchaseProductQuantity());
        $this->assertTrue($provider->supportsSetupFees());
        $this->assertSame('Cardknox', $provider->getName());
    }

    public function test_supports_every_plan_type_priced_in_usd(): void
    {
        foreach ([PlanType::FLAT_RATE, PlanType::SEAT_BASED, PlanType::USAGE_BASED] as $type) {
            $this->assertTrue($this->provider()->supportsPlan($this->cardknoxPlan(['type' => $type->value])));
        }
    }

    public function test_checkout_links_are_signed_pay_page_urls_carrying_quantity_and_discount(): void
    {
        $plan = $this->cardknoxPlan();
        $subscription = $this->cardknoxSubscription($plan);
        $order = Order::factory()->create(['user_id' => $subscription->user_id, 'tenant_id' => $subscription->tenant_id]);

        $subscriptionLink = $this->provider()->createSubscriptionCheckoutRedirectLink($plan, $subscription, null, 3);
        $orderLink = $this->provider()->createProductCheckoutRedirectLink($order);
        $cardLink = $this->provider()->getChangePaymentMethodLink($subscription);

        $this->assertStringContainsString('/payments-providers/cardknox/pay/subscription/'.$subscription->uuid, $subscriptionLink);
        $this->assertStringContainsString('quantity=3', $subscriptionLink);
        $this->assertStringContainsString('signature=', $subscriptionLink);
        $this->assertStringContainsString('/payments-providers/cardknox/pay/order/'.$order->uuid, $orderLink);
        $this->assertStringContainsString('/payments-providers/cardknox/card/'.$subscription->uuid, $cardLink);
    }

    public function test_cancel_discard_discount_and_usage_are_local(): void
    {
        Http::fake();
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(['type' => PlanType::USAGE_BASED->value]));

        $this->assertTrue($this->provider()->cancelSubscription($subscription));
        $this->assertTrue($this->provider()->discardSubscriptionCancellation($subscription));
        $this->assertTrue($this->provider()->reportUsage($subscription, 5));

        $this->assertTrue(app(SubscriptionUsageService::class)->reportUsage(5, $subscription));
        $this->assertSame(5, (int) $subscription->usages()->sum('unit_count'));

        Http::assertNothingSent();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=CardknoxProviderTest`
Expected: FAIL — `getPaymentProviderBySlug` throws because no provider has slug `cardknox`.

- [ ] **Step 3: Implement the provider**

`backend/app/Services/PaymentProviders/Cardknox/CardknoxProvider.php`:

```php
<?php

namespace App\Services\PaymentProviders\Cardknox;

use App\Constants\PaymentProviderConstants;
use App\Constants\PlanType;
use App\Models\Discount;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\CurrencyService;
use App\Services\PaymentProviders\PaymentProviderInterface;
use Exception;
use Illuminate\Support\Facades\URL;

/**
 * Cardknox is a card gateway, not a subscription platform: it redirects to our
 * own iFields pay page, and the app bills via CardknoxBillingService.
 */
class CardknoxProvider implements PaymentProviderInterface
{
    public function __construct(
        private CardknoxBillingService $billing,
        private CurrencyService $currencyService,
    ) {}

    public function getSlug(): string
    {
        return PaymentProviderConstants::CARDKNOX_SLUG;
    }

    public function getName(): string
    {
        return PaymentProvider::where('slug', $this->getSlug())->firstOrFail()->name;
    }

    public function createSubscriptionCheckoutRedirectLink(Plan $plan, Subscription $subscription, ?Discount $discount = null, int $quantity = 1): string
    {
        $this->assertProviderIsActive();

        return URL::temporarySignedRoute('payments-providers.cardknox.pay.subscription', now()->addHours(2), array_filter([
            'subscriptionUuid' => $subscription->uuid,
            'quantity' => $quantity,
            'discount' => $discount?->id,
        ], fn ($value) => $value !== null));
    }

    public function createProductCheckoutRedirectLink(Order $order, ?Discount $discount = null): string
    {
        $this->assertProviderIsActive();

        // The order totals already include the discount.
        return URL::temporarySignedRoute('payments-providers.cardknox.pay.order', now()->addHours(2), ['orderUuid' => $order->uuid]);
    }

    public function initSubscriptionCheckout(Plan $plan, Subscription $subscription, ?Discount $discount = null, int $quantity = 1): array
    {
        return [];
    }

    public function initProductCheckout(Order $order, ?Discount $discount = null): array
    {
        return [];
    }

    public function isRedirectProvider(): bool
    {
        return true;
    }

    public function isOverlayProvider(): bool
    {
        return false;
    }

    public function changePlan(Subscription $subscription, Plan $newPlan, bool $withProration = false): bool
    {
        $this->assertProviderIsActive();

        return $this->billing->changePlan($subscription, $newPlan, $withProration);
    }

    // SubscriptionService sets is_canceled_at_end_of_cycle once this returns
    // true; the renewal command then cancels at ends_at instead of charging.
    public function cancelSubscription(Subscription $subscription): bool
    {
        return true;
    }

    public function discardSubscriptionCancellation(Subscription $subscription): bool
    {
        return true;
    }

    public function getChangePaymentMethodLink(Subscription $subscription): string
    {
        return URL::temporarySignedRoute('payments-providers.cardknox.card', now()->addHours(2), ['subscriptionUuid' => $subscription->uuid]);
    }

    // DiscountService::redeemCodeForSubscription() writes the SubscriptionDiscount
    // row that renewals read; there is nothing to create on the gateway.
    public function addDiscountToSubscription(Subscription $subscription, Discount $discount): bool
    {
        return true;
    }

    public function updateSubscriptionQuantity(Subscription $subscription, int $quantity, bool $isProrated = true): bool
    {
        $this->assertProviderIsActive();

        return $this->billing->changeQuantity($subscription, $quantity, $isProrated);
    }

    public function supportsPlan(Plan $plan): bool
    {
        if (! in_array($plan->type, [PlanType::FLAT_RATE->value, PlanType::USAGE_BASED->value, PlanType::SEAT_BASED->value], true)) {
            return false;
        }

        $currency = $this->currencyService->getCurrency();

        return $currency->code === 'USD' && $plan->prices()->where('currency_id', $currency->id)->exists();
    }

    // SubscriptionUsageService records a SubscriptionUsage row when this
    // returns true; renewals sum those rows for the period.
    public function reportUsage(Subscription $subscription, int $unitCount): bool
    {
        return true;
    }

    public function supportsSkippingTrial(): bool
    {
        return true;
    }

    public function supportsSeatBasedWithIncludedSeats(): bool
    {
        return true;
    }

    public function supportsOneTimePurchaseProductQuantity(): bool
    {
        return true;
    }

    public function supportsSetupFees(): bool
    {
        return true;
    }

    private function assertProviderIsActive(): PaymentProvider
    {
        $paymentProvider = PaymentProvider::where('slug', $this->getSlug())->firstOrFail();

        if ($paymentProvider->is_active === false) {
            throw new Exception('Payment provider is not active: '.$this->getSlug());
        }

        return $paymentProvider;
    }
}
```

- [ ] **Step 4: Register it**

`backend/app/Providers/AppServiceProvider.php` — add the import:

```php
use App\Services\PaymentProviders\Cardknox\CardknoxProvider;
```

and add `CardknoxProvider::class,` to the tagged `payment-providers` list, after `PolarProvider::class,`.

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=CardknoxProviderTest`
Expected: 4 passed.

- [ ] **Step 6: Run the existing checkout and payment suites for regressions**

Run: `docker compose exec -T laravel.test php artisan test --compact tests/Feature/Http/Controllers tests/Feature/Livewire tests/Feature/Services`
Expected: all pass. A failure listing Cardknox in a provider list means a test helper left it active — check that the test class uses `CardknoxTestHelpers`.

- [ ] **Step 7: Commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint app/Services/PaymentProviders/Cardknox/CardknoxProvider.php app/Providers/AppServiceProvider.php tests/Feature/Services/PaymentProviders/Cardknox/CardknoxProviderTest.php
git add backend/app/Services/PaymentProviders/Cardknox/CardknoxProvider.php backend/app/Providers/AppServiceProvider.php backend/tests/Feature/Services/PaymentProviders/Cardknox/CardknoxProviderTest.php
git commit -m "feat(payments): register CardknoxProvider as a redirect payment provider"
```

---
### Task 10: Webhook endpoint, processing job, refunds and disputes

**Files:**
- Create: `backend/app/Services/PaymentProviders/Cardknox/CardknoxWebhookHandler.php`
- Create: `backend/app/Jobs/ProcessCardknoxWebhook.php`
- Create: `backend/app/Http/Controllers/PaymentProviders/CardknoxController.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/app/Services/PaymentProviders/Cardknox/CardknoxChargeLedger.php` (add `applyWebhook()`)
- Modify: `backend/app/Services/PaymentProviders/Cardknox/CardknoxBillingService.php` (add `refund()`)
- Test: `backend/tests/Feature/Http/Controllers/PaymentProviders/CardknoxControllerTest.php`

**Interfaces:**
- Consumes: `CardknoxConfiguration::webhookPin/isValidPin`, `CardknoxChargeLedger::settle/markRefunded`, `CardknoxClient::refund/void`.
- Produces:
  - Route `payments-providers.cardknox.webhook` (`POST /api/payments-providers/cardknox/webhook`, under the `api` prefix like the others).
  - `CardknoxWebhookHandler::handleWebhook(Request $request): JsonResponse`, `static signature(array $fields, string $pin): string`.
  - `ProcessCardknoxWebhook(array $fields)` queued job.
  - `CardknoxChargeLedger::applyWebhook(array $fields): void`.
  - `CardknoxBillingService::refund(Transaction $transaction): CardknoxResult`.

- [ ] **Step 1: Write the failing test**

`backend/tests/Feature/Http/Controllers/PaymentProviders/CardknoxControllerTest.php`:

```php
<?php

namespace Tests\Feature\Http\Controllers\PaymentProviders;

use App\Constants\OrderStatus;
use App\Constants\SubscriptionStatus;
use App\Constants\TransactionStatus;
use App\Jobs\ProcessCardknoxWebhook;
use App\Models\Order;
use App\Services\PaymentProviders\Cardknox\CardknoxBillingService;
use App\Services\PaymentProviders\Cardknox\CardknoxChargeLedger;
use App\Services\PaymentProviders\Cardknox\CardknoxChargePurpose;
use App\Services\PaymentProviders\Cardknox\CardknoxResult;
use App\Services\PaymentProviders\Cardknox\CardknoxWebhookHandler;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Feature\FeatureTest;
use Tests\Support\CardknoxTestHelpers;

class CardknoxControllerTest extends FeatureTest
{
    use CardknoxTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureCardknox();
    }

    private function postWebhook(array $fields, ?string $signature): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'];

        if ($signature !== null) {
            $server['HTTP_CK_SIGNATURE'] = $signature;
        }

        return $this->call('POST', route('payments-providers.cardknox.webhook'), [], [], [], $server, http_build_query($fields));
    }

    public function test_signature_algorithm_matches_the_documented_recipe(): void
    {
        // lowercase keys → sort → concatenate values → append PIN → MD5 → uppercase
        $fields = ['xResult' => 'A', 'xAmount' => '10.00', 'xRefNum' => '123'];
        $expected = strtoupper(md5('10.00'.'123'.'A'.'abcdefghijklmnop1'));

        $this->assertSame($expected, CardknoxWebhookHandler::signature($fields, 'abcdefghijklmnop1'));
    }

    public function test_valid_signature_is_accepted_and_queued_without_sensitive_fields(): void
    {
        Queue::fake();
        $fields = ['xCommand' => 'cc:Sale', 'xResult' => 'A', 'xRefNum' => '1', 'xInvoice' => 'S1-F-1', 'xToken' => 'secret-token', 'xMaskedCardNumber' => '4xxx1111'];

        $this->postWebhook($fields, CardknoxWebhookHandler::signature($fields, 'abcdefghijklmnop1'))->assertOk();

        Queue::assertPushed(ProcessCardknoxWebhook::class, fn (ProcessCardknoxWebhook $job): bool => $job->fields['xInvoice'] === 'S1-F-1'
            && ! array_key_exists('xToken', $job->fields)
            && ! array_key_exists('xMaskedCardNumber', $job->fields));
    }

    public function test_missing_or_wrong_signature_is_rejected(): void
    {
        Queue::fake();
        $fields = ['xCommand' => 'cc:Sale', 'xResult' => 'A'];

        $this->postWebhook($fields, null)->assertForbidden();
        $this->postWebhook($fields, 'DEADBEEF')->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_endpoint_rejects_everything_when_no_valid_pin_is_configured(): void
    {
        Queue::fake();
        config(['services.cardknox.webhook_pin' => 'short']);
        $fields = ['xCommand' => 'cc:Sale', 'xResult' => 'A'];

        $this->postWebhook($fields, CardknoxWebhookHandler::signature($fields, 'short'))->assertForbidden();
    }

    public function test_sale_webhook_settles_a_pending_charge_once_even_if_delivered_twice(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['status' => SubscriptionStatus::NEW->value]);
        $charge = app(CardknoxChargeLedger::class)->open('S'.$subscription->id.'-F', CardknoxChargePurpose::First, $subscription, 2000);
        $fields = ['xCommand' => 'cc:Sale', 'xResult' => 'A', 'xRefNum' => '9001', 'xInvoice' => $charge->invoice];

        (new ProcessCardknoxWebhook($fields))->handle(app(CardknoxChargeLedger::class));
        (new ProcessCardknoxWebhook($fields))->handle(app(CardknoxChargeLedger::class));

        $this->assertSame(SubscriptionStatus::ACTIVE->value, $subscription->fresh()->status);
        $this->assertSame('9001', $charge->fresh()->ref_num);
        $this->assertSame(1, $charge->transaction()->count());
    }

    public function test_refund_webhook_refunds_the_order(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan());
        $order = Order::factory()->create(['user_id' => $subscription->user_id, 'tenant_id' => $subscription->tenant_id, 'total_amount' => 4900, 'total_amount_after_discount' => 4900, 'total_discount_amount' => 0]);
        $ledger = app(CardknoxChargeLedger::class);
        $charge = $ledger->open('O'.$order->id, CardknoxChargePurpose::Order, $order, 4900);
        $ledger->settle($charge, CardknoxResult::fromResponse($this->gatewayApproves()));

        (new ProcessCardknoxWebhook(['xCommand' => 'cc:Refund', 'xResult' => 'A', 'xRefNum' => '7', 'xInvoice' => $charge->invoice]))->handle($ledger);

        $this->assertSame(OrderStatus::REFUNDED->value, $order->fresh()->status);
    }

    public function test_admin_refund_falls_back_to_void_only_on_a_definite_refusal(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan());
        $order = Order::factory()->create(['user_id' => $subscription->user_id, 'tenant_id' => $subscription->tenant_id, 'total_amount' => 4900, 'total_amount_after_discount' => 4900, 'total_discount_amount' => 0]);
        $ledger = app(CardknoxChargeLedger::class);
        $charge = $ledger->open('O'.$order->id, CardknoxChargePurpose::Order, $order, 4900);
        $ledger->settle($charge, CardknoxResult::fromResponse($this->gatewayApproves(['xRefNum' => '42'])));
        $this->fakeGateway(['xResult' => 'E', 'xError' => 'Transaction not settled'], $this->gatewayApproves());

        $result = app(CardknoxBillingService::class)->refund($charge->transaction->fresh());

        $this->assertTrue($result->isApproved());
        Http::assertSentCount(2);
        $this->assertSame(TransactionStatus::REFUNDED->value, $charge->transaction->fresh()->status);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=CardknoxControllerTest`
Expected: FAIL — `Class "App\Services\PaymentProviders\Cardknox\CardknoxWebhookHandler" not found`.

- [ ] **Step 3: Handler, job, controller, route**

`backend/app/Services/PaymentProviders/Cardknox/CardknoxWebhookHandler.php`:

```php
<?php

namespace App\Services\PaymentProviders\Cardknox;

use App\Jobs\ProcessCardknoxWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * Cardknox posts one form-encoded notification per transaction, never
 * retries, and gives up after 10 seconds. Verify, queue, answer at once.
 */
class CardknoxWebhookHandler
{
    /** Only what processing needs reaches the queue; card tokens never do. */
    private const KEPT_FIELDS = ['xCommand', 'xResult', 'xStatus', 'xError', 'xErrorCode', 'xRefNum', 'xInvoice', 'xAmount'];

    public function __construct(private CardknoxConfiguration $configuration) {}

    public function handleWebhook(Request $request): JsonResponse
    {
        $pin = $this->configuration->webhookPin();
        parse_str($request->getContent(), $fields);
        $header = (string) $request->header('ck-signature', '');

        if (! CardknoxConfiguration::isValidPin($pin) || $header === '' || ! hash_equals(self::signature($fields, (string) $pin), strtoupper($header))) {
            return response()->json(['error' => 'Invalid signature'], 403);
        }

        ProcessCardknoxWebhook::dispatch(Arr::only($fields, self::KEPT_FIELDS));

        return response()->json(['received' => true]);
    }

    /** Lowercase names, sort, concatenate values, append PIN, non-ASCII → '?', MD5, uppercase. */
    public static function signature(array $fields, string $pin): string
    {
        $values = [];

        foreach ($fields as $name => $value) {
            $values[strtolower((string) $name)] = is_scalar($value) ? (string) $value : '';
        }

        ksort($values, SORT_STRING);

        $payload = (string) preg_replace('/[^\x00-\x7F]/u', '?', implode('', $values).$pin);

        return strtoupper(md5($payload));
    }
}
```

`backend/app/Jobs/ProcessCardknoxWebhook.php`:

```php
<?php

namespace App\Jobs;

use App\Services\PaymentProviders\Cardknox\CardknoxChargeLedger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessCardknoxWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public array $fields) {}

    public function handle(CardknoxChargeLedger $ledger): void
    {
        $ledger->applyWebhook($this->fields);
    }
}
```

`backend/app/Http/Controllers/PaymentProviders/CardknoxController.php`:

```php
<?php

namespace App\Http\Controllers\PaymentProviders;

use App\Http\Controllers\Controller;
use App\Services\PaymentProviders\Cardknox\CardknoxWebhookHandler;
use Illuminate\Http\Request;

class CardknoxController extends Controller
{
    public function handleWebhook(Request $request, CardknoxWebhookHandler $handler)
    {
        return $handler->handleWebhook($request);
    }
}
```

`backend/routes/api.php` — add the import next to the other payment-provider controllers and, after the Polar webhook route:

```php
Route::post('/payments-providers/cardknox/webhook', [
    CardknoxController::class,
    'handleWebhook',
])->name('payments-providers.cardknox.webhook');
```

- [ ] **Step 4: Ledger `applyWebhook()` and billing `refund()`**

In `CardknoxChargeLedger.php` add `use Illuminate\Support\Facades\Log;` and, after `markDisputed()`:

```php
    /**
     * Webhooks confirm; they never drive state on their own. Every branch is
     * idempotent (settle/markRefunded check the current status), which is
     * what makes duplicate and out-of-order deliveries harmless.
     */
    public function applyWebhook(array $fields): void
    {
        $command = strtolower((string) ($fields['xCommand'] ?? ''));
        $invoice = (string) ($fields['xInvoice'] ?? '');
        $charge = $invoice === '' ? null : CardknoxCharge::query()->where('invoice', $invoice)->first();

        if ($charge === null) {
            Log::info('Cardknox webhook matched no charge', ['command' => $command, 'ref' => $fields['xRefNum'] ?? null]);

            return;
        }

        $result = CardknoxResult::fromResponse($fields);

        if ($command === 'cc:sale') {
            $this->settle($charge, $result);

            return;
        }

        if (in_array($command, ['cc:refund', 'cc:void'], true) && $result->isApproved()) {
            $this->markRefunded($charge);
        }
    }
```

In `CardknoxBillingService.php` add imports `use App\Client\CardknoxClient;`, `use App\Models\CardknoxCharge;`, `use App\Models\Transaction;`, add `private CardknoxClient $client,` as the last constructor parameter, and add after `changeQuantity()`:

```php
    public function refund(Transaction $transaction): CardknoxResult
    {
        $charge = CardknoxCharge::query()->where('transaction_id', $transaction->id)->firstOrFail();

        if ($charge->ref_num === null) {
            return CardknoxResult::error(__('This charge has no gateway reference to refund.'));
        }

        $result = $this->client->refund($charge->ref_num);

        // An unsettled charge cannot be refunded, only voided. Never void after
        // an Unknown refund: the refund may have gone through.
        if (in_array($result->outcome, [CardknoxOutcome::Declined, CardknoxOutcome::Error], true)) {
            $result = $this->client->void($charge->ref_num);
        }

        if ($result->isApproved()) {
            $this->ledger->markRefunded($charge);
        }

        return $result;
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=CardknoxControllerTest`
Expected: 7 passed.

- [ ] **Step 6: Commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint app/Services/PaymentProviders/Cardknox app/Jobs/ProcessCardknoxWebhook.php app/Http/Controllers/PaymentProviders/CardknoxController.php routes/api.php tests/Feature/Http/Controllers/PaymentProviders/CardknoxControllerTest.php
git add backend/app/Services/PaymentProviders/Cardknox/CardknoxWebhookHandler.php backend/app/Services/PaymentProviders/Cardknox/CardknoxChargeLedger.php backend/app/Services/PaymentProviders/Cardknox/CardknoxBillingService.php backend/app/Jobs/ProcessCardknoxWebhook.php backend/app/Http/Controllers/PaymentProviders/CardknoxController.php backend/routes/api.php backend/tests/Feature/Http/Controllers/PaymentProviders/CardknoxControllerTest.php
git commit -m "feat(payments): signed Cardknox webhooks, refunds with void fallback"
```

---
### Task 11: Reconciliation of pending charges

**Files:**
- Modify: `backend/app/Services/PaymentProviders/Cardknox/CardknoxChargeLedger.php` (add `reconcilePending()`)
- Create: `backend/app/Console/Commands/Cardknox/ReconcileCardknoxTransactions.php`
- Modify: `backend/routes/console.php`
- Test: `backend/tests/Feature/Console/Cardknox/ReconcileCardknoxTransactionsTest.php`

**Interfaces:**
- Consumes: `CardknoxClient::findByInvoice()` (null = no record, `Unknown` = lookup failed), `CardknoxChargeLedger::settle()`.
- Produces: `CardknoxChargeLedger::reconcilePending(Carbon $olderThan, ?int $chargeId = null): int` (settled count); command `app:reconcile-cardknox-transactions {--charge=}`.

- [ ] **Step 1: Write the failing test**

`backend/tests/Feature/Console/Cardknox/ReconcileCardknoxTransactionsTest.php`:

```php
<?php

namespace Tests\Feature\Console\Cardknox;

use App\Constants\SubscriptionStatus;
use App\Constants\TransactionStatus;
use App\Models\CardknoxCharge;
use App\Services\PaymentProviders\Cardknox\CardknoxChargeLedger;
use App\Services\PaymentProviders\Cardknox\CardknoxChargePurpose;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTest;
use Tests\Support\CardknoxTestHelpers;

class ReconcileCardknoxTransactionsTest extends FeatureTest
{
    use CardknoxTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureCardknox();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pendingRenewal(string $openedAt): CardknoxCharge
    {
        Carbon::setTestNow($openedAt);
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['ends_at' => '2026-11-01 00:00:00']);
        $charge = app(CardknoxChargeLedger::class)->open('S'.$subscription->id.'-20261101', CardknoxChargePurpose::Renewal, $subscription, 2000, 0, Carbon::parse('2026-11-01 00:00:00'));
        Carbon::setTestNow();

        return $charge;
    }

    private function run(CardknoxCharge $charge): void
    {
        $this->artisan('app:reconcile-cardknox-transactions', ['--charge' => $charge->id])->assertSuccessful();
    }

    public function test_approved_at_the_gateway_settles_and_advances_the_period(): void
    {
        $charge = $this->pendingRenewal(now()->subHour()->toDateTimeString());
        $this->fakeGateway(['xResult' => 'A', 'xReportData' => [['xInvoice' => $charge->invoice, 'xCommand' => 'cc:Sale', 'xResponseResult' => 'Approved', 'xRefNum' => '31']]]);

        $this->run($charge);

        $this->assertSame(TransactionStatus::SUCCESS->value, $charge->transaction->fresh()->status);
        $this->assertSame('31', $charge->fresh()->ref_num);
        $this->assertSame('2026-12-01 00:00:00', Carbon::parse($charge->transaction->subscription->fresh()->ends_at)->toDateTimeString());
    }

    public function test_recent_charges_are_left_alone(): void
    {
        $charge = $this->pendingRenewal(now()->subMinutes(5)->toDateTimeString());
        Http::fake();

        $this->run($charge);

        Http::assertNothingSent();
    }

    public function test_missing_record_waits_a_day_then_fails_into_the_retry_ladder(): void
    {
        $young = $this->pendingRenewal(now()->subHours(2)->toDateTimeString());
        $old = $this->pendingRenewal(now()->subHours(25)->toDateTimeString());
        $this->fakeGateway(['xResult' => 'A', 'xReportData' => []], ['xResult' => 'A', 'xReportData' => []]);

        $this->run($young);
        $this->run($old);

        $this->assertSame(TransactionStatus::PENDING->value, $young->transaction->fresh()->status);
        $this->assertSame(TransactionStatus::FAILED->value, $old->transaction->fresh()->status);
        $this->assertSame(SubscriptionStatus::PAST_DUE->value, $old->transaction->subscription->fresh()->status);
    }

    public function test_failed_lookup_changes_nothing(): void
    {
        $charge = $this->pendingRenewal(now()->subHours(30)->toDateTimeString());
        Http::fake(['x1.cardknox.com/*' => Http::failedConnection()]);

        $this->run($charge);

        $this->assertSame(TransactionStatus::PENDING->value, $charge->transaction->fresh()->status);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=ReconcileCardknoxTransactionsTest`
Expected: FAIL — `The command "app:reconcile-cardknox-transactions" does not exist.`

- [ ] **Step 3: Implement**

In `CardknoxChargeLedger.php`, add after `applyWebhook()`:

```php
    /**
     * Cardknox never retries a webhook, so anything still pending after the
     * grace period is looked up directly. A charge the gateway has no record of
     * after 24 hours never reached it, and is failed (renewals then retry).
     */
    public function reconcilePending(Carbon $olderThan, ?int $chargeId = null): int
    {
        $settled = 0;

        CardknoxCharge::query()
            ->where('created_at', '<=', $olderThan)
            ->whereHas('transaction', fn ($query) => $query->where('status', TransactionStatus::PENDING->value))
            ->when($chargeId !== null, fn ($query) => $query->whereKey($chargeId))
            ->each(function (CardknoxCharge $charge) use (&$settled): void {
                $found = $this->client->findByInvoice($charge->invoice, Carbon::parse($charge->created_at)->subHour());

                if ($found === null) {
                    if (Carbon::parse($charge->created_at)->lt(now()->subDay())) {
                        $this->settle($charge, CardknoxResult::error(__('No gateway record of this charge after 24 hours.')));
                        $settled++;
                    }

                    return;
                }

                if ($found->outcome !== CardknoxOutcome::Unknown) {
                    $this->settle($charge, $found);
                    $settled++;
                }
            });

        return $settled;
    }
```

`backend/app/Console/Commands/Cardknox/ReconcileCardknoxTransactions.php`:

```php
<?php

namespace App\Console\Commands\Cardknox;

use App\Services\PaymentProviders\Cardknox\CardknoxChargeLedger;
use Illuminate\Console\Command;

class ReconcileCardknoxTransactions extends Command
{
    protected $signature = 'app:reconcile-cardknox-transactions {--charge= : Only this cardknox_charges id}';

    protected $description = 'Settle Cardknox charges still pending after their webhook should have arrived';

    public function handle(CardknoxChargeLedger $ledger): int
    {
        $chargeId = $this->option('charge');

        $settled = $ledger->reconcilePending(now()->subMinutes(15), $chargeId === null ? null : (int) $chargeId);

        $this->info("Settled {$settled} pending Cardknox charge(s).");

        return self::SUCCESS;
    }
}
```

`backend/routes/console.php` — add after the renewals line:

```php
Schedule::command('app:reconcile-cardknox-transactions')->hourly()->withoutOverlapping()->onOneServer();
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=ReconcileCardknoxTransactionsTest`
Expected: 4 passed.

- [ ] **Step 5: Commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint app/Services/PaymentProviders/Cardknox/CardknoxChargeLedger.php app/Console/Commands/Cardknox routes/console.php tests/Feature/Console/Cardknox
git add backend/app/Services/PaymentProviders/Cardknox/CardknoxChargeLedger.php backend/app/Console/Commands/Cardknox/ReconcileCardknoxTransactions.php backend/routes/console.php backend/tests/Feature/Console/Cardknox/ReconcileCardknoxTransactionsTest.php
git commit -m "feat(payments): hourly reconciliation of pending Cardknox charges"
```

---
### Task 12: Admin — settings page, enable guard, refund/dispute/charge-now actions

**Files:**
- Create: `backend/app/Filament/Admin/Resources/PaymentProviders/Pages/CardknoxSettings.php`
- Create: `backend/app/Livewire/Filament/CardknoxSettings.php`
- Create: `backend/resources/views/filament/admin/resources/payment-provider-resource/pages/cardknox-settings.blade.php`
- Create: `backend/resources/views/livewire/filament/cardknox-settings.blade.php`
- Create: `backend/resources/views/filament/admin/resources/payment-provider-resource/pages/partials/cardknox-how-to.blade.php`
- Modify: `backend/app/Filament/Admin/Resources/PaymentProviders/PaymentProviderResource.php`
- Modify: `backend/app/Filament/Admin/Resources/Transactions/Pages/ViewTranscription.php`
- Modify: `backend/app/Filament/Admin/Resources/Subscriptions/Pages/ViewSubscription.php`
- Test: `backend/tests/Feature/Filament/Admin/CardknoxAdminTest.php`

**Interfaces:**
- Consumes: `ConfigService::get/set`, `CardknoxConfiguration::isComplete/isValidPin`, `CardknoxBillingService::refund/chargeRenewal`, `CardknoxChargeLedger::markDisputed`.
- Produces: admin page route `filament.admin.resources.payment-providers.cardknox-settings` (resolved by `EditPaymentProvider`'s existing `{slug}-settings` lookup); page actions `cardknox_refund`, `cardknox_mark_disputed` (transaction view) and `cardknox_charge_now` (subscription view).

- [ ] **Step 1: Write the failing test**

`backend/tests/Feature/Filament/Admin/CardknoxAdminTest.php`:

```php
<?php

namespace Tests\Feature\Filament\Admin;

use App\Constants\OrderStatus;
use App\Constants\SubscriptionStatus;
use App\Constants\TransactionStatus;
use App\Filament\Admin\Resources\PaymentProviders\PaymentProviderResource;
use App\Filament\Admin\Resources\Subscriptions\Pages\ViewSubscription;
use App\Filament\Admin\Resources\Transactions\Pages\ViewTranscription;
use App\Livewire\Filament\CardknoxSettings;
use App\Models\Order;
use App\Services\ConfigService;
use App\Services\PaymentProviders\Cardknox\CardknoxChargeLedger;
use App\Services\PaymentProviders\Cardknox\CardknoxChargePurpose;
use App\Services\PaymentProviders\Cardknox\CardknoxResult;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;
use Tests\Support\CardknoxTestHelpers;

class CardknoxAdminTest extends FeatureTest
{
    use CardknoxTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureCardknox();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_settings_page_is_registered(): void
    {
        $this->assertTrue(PaymentProviderResource::hasPage('cardknox-settings'));
    }

    public function test_settings_require_a_valid_pin(): void
    {
        $admin = $this->createAdminUser();

        Livewire::actingAs($admin)->test(CardknoxSettings::class)
            ->fillForm(['transaction_key' => 'key', 'ifields_key' => 'ifk', 'webhook_pin' => 'short'])
            ->call('save')
            ->assertHasFormErrors(['webhook_pin']);

        Livewire::actingAs($admin)->test(CardknoxSettings::class)
            ->fillForm(['transaction_key' => 'key_saved', 'ifields_key' => 'ifk', 'webhook_pin' => 'abcdefghijklmnop2'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('key_saved', app(ConfigService::class)->get('services.cardknox.transaction_key'));
    }

    private function paidOrderCharge(): array
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan());
        $order = Order::factory()->create(['user_id' => $subscription->user_id, 'tenant_id' => $subscription->tenant_id, 'total_amount' => 4900, 'total_amount_after_discount' => 4900, 'total_discount_amount' => 0]);
        $ledger = app(CardknoxChargeLedger::class);
        $charge = $ledger->open('O'.$order->id, CardknoxChargePurpose::Order, $order, 4900);
        $ledger->settle($charge, CardknoxResult::fromResponse($this->gatewayApproves(['xRefNum' => '88'])));

        return [$order, $charge->transaction->fresh()];
    }

    public function test_admin_can_refund_a_cardknox_transaction(): void
    {
        [$order, $transaction] = $this->paidOrderCharge();
        $this->fakeGateway($this->gatewayApproves());
        $admin = $this->createAdminUser();
        $this->actingAs($admin);

        Livewire::actingAs($admin)
            ->test(ViewTranscription::class, ['record' => $transaction->getRouteKey()])
            ->callAction('cardknox_refund');

        $this->assertSame(TransactionStatus::REFUNDED->value, $transaction->fresh()->status);
        $this->assertSame(OrderStatus::REFUNDED->value, $order->fresh()->status);
    }

    public function test_admin_can_mark_a_transaction_disputed(): void
    {
        [$order, $transaction] = $this->paidOrderCharge();
        $admin = $this->createAdminUser();
        $this->actingAs($admin);

        Livewire::actingAs($admin)
            ->test(ViewTranscription::class, ['record' => $transaction->getRouteKey()])
            ->callAction('cardknox_mark_disputed');

        $this->assertSame(TransactionStatus::DISPUTED->value, $transaction->fresh()->status);
        $this->assertSame(OrderStatus::DISPUTED->value, $order->fresh()->status);
    }

    public function test_admin_can_charge_a_past_due_subscription_now(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), [
            'status' => SubscriptionStatus::PAST_DUE->value,
            'ends_at' => now()->subDay(),
            'extra_payment_provider_data' => ['failed_attempts' => 1, 'next_retry_at' => now()->addDays(2)->toDateTimeString()],
        ]);
        $this->fakeGateway($this->gatewayApproves());
        $admin = $this->createAdminUser();
        $this->actingAs($admin);

        Livewire::actingAs($admin)
            ->test(ViewSubscription::class, ['record' => $subscription->getRouteKey()])
            ->callAction('cardknox_charge_now');

        $this->assertSame(SubscriptionStatus::ACTIVE->value, $subscription->fresh()->status);
    }

    public function test_cardknox_cannot_be_enabled_while_incomplete(): void
    {
        config(['services.cardknox.webhook_pin' => null]);
        $admin = $this->createAdminUser();
        $this->actingAs($admin);

        $this->get(PaymentProviderResource::getUrl('edit', ['record' => $this->cardknoxProviderModel()], panel: 'admin'))
            ->assertOk()
            ->assertSee(__('Save the transaction key, iFields key and a webhook PIN in the Cardknox settings first.'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=CardknoxAdminTest`
Expected: FAIL — `Class "App\Livewire\Filament\CardknoxSettings" not found`.

- [ ] **Step 3: Settings page (mirrors Lemon Squeezy)**

`backend/app/Filament/Admin/Resources/PaymentProviders/Pages/CardknoxSettings.php`:

```php
<?php

namespace App\Filament\Admin\Resources\PaymentProviders\Pages;

use App\Filament\Admin\Resources\PaymentProviders\PaymentProviderResource;
use App\Services\ConfigService;
use Filament\Resources\Pages\Page;

class CardknoxSettings extends Page
{
    protected static string $resource = PaymentProviderResource::class;

    protected string $view = 'filament.admin.resources.payment-provider-resource.pages.cardknox-settings';

    public function mount(): void
    {
        static::authorizeResourceAccess();
    }

    public static function canAccess(array $parameters = []): bool
    {
        return app()->make(ConfigService::class)->isAdminSettingsEnabled();
    }
}
```

`backend/resources/views/filament/admin/resources/payment-provider-resource/pages/cardknox-settings.blade.php`:

```blade
<x-filament-panels::page>
    @livewire('filament.cardknox-settings')
</x-filament-panels::page>
```

`backend/app/Livewire/Filament/CardknoxSettings.php`:

```php
<?php

namespace App\Livewire\Filament;

use App\Services\ConfigService;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Livewire\Component;

class CardknoxSettings extends Component implements HasForms
{
    use InteractsWithForms;

    private ConfigService $configService;

    public ?array $data = [];

    public function boot(ConfigService $configService): void
    {
        $this->configService = $configService;
    }

    public function render()
    {
        return view('livewire.filament.cardknox-settings');
    }

    public function mount(): void
    {
        $this->form->fill([
            'transaction_key' => $this->configService->get('services.cardknox.transaction_key'),
            'ifields_key' => $this->configService->get('services.cardknox.ifields_key'),
            'webhook_pin' => $this->configService->get('services.cardknox.webhook_pin'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        TextInput::make('transaction_key')
                            ->label(__('Transaction (API) Key'))
                            ->password()
                            ->revealable()
                            ->required(),
                        TextInput::make('ifields_key')
                            ->label(__('iFields Key'))
                            ->helperText(__('Public key used by the card form in the browser.'))
                            ->required(),
                        TextInput::make('webhook_pin')
                            ->label(__('Webhook PIN'))
                            ->password()
                            ->revealable()
                            ->required()
                            ->regex('/^[A-Za-z0-9]{15,}$/')
                            ->helperText(__('Letters and digits only, at least 15 characters. Enter the same PIN in the Cardknox portal under Settings → Gateway Settings → Webhook.')),
                    ])->columnSpan([
                        'sm' => 6,
                        'xl' => 8,
                        '2xl' => 8,
                    ]),
                Section::make()->schema([
                    ViewField::make('how-to')
                        ->label(__('Cardknox Settings'))
                        ->view('filament.admin.resources.payment-provider-resource.pages.partials.cardknox-how-to'),
                ])->columnSpan([
                    'sm' => 6,
                    'xl' => 4,
                    '2xl' => 4,
                ]),
            ])->columns(12)
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $this->configService->set('services.cardknox.transaction_key', $data['transaction_key']);
        $this->configService->set('services.cardknox.ifields_key', $data['ifields_key']);
        $this->configService->set('services.cardknox.webhook_pin', $data['webhook_pin']);

        Notification::make()
            ->title(__('Settings Saved'))
            ->success()
            ->send();
    }
}
```

`backend/resources/views/livewire/filament/cardknox-settings.blade.php` — copy `resources/views/livewire/filament/lemon-squeezy-settings.blade.php` verbatim (it contains no provider-specific text).

`backend/resources/views/filament/admin/resources/payment-provider-resource/pages/partials/cardknox-how-to.blade.php`:

```blade
<div class="space-y-3 text-sm">
    <p>{{ __('In the Cardknox (Sola) portal, open Account Settings → Keys and create an API key and an iFields key. Paste both here.') }}</p>
    <p>{{ __('Then open Settings → Gateway Settings → Webhook and set the postback URL to:') }}</p>
    <code class="block break-all rounded bg-gray-100 p-2 dark:bg-gray-800">{{ route('payments-providers.cardknox.webhook') }}</code>
    <p>{{ __('Set a webhook PIN there and enter the same PIN here. Cardknox cannot be enabled until all three values are saved.') }}</p>
    <p>{{ __('Cardknox accepts USD only. Renewals are charged by the app every hour; Horizon and the scheduler must be running.') }}</p>
</div>
```

`backend/app/Filament/Admin/Resources/PaymentProviders/PaymentProviderResource.php` — add imports:

```php
use App\Constants\PaymentProviderConstants;
use App\Filament\Admin\Resources\PaymentProviders\Pages\CardknoxSettings;
use App\Services\PaymentProviders\Cardknox\CardknoxConfiguration;
```

add to `getPages()` after `'polar-settings'`:

```php
            'cardknox-settings' => CardknoxSettings::route('/cardknox-settings'),
```

- [ ] **Step 4: Enable guard**

In the same resource add this static helper:

```php
    /** Cardknox cannot be switched on until its keys and webhook PIN are saved. */
    public static function isLockedOff(?PaymentProvider $record): bool
    {
        return $record?->slug === PaymentProviderConstants::CARDKNOX_SLUG
            && ! app(CardknoxConfiguration::class)->isComplete();
    }
```

In `form()`, append to **both** `Toggle::make('is_active')` and `Toggle::make('is_enabled_for_new_payments')`:

```php
                        ->disabled(fn (?PaymentProvider $record): bool => static::isLockedOff($record))
                        ->hint(fn (?PaymentProvider $record): ?string => static::isLockedOff($record) ? __('Save the transaction key, iFields key and a webhook PIN in the Cardknox settings first.') : null)
```

In `table()`, append to **both** `ToggleColumn::make('is_active')` and `ToggleColumn::make('is_enabled_for_new_payments')`:

```php
                    ->disabled(fn (PaymentProvider $record): bool => static::isLockedOff($record))
```

- [ ] **Step 5: Transaction actions**

Replace `backend/app/Filament/Admin/Resources/Transactions/Pages/ViewTranscription.php` with:

```php
<?php

namespace App\Filament\Admin\Resources\Transactions\Pages;

use App\Constants\PaymentProviderConstants;
use App\Constants\TransactionStatus;
use App\Filament\Admin\Resources\Transactions\TransactionResource;
use App\Models\Transaction;
use App\Services\PaymentProviders\Cardknox\CardknoxBillingService;
use App\Services\PaymentProviders\Cardknox\CardknoxChargeLedger;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewTranscription extends ViewRecord
{
    protected static string $resource = TransactionResource::class;

    protected function getHeaderActions(): array
    {
        // Cardknox has no dashboard of subscription objects to act on, so
        // refunds and disputes are recorded from here.
        $isCardknox = fn (Transaction $record): bool => $record->paymentProvider?->slug === PaymentProviderConstants::CARDKNOX_SLUG;

        return [
            Action::make('cardknox_refund')
                ->label(__('Refund'))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (Transaction $record): bool => $isCardknox($record) && $record->status === TransactionStatus::SUCCESS->value)
                ->action(function (Transaction $record, CardknoxBillingService $billing): void {
                    $result = $billing->refund($record);

                    $result->isApproved()
                        ? Notification::make()->title(__('Refunded'))->success()->send()
                        : Notification::make()->title(__('Refund failed'))->body($result->error)->danger()->send();
                }),
            Action::make('cardknox_mark_disputed')
                ->label(__('Mark disputed'))
                ->icon('heroicon-o-exclamation-triangle')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn (Transaction $record): bool => $isCardknox($record) && $record->status === TransactionStatus::SUCCESS->value)
                ->action(function (Transaction $record, CardknoxChargeLedger $ledger): void {
                    $ledger->markDisputed($record);
                    Notification::make()->title(__('Marked as disputed'))->success()->send();
                }),
        ];
    }
}
```

- [ ] **Step 6: Subscription "Charge now"**

In `backend/app/Filament/Admin/Resources/Subscriptions/Pages/ViewSubscription.php` add imports:

```php
use App\Constants\PaymentProviderConstants;
use App\Services\PaymentProviders\Cardknox\CardknoxBillingService;
```

and add this action inside the `ActionGroup::make([...])` list, after the `sync` action:

```php
                Action::make('cardknox_charge_now')
                    ->label(__('Charge now'))
                    ->icon('heroicon-o-credit-card')
                    ->requiresConfirmation()
                    ->modalDescription(__('Charges the overdue renewal immediately instead of waiting for the next retry.'))
                    ->visible(fn (Subscription $record): bool => $record->paymentProvider?->slug === PaymentProviderConstants::CARDKNOX_SLUG
                        && $record->status === SubscriptionStatus::PAST_DUE->value)
                    ->action(function (Subscription $record, CardknoxBillingService $billing): void {
                        $result = $billing->retryOutstanding($record);

                        $result?->isApproved()
                            ? Notification::make()->title(__('Payment succeeded'))->success()->send()
                            : Notification::make()->title(__('Payment did not go through'))->body($result?->error)->danger()->send();
                    }),
```

- [ ] **Step 7: Run test to verify it passes**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter=CardknoxAdminTest`
Expected: 7 passed. `callAction()` on a header action inside an `ActionGroup` works by name in Filament 5; if it reports the action as not found, move `cardknox_charge_now` out of the group to the top level of the returned array.

- [ ] **Step 8: Commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint app/Filament/Admin/Resources/PaymentProviders app/Livewire/Filament/CardknoxSettings.php app/Filament/Admin/Resources/Transactions/Pages/ViewTranscription.php app/Filament/Admin/Resources/Subscriptions/Pages/ViewSubscription.php tests/Feature/Filament/Admin/CardknoxAdminTest.php
git add backend/app/Filament/Admin/Resources/PaymentProviders/Pages/CardknoxSettings.php backend/app/Filament/Admin/Resources/PaymentProviders/PaymentProviderResource.php backend/app/Livewire/Filament/CardknoxSettings.php backend/resources/views/filament/admin/resources/payment-provider-resource/pages/cardknox-settings.blade.php backend/resources/views/livewire/filament/cardknox-settings.blade.php backend/resources/views/filament/admin/resources/payment-provider-resource/pages/partials/cardknox-how-to.blade.php backend/app/Filament/Admin/Resources/Transactions/Pages/ViewTranscription.php backend/app/Filament/Admin/Resources/Subscriptions/Pages/ViewSubscription.php backend/tests/Feature/Filament/Admin/CardknoxAdminTest.php
git commit -m "feat(payments): Cardknox admin settings, enable guard, refund/dispute/charge-now actions"
```

---
### Task 13: Observability — health checks and Sentry scrubbing

**Files:**
- Create: `backend/app/Health/Checks/CardknoxRenewalBacklogCheck.php`
- Create: `backend/app/Health/Checks/CardknoxDeclineRateCheck.php`
- Create: `backend/app/Health/Checks/CardknoxPendingTransactionsCheck.php`
- Modify: `backend/app/Providers/HealthServiceProvider.php`
- Modify: `backend/config/health.php` (`flexpick` block)
- Modify: `backend/app/Support/Sentry/TokenScrubber.php`
- Test: `backend/tests/Feature/Health/CardknoxChecksTest.php`
- Test: `backend/tests/Unit/TokenScrubberTest.php` (add cases)

**Interfaces:**
- Consumes: `CardknoxCharge`, `Subscription`, `Transaction`, `App\Health\FailureRate($total, $failed, $minSamples)` (`belowFloor()`, `percent()`).
- Produces: checks named `CardknoxRenewalBacklog` (band **high**), `CardknoxDeclineRate` (**medium**), `CardknoxPendingTransactions` (**medium**); config keys `health.flexpick.cardknox.{renewal_backlog_hours, pending_minutes, decline.window_hours, decline.min_samples, decline.fail_percent}`.

- [ ] **Step 1: Write the failing tests**

`backend/tests/Feature/Health/CardknoxChecksTest.php`:

```php
<?php

namespace Tests\Feature\Health;

use App\Constants\SubscriptionStatus;
use App\Constants\TransactionStatus;
use App\Health\Checks\CardknoxDeclineRateCheck;
use App\Health\Checks\CardknoxPendingTransactionsCheck;
use App\Health\Checks\CardknoxRenewalBacklogCheck;
use App\Models\CardknoxCharge;
use App\Models\Subscription;
use App\Models\Transaction;
use Tests\Feature\FeatureTest;
use Tests\Support\CardknoxTestHelpers;

class CardknoxChecksTest extends FeatureTest
{
    use CardknoxTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureCardknox();

        // These checks read whole tables and FeatureTest never rolls back.
        // Neutralise rows left by other Cardknox tests instead of deleting
        // them (subscriptions and transactions have dependent rows).
        $providerId = $this->cardknoxProviderModel()->id;
        Transaction::query()->where('payment_provider_id', $providerId)
            ->update(['status' => TransactionStatus::SUCCESS->value, 'created_at' => now()->subYears(5)]);
        Subscription::query()->where('payment_provider_id', $providerId)
            ->update(['status' => SubscriptionStatus::CANCELED->value]);
    }

    public function test_backlog_fails_when_a_due_subscription_was_never_attempted(): void
    {
        $this->assertSame('ok', (new CardknoxRenewalBacklogCheck)->run()->status->value);

        $this->cardknoxSubscription($this->cardknoxPlan(), ['ends_at' => now()->subHours(3)]);

        $this->assertSame('failed', (new CardknoxRenewalBacklogCheck)->run()->status->value);
    }

    public function test_backlog_ignores_a_subscription_with_an_attempt_in_flight(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan(), ['ends_at' => now()->subHours(3)]);
        $base = 'S'.$subscription->id.'-'.now()->subHours(3)->format('Ymd');
        CardknoxCharge::create(['transaction_id' => $this->pendingTransactionFor($subscription)->id, 'invoice_base' => $base, 'invoice' => $base.'-1', 'purpose' => 'renewal']);

        $this->assertSame('ok', (new CardknoxRenewalBacklogCheck)->run()->status->value);
    }

    public function test_pending_check_fails_on_an_old_pending_transaction(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan());
        $transaction = $this->pendingTransactionFor($subscription);
        $this->assertSame('ok', (new CardknoxPendingTransactionsCheck)->run()->status->value);

        $transaction->forceFill(['created_at' => now()->subMinutes(61)])->saveQuietly();

        $this->assertSame('failed', (new CardknoxPendingTransactionsCheck)->run()->status->value);
    }

    public function test_decline_rate_fails_above_threshold_with_enough_samples(): void
    {
        $subscription = $this->cardknoxSubscription($this->cardknoxPlan());

        foreach ([TransactionStatus::FAILED, TransactionStatus::FAILED, TransactionStatus::FAILED, TransactionStatus::SUCCESS, TransactionStatus::SUCCESS] as $status) {
            $this->pendingTransactionFor($subscription)->update(['status' => $status->value]);
        }

        $this->assertSame('failed', (new CardknoxDeclineRateCheck)->run()->status->value);
    }
}
```

Add to `backend/tests/Unit/TokenScrubberTest.php` (it already imports `Sentry\Event` and `Sentry\EventHint`):

```php
    public function test_cardknox_secrets_are_scrubbed_from_extra_and_messages(): void
    {
        $event = Event::createEvent();
        $event->setMessage('gateway said xKey=live_secret_key&xToken=tok_abc123 failed');
        $event->setExtra(['payload' => ['xKey' => 'live_secret_key', 'xCardNum' => 'sut_x', 'xCVV' => 'sut_y', 'xAmount' => '10.00']]);

        $scrubbed = TokenScrubber::handle($event, new EventHint);

        $this->assertStringNotContainsString('live_secret_key', $scrubbed->getMessage());
        $this->assertStringNotContainsString('tok_abc123', $scrubbed->getMessage());
        $this->assertSame('[REDACTED]', $scrubbed->getExtra()['payload']['xKey']);
        $this->assertSame('[REDACTED]', $scrubbed->getExtra()['payload']['xCardNum']);
        $this->assertSame('[REDACTED]', $scrubbed->getExtra()['payload']['xCVV']);
        $this->assertSame('10.00', $scrubbed->getExtra()['payload']['xAmount']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter='CardknoxChecksTest|TokenScrubberTest'`
Expected: FAIL — check classes not found; scrubber test fails on `xKey`.

- [ ] **Step 3: Config**

`backend/config/health.php` — inside `'flexpick' => [`, after the `scanner_degradation` block:

```php
        'cardknox' => [
            // An ACTIVE Cardknox subscription this long past ends_at with no
            // attempt means the renewal command is not running.
            'renewal_backlog_hours' => (int) env('HEALTH_CARDKNOX_BACKLOG_HOURS', 2),
            'pending_minutes' => (int) env('HEALTH_CARDKNOX_PENDING_MINUTES', 60),
            'decline' => [
                'window_hours' => (int) env('HEALTH_CARDKNOX_DECLINE_WINDOW_HOURS', 24),
                'min_samples' => (int) env('HEALTH_CARDKNOX_DECLINE_MIN_SAMPLES', 5),
                'fail_percent' => (int) env('HEALTH_CARDKNOX_DECLINE_FAIL_PERCENT', 30),
            ],
        ],
```

and in `'bands' => [`, after `'ScannerDegradation' => 'medium',`:

```php
            // Missed renewals are lost revenue and silent churn: page.
            'CardknoxRenewalBacklog' => 'high',
            'CardknoxDeclineRate' => 'medium',
            'CardknoxPendingTransactions' => 'medium',
```

- [ ] **Step 4: Checks**

`backend/app/Health/Checks/CardknoxRenewalBacklogCheck.php`:

```php
<?php

namespace App\Health\Checks;

use App\Constants\PaymentProviderConstants;
use App\Constants\SubscriptionStatus;
use App\Models\CardknoxCharge;
use App\Models\Subscription;
use Carbon\Carbon;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Cardknox renewals are charged by our scheduler, not the gateway. If the
 * scheduler or Horizon dies, nobody else will notice a renewal never ran.
 */
class CardknoxRenewalBacklogCheck extends Check
{
    public function run(): Result
    {
        $hours = (int) config('health.flexpick.cardknox.renewal_backlog_hours');

        $overdue = Subscription::query()
            ->where('status', SubscriptionStatus::ACTIVE->value)
            ->where('is_canceled_at_end_of_cycle', false)
            ->where('ends_at', '<', now()->subHours($hours))
            ->whereHas('paymentProvider', fn ($query) => $query->where('slug', PaymentProviderConstants::CARDKNOX_SLUG))
            ->get()
            ->reject(fn (Subscription $subscription): bool => CardknoxCharge::query()
                ->where('invoice_base', 'S'.$subscription->id.'-'.Carbon::parse($subscription->ends_at)->format('Ymd'))
                ->exists());

        $result = Result::make()
            ->meta(['overdue' => $overdue->count()])
            ->shortSummary("{$overdue->count()} overdue");

        if ($overdue->isNotEmpty()) {
            return $result->failed("{$overdue->count()} Cardknox subscription(s) are more than {$hours}h past renewal with no charge attempt; is app:charge-cardknox-renewals running?");
        }

        return $result->ok();
    }
}
```

`backend/app/Health/Checks/CardknoxPendingTransactionsCheck.php`:

```php
<?php

namespace App\Health\Checks;

use App\Constants\PaymentProviderConstants;
use App\Constants\TransactionStatus;
use App\Models\Transaction;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

class CardknoxPendingTransactionsCheck extends Check
{
    public function run(): Result
    {
        $minutes = (int) config('health.flexpick.cardknox.pending_minutes');

        $stuck = Transaction::query()
            ->where('status', TransactionStatus::PENDING->value)
            ->where('created_at', '<', now()->subMinutes($minutes))
            ->whereHas('paymentProvider', fn ($query) => $query->where('slug', PaymentProviderConstants::CARDKNOX_SLUG))
            ->count();

        $result = Result::make()->meta(['stuck' => $stuck])->shortSummary("{$stuck} stuck");

        if ($stuck > 0) {
            return $result->failed("{$stuck} Cardknox transaction(s) pending for over {$minutes} minutes; reconciliation may be failing.");
        }

        return $result->ok();
    }
}
```

`backend/app/Health/Checks/CardknoxDeclineRateCheck.php`:

```php
<?php

namespace App\Health\Checks;

use App\Constants\PaymentProviderConstants;
use App\Constants\TransactionStatus;
use App\Health\FailureRate;
use App\Models\Transaction;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

class CardknoxDeclineRateCheck extends Check
{
    public function run(): Result
    {
        $config = (array) config('health.flexpick.cardknox.decline');
        $since = now()->subHours((int) $config['window_hours']);

        $settled = Transaction::query()
            ->where('created_at', '>=', $since)
            ->whereIn('status', [TransactionStatus::SUCCESS->value, TransactionStatus::FAILED->value])
            ->whereHas('paymentProvider', fn ($query) => $query->where('slug', PaymentProviderConstants::CARDKNOX_SLUG));

        $total = (clone $settled)->count();
        $failed = (clone $settled)->where('status', TransactionStatus::FAILED->value)->count();

        $rate = new FailureRate($total, $failed, (int) $config['min_samples']);

        $result = Result::make()
            ->meta(['total' => $rate->total, 'failed' => $rate->failed, 'percent' => $rate->percent()])
            ->shortSummary("{$rate->percent()}% of {$rate->total}");

        if (! $rate->belowFloor() && $rate->percent() > (int) $config['fail_percent']) {
            return $result->failed("Cardknox decline rate is {$rate->percent()}% over the last {$config['window_hours']}h ({$rate->failed}/{$rate->total}).");
        }

        return $result->ok();
    }
}
```

`backend/app/Providers/HealthServiceProvider.php` — import the three classes and add after `ScannerDegradationCheck::new(),`:

```php
            CardknoxRenewalBacklogCheck::new(),
            CardknoxDeclineRateCheck::new(),
            CardknoxPendingTransactionsCheck::new(),
```

- [ ] **Step 5: TokenScrubber**

In `backend/app/Support/Sentry/TokenScrubber.php` add a constant after `REPLACEMENT`:

```php
    // Cardknox request fields that carry credentials or card tokens. Matched
    // case-insensitively as array keys and as key=value pairs in strings.
    private const CARDKNOX_KEYS = ['xkey', 'xtoken', 'xcardnum', 'xcvv', 'xmagstripe'];
```

Replace `scrub()` with:

```php
    private static function scrub(string $value): string
    {
        // Any embedded credential pair, whether or not it belongs to a
        // connected tenant's own token.
        $value = (string) preg_replace(
            '#https://[^:/@\s]+:[^@\s]+@#i',
            'https://'.self::REPLACEMENT.'@',
            $value
        );

        // Cardknox fields in form bodies or JSON (xKey=..., "xKey":"...").
        return (string) preg_replace(
            '/\b('.implode('|', self::CARDKNOX_KEYS).')("?\s*[:=]\s*"?)[^&"\s,}]+/i',
            '$1$2'.self::REPLACEMENT,
            $value
        );
    }
```

and in `scrubArray()` add as the first statement inside the `foreach`:

```php
            if (is_string($key) && in_array(strtolower($key), self::CARDKNOX_KEYS, true)) {
                $values[$key] = self::REPLACEMENT;

                continue;
            }
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `docker compose exec -T laravel.test php artisan test --compact --filter='CardknoxChecksTest|TokenScrubberTest|HealthCheckRegistrationTest'`
Expected: all pass (`HealthCheckRegistrationTest` proves each new check has a band).

- [ ] **Step 7: Commit**

```bash
docker compose exec -T laravel.test vendor/bin/pint app/Health/Checks app/Providers/HealthServiceProvider.php config/health.php app/Support/Sentry/TokenScrubber.php tests/Feature/Health/CardknoxChecksTest.php tests/Unit/TokenScrubberTest.php
git add backend/app/Health/Checks/CardknoxRenewalBacklogCheck.php backend/app/Health/Checks/CardknoxDeclineRateCheck.php backend/app/Health/Checks/CardknoxPendingTransactionsCheck.php backend/app/Providers/HealthServiceProvider.php backend/config/health.php backend/app/Support/Sentry/TokenScrubber.php backend/tests/Feature/Health/CardknoxChecksTest.php backend/tests/Unit/TokenScrubberTest.php
git commit -m "feat(observability): Cardknox renewal, decline and pending checks; scrub gateway secrets"
```

---
### Task 14: Full verification, docs, and the sandbox checklist

**Files:**
- Modify: `CLAUDE.md` (repo root — add a Cardknox note under "Key architecture notes")
- Create: `backend/docs/superpowers/runbooks/cardknox-sandbox-checklist.md`

**Interfaces:**
- Consumes: everything above.
- Produces: green gates, documentation, and a checklist the user runs once sandbox credentials exist.

- [ ] **Step 1: Full suite**

Run: `docker compose exec -T laravel.test php artisan test --compact`
Expected: all pass. On a storm of `QueryException`s, re-run once (concurrent sessions share the test DB) before investigating.

- [ ] **Step 2: Static analysis and formatting gates (the CI gates)**

Run: `docker compose exec -T laravel.test vendor/bin/phpstan analyse --memory-limit=1G`
Expected: no errors. Fix types in the new code; do not add baseline entries.

Run: `docker compose exec -T laravel.test vendor/bin/pint && docker compose exec -T laravel.test vendor/bin/pint --test`
Expected: `pint --test` passes. (Never `pint --dirty` in the container — `.git` is not mounted.)

- [ ] **Step 3: Leftover-reference check**

Run: `grep -rn "cardknox_usage_records\|cardknox_customer_id\|discount_started_cycle" backend/app backend/database backend/tests`
Expected: no output (all superseded by spec §12).

- [ ] **Step 4: Document the architecture**

In the repo-root `CLAUDE.md`, under "Key architecture notes", after the **Payments** bullet add:

```markdown
- **Cardknox (Sola) is app-billed.** Unlike the other providers, Cardknox is a card gateway with no subscription objects: `app/Services/PaymentProviders/Cardknox/` holds the billing engine. `CardknoxChargeLedger::settle()` is the only place a gateway result changes state (shared by the pay page, `app:charge-cardknox-renewals`, `app:reconcile-cardknox-transactions` and the webhook job); every charge has a unique invoice key in `cardknox_charges`, so double charges are impossible. Subscriptions are `PAYMENT_PROVIDER_MANAGED`. Renewals need the scheduler and Horizon; `CardknoxRenewalBacklog` (high band) pages when they stop.
```

- [ ] **Step 5: Sandbox checklist**

`backend/docs/superpowers/runbooks/cardknox-sandbox-checklist.md`:

```markdown
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
```

- [ ] **Step 6: Commit**

```bash
git add CLAUDE.md backend/docs/superpowers/runbooks/cardknox-sandbox-checklist.md
git commit -m "docs(payments): Cardknox architecture note and sandbox checklist"
```

- [ ] **Step 7: Final review handoff**

Request a whole-branch code review (superpowers:requesting-code-review) covering the commits from Task 1 onward, with the Review Focus list above as the reviewer's checklist.
