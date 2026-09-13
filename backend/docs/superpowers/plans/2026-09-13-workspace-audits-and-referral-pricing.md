# Workspace Audits, Referral → Pricing, FlexPick Branding — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Referral links open a public pricing page with a Sign Up button; audits and every audit quota are owned by the workspace (`Tenant`) instead of the user; all public-facing SaaSykit branding becomes FlexPick.

**Architecture:** Three independent deliverables, shipped as separate commits in order B → C → A. §B relaxes the `/pricing` auth gate, repoints the referral link and closes the checkout invite-only bypass. §C swaps logo PNGs and string fallbacks. §A adds `audit_requests.tenant_id` + a `tenant_parameters` table, moves every user-keyed method of `AuditEntitlementService` to `Tenant`, claims pre-signup audits into a tenant via a listener on `TenantCreated`/`UserJoinedTenant`, and backfills existing rows.

**Tech Stack:** Laravel 13 / PHP 8.4, Filament 5, Livewire 4, PHPUnit 11 (classic `TestCase` classes, `Tests\Feature\FeatureTest` base), Larastan 3, Pint, ImageMagick 6 (`convert`).

**Spec:** `backend/docs/superpowers/specs/2026-09-13-workspace-audits-and-referral-pricing-design.md`

## Global Constraints

- All commands run from `backend/` (`cd /var/www/html/flexpick.net/backend`). Tests: `php artisan test --compact --filter=Name`. Format: `vendor/bin/pint` then `vendor/bin/pint --test` (never `--dirty`). Static analysis: `vendor/bin/phpstan analyse` — baseline has exactly **1** pre-existing error (`AuditGroupDeltaService`); new code adds none.
- The suite is PHPUnit, not Pest. New tests: `php artisan make:test --phpunit {Name}` or hand-written classes extending `Tests\Feature\FeatureTest`.
- The test DB is shared with other sessions: a storm of `QueryException`s means re-run, not a bug (memory: `concurrent-sessions-shared-test-db`). `git add` explicit paths only.
- Business logic in services, not models/controllers. Match surrounding comment density and naming.
- Plan metadata keys stay exactly `audit_diagnostic_credits`, `audit_deep_ai_credits`, `audit_expert_credits`.
- Commit message trailer (every commit):
  ```
  Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
  Claude-Session: https://claude.ai/code/session_01Sd52zZFTgfuaMTf1ZyKsoZ
  ```
- Spec deviations already decided while planning (implement as written here):
  1. `AuditReportController::show()` is reached only by signed URL from an email and has **no** user check today; adding one would break emailed links for logged-out recipients. `AuditReport::isViewableBy(User)` (spec A.7) therefore guards **`download()` only**; `show()` is unchanged.
  2. `SendAuditUnlockReminders` creates no `AuditRequest` — spec A.6's "copies tenant_id" line is moot; the command is untouched.
  3. `AuditReports` page (run page) lists reports and schedules by `user_id` today. Because "members share the same audit history", both switch to the tenant: reports via `forTenant`, schedules via `tenant_id`. `audit_schedules.user_id` is kept as *created by*.
  4. `scopeForUser` is removed in the **last** §A task, not the first, so every intermediate commit stays green.

---

# Part B — Referral link → pricing, Sign Up button

### Task 1: Public pricing page, Sign Up button, referral link target

**Files:**
- Modify: `routes/web.php:48-50`
- Modify: `resources/views/pricing.blade.php:14-21`
- Modify: `app/Services/ReferralService.php:274-279`
- Modify: `tests/Feature/Http/Controllers/PricingPageTest.php:82-88`
- Modify: `tests/Feature/Http/LayoutBrandingTest.php`
- Modify: `tests/Feature/Services/ReferralServiceTest.php:423-432`
- Modify: `tests/Feature/Referral/ReferralFlowTest.php:298-310`

**Interfaces:**
- Produces: `ReferralService::getReferralLink(User): string` now returns `url()->query(route('pricing'), ['rc' => $code])`.

- [ ] **Step 1: Replace the guest-redirect test with guest-sees-pricing tests**

In `tests/Feature/Http/Controllers/PricingPageTest.php`, replace the whole `test_guest_is_redirected_to_login` method with:

```php
    public function test_a_guest_can_view_pricing_and_is_offered_sign_up(): void
    {
        $response = $this->get(route('pricing'));

        $response->assertStatus(200);
        $response->assertSee(__('Plans & Pricing'));
        $response->assertSee(__('Sign up'));
        $response->assertSee(route('register'), false);
        $response->assertSee(route('login'), false);
    }

    public function test_a_signed_in_user_is_not_offered_sign_up(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user)->get(route('pricing'));

        $response->assertStatus(200);
        $response->assertDontSee(__('Sign up'));
    }
```

- [ ] **Step 2: Update the branding test that encoded the gate**

In `tests/Feature/Http/LayoutBrandingTest.php`:
- In `test_app_layout_pages_render_flexpick_branding_on_dark_canvas`, replace the three lines from `// pricing requires authentication` through `$response = $this->actingAs($user)->get(route('pricing'));` with `$response = $this->get(route('pricing'));` and delete the now-unused `$user = $this->createUser();` line.
- Delete `test_no_guest_reachable_page_links_to_the_gated_pricing_route` entirely (its docblock and body) — the premise is gone.

- [ ] **Step 3: Update the two referral link tests**

`tests/Feature/Services/ReferralServiceTest.php` — in `test_get_referral_link_returns_correct_url` replace the `assertEquals` line with:

```php
        $this->assertEquals(route('pricing').'?'.ReferralConstants::HTTP_PARAM_REFERRAL_CODE.'='.$referralCode->code, $link);
```

`tests/Feature/Referral/ReferralFlowTest.php` — in `test_referral_link_contains_correct_format` replace `$this->assertStringContainsString(url('/'), $link);` with:

```php
        $this->assertStringStartsWith(route('pricing').'?', $link);
```

- [ ] **Step 4: Run the tests to verify they fail**

Run: `php artisan test --compact --filter='PricingPageTest|LayoutBrandingTest|ReferralServiceTest|ReferralFlowTest'`
Expected: FAIL — guest gets 302 to login; referral link still points at `/?rc=`.

- [ ] **Step 5: Make the pricing route public**

In `routes/web.php` change:

```php
Route::get('/pricing', function () {
    return view('pricing');
})->name('pricing')->middleware('auth');
```

to:

```php
Route::get('/pricing', function () {
    return view('pricing');
})->name('pricing');
```

- [ ] **Step 6: Replace the guest text link with a Sign Up button**

In `resources/views/pricing.blade.php` replace the `@guest … @endguest` block with:

```blade
            @guest
                <div class="mt-6 flex flex-wrap items-center justify-center gap-4">
                    <x-button-link.primary href="{{ route('register') }}" class="text-lg py-3! px-6">
                        {{ __('Sign up') }}
                    </x-button-link.primary>
                    <p class="m-0 text-sm">
                        {{ __('Already have an account?') }}
                        <x-link href="{{ route('login') }}">{{ __('Log in') }}</x-link>
                    </p>
                </div>
            @endguest
```

- [ ] **Step 7: Point the referral link at pricing**

In `app/Services/ReferralService.php` replace the body of `getReferralLink`:

```php
    public function getReferralLink(User $user): string
    {
        $referralCode = $this->getOrCreateReferralCode($user);

        // Lands on the catalog, not the home redirect: a referred visitor
        // should see what they can buy before they are asked to sign up.
        return url()->query(route('pricing'), [ReferralConstants::HTTP_PARAM_REFERRAL_CODE => $referralCode->code]);
    }
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `php artisan test --compact --filter='PricingPageTest|LayoutBrandingTest|ReferralServiceTest|ReferralFlowTest|TrackReferralCodeTest'`
Expected: PASS.

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint app/Services/ReferralService.php routes/web.php tests/Feature/Http/Controllers/PricingPageTest.php tests/Feature/Http/LayoutBrandingTest.php tests/Feature/Services/ReferralServiceTest.php tests/Feature/Referral/ReferralFlowTest.php
git add routes/web.php resources/views/pricing.blade.php app/Services/ReferralService.php tests/Feature/Http/Controllers/PricingPageTest.php tests/Feature/Http/LayoutBrandingTest.php tests/Feature/Services/ReferralServiceTest.php tests/Feature/Referral/ReferralFlowTest.php
git commit -m "feat(pricing): public pricing page with sign-up CTA; referral links land on it"
```

---

### Task 2: Checkout inline signup honours invite-only registration

**Files:**
- Modify: `app/Livewire/Checkout/CheckoutForm.php` (props, `registerUser`, `sendOtpCode`, new helper)
- Modify: `app/Livewire/Checkout/ProductCheckoutForm.php`, `SubscriptionCheckoutForm.php`, `LocalSubscriptionCheckoutForm.php`, `ConvertLocalSubscriptionCheckoutForm.php` (`render()` view data)
- Modify: `app/Validator/RegisterValidator.php:16-22` (docblock only)
- Modify: `resources/views/livewire/checkout/partials/traditional-login-or-register.blade.php`
- Modify: `resources/views/livewire/checkout/partials/one-time-password.blade.php`
- Create: `tests/Feature/Livewire/Checkout/CheckoutInviteOnlyRegistrationTest.php`

**Interfaces:**
- Consumes: `ReferralRegistrationGate::requiresCodeInput(): bool`, `::isActive(): bool`, `::cookieName(): string`; `RegisterValidator::validate(array $fields, bool $passwordConfirmed = true, bool $inviteOnly = false)`; `ReferralConstants::REGISTRATION_CODE_FIELD = 'referral_code'`; `SessionConstants::REFERRAL_CODE`.
- Produces: `CheckoutForm::$referralCode` public Livewire property; view data key `requiresInvitationCode`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Livewire/Checkout/CheckoutInviteOnlyRegistrationTest.php`:

```php
<?php

namespace Tests\Feature\Livewire\Checkout;

use App\Constants\SessionConstants;
use App\Dto\CartDto;
use App\Dto\CartItemDto;
use App\Livewire\Checkout\ProductCheckoutForm;
use App\Models\Currency;
use App\Models\OneTimeProduct;
use App\Models\OneTimeProductPrice;
use App\Models\ReferralCode;
use App\Models\User;
use App\Services\SessionService;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\Feature\FeatureTest;

/**
 * With REFERRAL_ONLY_REGISTRATION on, /pricing is public and every plan CTA
 * leads to a checkout that registers guests inline. That form must apply
 * the same gate as /register, or the flag is a decoration.
 */
class CheckoutInviteOnlyRegistrationTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.referral.only_registration' => true]);
        $this->addPaymentProvider();
        $this->stubCartWith($this->product());
    }

    public function test_a_cold_guest_cannot_register_at_checkout_without_a_code(): void
    {
        Livewire::test(ProductCheckoutForm::class)
            ->set('name', 'Cold Visitor')
            ->set('email', 'cold@example.com')
            ->set('password', 'password')
            ->set('paymentProvider', 'paymore')
            ->call('checkout')
            ->assertHasErrors(['referral_code']);

        $this->assertDatabaseMissing('users', ['email' => 'cold@example.com']);
    }

    public function test_a_typed_valid_code_lets_a_guest_register_at_checkout(): void
    {
        $this->referralCode('PARTNER1');

        Livewire::test(ProductCheckoutForm::class)
            ->set('name', 'Invited Visitor')
            ->set('email', 'invited@example.com')
            ->set('password', 'password')
            ->set('referralCode', 'PARTNER1')
            ->set('paymentProvider', 'paymore')
            ->call('checkout')
            ->assertHasNoErrors()
            ->assertRedirect('http://paymore.com/checkout');

        $this->assertDatabaseHas('users', ['email' => 'invited@example.com']);
    }

    public function test_a_referred_visitor_carrying_a_session_code_never_sees_the_field(): void
    {
        $this->referralCode('PARTNER2');
        session([SessionConstants::REFERRAL_CODE => 'PARTNER2']);

        Livewire::test(ProductCheckoutForm::class)
            ->assertViewHas('requiresInvitationCode', false)
            ->set('name', 'Referred Visitor')
            ->set('email', 'referred@example.com')
            ->set('password', 'password')
            ->set('paymentProvider', 'paymore')
            ->call('checkout')
            ->assertHasNoErrors()
            ->assertRedirect('http://paymore.com/checkout');

        $this->assertDatabaseHas('users', ['email' => 'referred@example.com']);
    }

    public function test_the_field_is_offered_to_a_cold_guest(): void
    {
        Livewire::test(ProductCheckoutForm::class)
            ->assertViewHas('requiresInvitationCode', true)
            ->assertSee(__('Invitation code'));
    }

    public function test_the_gate_is_inert_when_the_flag_is_off(): void
    {
        config(['app.referral.only_registration' => false]);

        Livewire::test(ProductCheckoutForm::class)
            ->assertViewHas('requiresInvitationCode', false)
            ->set('name', 'Anyone')
            ->set('email', 'anyone@example.com')
            ->set('password', 'password')
            ->set('paymentProvider', 'paymore')
            ->call('checkout')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'anyone@example.com']);
    }

    private function product(): OneTimeProduct
    {
        $product = OneTimeProduct::factory()->create(['slug' => 'invite-gate-product', 'is_active' => true]);

        OneTimeProductPrice::create([
            'one_time_product_id' => $product->id,
            'currency_id' => Currency::where('code', 'USD')->first()->id,
            'price' => 100,
        ]);

        return $product;
    }

    private function stubCartWith(OneTimeProduct $product): void
    {
        $this->instance(SessionService::class, Mockery::mock(SessionService::class, function (MockInterface $mock) use ($product) {
            $cartDto = new CartDto;
            $cartItem = new CartItemDto;
            $cartItem->productId = $product->id;
            $cartDto->items = [$cartItem];
            $mock->shouldReceive('getCartDto')->andReturn($cartDto);
            $mock->shouldReceive('saveCartDto');
            $mock->shouldReceive('getCouponCode')->andReturn(null);
            $mock->shouldReceive('clearCouponCode');
            $mock->shouldReceive('shouldCreateTenantForFreePlanUser')->andReturn(false);
            $mock->shouldReceive('resetCreateTenantForFreePlanUser');
        }));
    }

    private function referralCode(string $code): ReferralCode
    {
        return ReferralCode::create(['user_id' => User::factory()->create()->id, 'code' => $code]);
    }
}
```

Check `ProductCheckoutFormTest::addPaymentProvider()` — it is defined in that test class, not in `FeatureTest`. Copy it into this test class verbatim (it registers a `paymore` provider mock via `PaymentService`); look at `tests/Feature/Livewire/Checkout/ProductCheckoutFormTest.php` for the method body and the `use` lines it needs (`PaymentProviderInterface`, `PaymentService`, `PaymentProvider`). Also confirm `ReferralCode` fillable columns with `sed -n 1,40p app/Models/ReferralCode.php` and adjust `referralCode()` if the factory/columns differ.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=CheckoutInviteOnlyRegistrationTest`
Expected: FAIL — `assertHasErrors(['referral_code'])` fails (user is created), `assertViewHas('requiresInvitationCode')` fails (key absent).

- [ ] **Step 3: Wire the gate into `CheckoutForm`**

In `app/Livewire/Checkout/CheckoutForm.php`:

Add imports:

```php
use App\Constants\ReferralConstants;
use App\Services\ReferralRegistrationGate;
```

Add a public property after `public $oneTimePassword;`:

```php
    /** Typed invitation code, only rendered while invite-only signup is on. */
    public string $referralCode = '';
```

Add a protected helper (the four concrete forms each override `render()`, so the base class cannot inject the key itself):

```php
    /** Whether the signup half of the form must ask for an invitation code. */
    protected function requiresInvitationCode(): bool
    {
        return app(ReferralRegistrationGate::class)->requiresCodeInput();
    }
```

Then in **each** of the four `render()` methods — `ProductCheckoutForm.php:139`, `SubscriptionCheckoutForm.php:61`, `LocalSubscriptionCheckoutForm.php:61`, `ConvertLocalSubscriptionCheckoutForm.php:61` — add to the view-data array, next to the existing `'userExists' => …` entry:

```php
            'requiresInvitationCode' => $this->requiresInvitationCode(),
```

In `registerUser()`, replace

```php
        $validator = $registerValidator->validate($fields, passwordConfirmed: false);
```

with

```php
        $fields = $this->withInvitationCode($fields);

        $validator = $registerValidator->validate($fields, passwordConfirmed: false, inviteOnly: true);
```

and replace the `createUser([...], true)` call's array with:

```php
        $user = $userService->createUser($this->withInvitationCode([
            'name' => $this->name,
            'email' => $this->email,
            'password' => $this->password,
        ]), true);
```

In `sendOtpCode()`'s `else` branch, make the same two substitutions: `$fields = $this->withInvitationCode($fields);` before `validate(...)`, add `inviteOnly: true`, and wrap the `createUser` array in `$this->withInvitationCode([...])`.

Add the helper at the end of the class:

```php
    /**
     * The checkout form registers guests too, so it enforces invite-only
     * signup exactly like /register. A referred visitor already carries the
     * code in session or cookie and is let through by the gate without
     * typing anything; a cold visitor is asked for one.
     */
    private function withInvitationCode(array $fields): array
    {
        if (app(ReferralRegistrationGate::class)->isActive()) {
            $fields[ReferralConstants::REGISTRATION_CODE_FIELD] = $this->referralCode;
        }

        return $fields;
    }
```

- [ ] **Step 4: Fix the validator docblock**

In `app/Validator/RegisterValidator.php` replace the `@param bool $inviteOnly` docblock with:

```php
    /**
     * @param  bool  $inviteOnly  Enforce REFERRAL_ONLY_REGISTRATION. Opt-in per
     *                            caller: every form that creates an account for
     *                            a visitor (register, OTP register, checkout)
     *                            passes true. Internal callers that create users
     *                            on someone's behalf (guest report unlock) leave
     *                            it off.
     */
```

- [ ] **Step 5: Render the field in both checkout partials**

`resources/views/livewire/checkout/partials/traditional-login-or-register.blade.php` — inside the final `@if(!$userExists || empty($email))` block, after the name field's `@enderror`, add:

```blade
    @if (!empty($requiresInvitationCode))
        <x-auth.invitation-code-field wire:model="referralCode" />
    @endif
```

`resources/views/livewire/checkout/partials/one-time-password.blade.php` — inside `@if(!$userExists)` after the name field's `@enderror`, add the same three lines.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact --filter='CheckoutInviteOnlyRegistrationTest|ProductCheckoutFormTest|SubscriptionCheckoutFormTest|LocalSubscriptionCheckoutFormTest|ConvertLocalSubscriptionCheckoutFormTest|RegisterValidatorTest'`
Expected: PASS. If `test_the_field_is_offered_to_a_cold_guest` fails on `assertSee` because the name block is hidden until an email is typed, add `->set('email', 'cold@example.com')` before `assertSee`.

- [ ] **Step 7: Format, static analysis, commit**

```bash
vendor/bin/pint app/Livewire/Checkout app/Validator/RegisterValidator.php tests/Feature/Livewire/Checkout/CheckoutInviteOnlyRegistrationTest.php
vendor/bin/phpstan analyse --no-progress | tail -5   # expect the 1 baseline error only
git add app/Livewire/Checkout/CheckoutForm.php app/Livewire/Checkout/ProductCheckoutForm.php app/Livewire/Checkout/SubscriptionCheckoutForm.php app/Livewire/Checkout/LocalSubscriptionCheckoutForm.php app/Livewire/Checkout/ConvertLocalSubscriptionCheckoutForm.php app/Validator/RegisterValidator.php resources/views/livewire/checkout/partials/traditional-login-or-register.blade.php resources/views/livewire/checkout/partials/one-time-password.blade.php tests/Feature/Livewire/Checkout/CheckoutInviteOnlyRegistrationTest.php
git commit -m "feat(checkout): inline signup enforces invite-only registration"
```

---

# Part C — FlexPick branding

### Task 3: FlexPick logo PNGs for email, invoices and OG images

**Files:**
- Replace: `public/images/logo-dark.png`, `public/images/logo-light.png`
- Modify: `tests/Feature/Http/LayoutBrandingTest.php`

**Interfaces:**
- Consumes: `config('app.logo.dark')` = `images/logo-dark.png` (coloured, for light backgrounds), `config('app.logo.light')` = `images/logo-light.png` (white, for dark backgrounds). No code reads change.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Http/LayoutBrandingTest.php`:

```php
    /**
     * The email layout, PDF invoices and OG images all read these two files.
     * They shipped as the SaaSykit mark; this pins the FlexPick replacement
     * so a deploy can never silently regress to the boilerplate logo.
     */
    public function test_the_raster_logos_are_the_flexpick_mark(): void
    {
        foreach (['logo-dark.png', 'logo-light.png'] as $file) {
            $path = public_path('images/'.$file);

            $this->assertFileExists($path);
            $this->assertSame('image/png', mime_content_type($path));

            [$width, $height] = getimagesize($path);
            // The SaaSykit originals were 560x133; the FlexPick mark is 300x54.
            $this->assertSame(300, $width, $file);
            $this->assertSame(54, $height, $file);
        }
    }

    public function test_the_email_layout_carries_the_flexpick_logo_and_no_saasykit_text(): void
    {
        $html = view('components.layouts.email', ['slot' => 'body'])->render();

        $this->assertStringContainsString('images/logo-dark.png', $html);
        $this->assertStringNotContainsStringIgnoringCase('saasykit', $html);
    }
```

If `view('components.layouts.email', ...)` cannot render an anonymous component directly, use `Blade::render('<x-layouts.email>body</x-layouts.email>')` instead (import `Illuminate\Support\Facades\Blade`).

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=LayoutBrandingTest`
Expected: FAIL — width is 560, not 300.

- [ ] **Step 3: Generate the PNGs from the landing-site mark**

```bash
convert ../frontend/public/images/logo.png -type TrueColorAlpha PNG32:public/images/logo-dark.png
convert ../frontend/public/images/logo.png -type TrueColorAlpha -fill white -colorize 100 PNG32:public/images/logo-light.png
identify public/images/logo-dark.png public/images/logo-light.png
```

Expected: both `PNG 300x54 … 8-bit sRGB` with alpha. Open each with the Read tool to eyeball: dark = blue icon + orange/blue wordmark on transparent; light = white silhouette on transparent.

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact --filter=LayoutBrandingTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add public/images/logo-dark.png public/images/logo-light.png tests/Feature/Http/LayoutBrandingTest.php
git commit -m "feat(brand): FlexPick raster logos for email, invoices and OG images"
```

---

### Task 4: Remove remaining SaaSykit strings and views

**Files:**
- Modify: `config/app.php:19`
- Modify: `config/invoices.php:54-55`
- Modify: `resources/views/components/layouts/partials/head.blade.php:9,11`
- Modify: `resources/views/components/layouts/partials/social-cards.blade.php:1-2,16,19`
- Modify: `app/Providers/Filament/AdminPanelProvider.php:46`
- Delete: `resources/views/coming-soon/horizontal.blade.php`, `resources/views/coming-soon/vertical.blade.php`
- Modify: `tests/Feature/Http/LayoutBrandingTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Http/LayoutBrandingTest.php`:

```php
    public function test_no_public_page_mentions_the_boilerplate_vendor(): void
    {
        foreach ([route('pricing'), route('login'), route('register'), '/terms-of-service', '/privacy-policy'] as $url) {
            $this->get($url)->assertOk()->assertDontSee('SaaSykit', false);
        }

        $this->assertFileDoesNotExist(resource_path('views/coming-soon/horizontal.blade.php'));
        $this->assertSame('FlexPick', config('app.name'));
        $this->assertStringNotContainsString('SaaSykit', (string) config('invoices.seller.attributes.name'));
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=test_no_public_page_mentions_the_boilerplate_vendor`
Expected: FAIL on `assertFileDoesNotExist`. (The page assertions may already pass because `APP_NAME=FlexPick` in `.env`; that is fine — the file and config assertions are the teeth.)

- [ ] **Step 3: Apply the string and view changes**

`config/app.php`: `'name' => env('APP_NAME', 'SaaSykit'),` → `'name' => env('APP_NAME', 'FlexPick'),`

`config/invoices.php`: `'name' => 'SaaSykit Company Inc.',` → `'name' => 'FlexPick',` and `'address' => 'SaaSy Street 123',` → `'address' => '',`

`head.blade.php`: both `config('app.name', 'SaaSykit')` → `config('app.name')`.

`social-cards.blade.php`: every `config('app.name', 'SaaSykit')` → `config('app.name')` and every `config('app.description', 'SaaSykit')` → `config('app.description')`.

`AdminPanelProvider.php`: directly before `->favicon(asset('images/favicon.ico'))` insert:

```php
            ->brandName('FlexPick')
            ->brandLogo(asset('images/flexpick-wordmark-dark-text.svg'))
            ->darkModeBrandLogo(asset('images/flexpick-wordmark.svg'))
            ->brandLogoHeight('1.75rem')
```

Delete the views:

```bash
git rm -q resources/views/coming-soon/horizontal.blade.php resources/views/coming-soon/vertical.blade.php
grep -rn "coming-soon" app routes resources config   # expect no output
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter='LayoutBrandingTest|ConfigServiceTest|LegalPagesTest|InvoiceSettings'`
Expected: PASS. If `ConfigServiceTest` asserted the `SaaSykit` default of `app.name`, update that assertion to `FlexPick`.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint config/app.php config/invoices.php app/Providers/Filament/AdminPanelProvider.php tests/Feature/Http/LayoutBrandingTest.php
git add config/app.php config/invoices.php resources/views/components/layouts/partials/head.blade.php resources/views/components/layouts/partials/social-cards.blade.php app/Providers/Filament/AdminPanelProvider.php tests/Feature/Http/LayoutBrandingTest.php
git commit -m "chore(brand): drop remaining SaaSykit fallbacks and views"
```

---

# Part A — Workspace-owned audits and quotas

### Task 5: Schema — `audit_requests.tenant_id`, `tenant_parameters`, model relations

**Files:**
- Create: `database/migrations/2026_09_13_000001_create_tenant_parameters_table.php`
- Create: `database/migrations/2026_09_13_000002_add_tenant_id_to_audit_requests_table.php`
- Create: `app/Models/TenantParameter.php`
- Create: `database/factories/TenantParameterFactory.php`
- Modify: `app/Models/Tenant.php`
- Modify: `app/Models/AuditRequest.php`
- Create: `tests/Feature/Models/AuditRequestForTenantScopeTest.php`

**Interfaces:**
- Produces: `AuditRequest::scopeForTenant(Builder, Tenant): Builder`, `AuditRequest::tenant(): BelongsTo`, `Tenant::auditRequests(): HasMany`, `Tenant::parameters(): HasMany`, `TenantParameter` (fillable `tenant_id`, `name`, `value`).
- Leaves `scopeForUser` in place (removed in Task 13).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Models/AuditRequestForTenantScopeTest.php`:

```php
<?php

namespace Tests\Feature\Models;

use App\Models\AuditRequest;
use App\Models\Tenant;
use App\Models\TenantParameter;
use Tests\Feature\FeatureTest;

class AuditRequestForTenantScopeTest extends FeatureTest
{
    public function test_for_tenant_matches_only_that_tenants_rows(): void
    {
        $tenant = Tenant::factory()->create();
        $other = Tenant::factory()->create();

        $mine = AuditRequest::factory()->create(['tenant_id' => $tenant->id]);
        $theirs = AuditRequest::factory()->create(['tenant_id' => $other->id]);
        $unclaimed = AuditRequest::factory()->create(['tenant_id' => null]);

        $ids = AuditRequest::forTenant($tenant)->pluck('id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id));
        $this->assertFalse($ids->contains($unclaimed->id));
        $this->assertTrue($mine->tenant->is($tenant));
        $this->assertTrue($tenant->auditRequests->contains($mine));
    }

    public function test_deleting_a_tenant_orphans_its_audits_rather_than_deleting_them(): void
    {
        $tenant = Tenant::factory()->create();
        $request = AuditRequest::factory()->create(['tenant_id' => $tenant->id]);

        $tenant->delete();

        $this->assertNull($request->fresh()->tenant_id);
    }

    public function test_tenant_parameters_are_unique_per_name(): void
    {
        $tenant = Tenant::factory()->create();

        TenantParameter::create(['tenant_id' => $tenant->id, 'name' => 'k', 'value' => '1']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        TenantParameter::create(['tenant_id' => $tenant->id, 'name' => 'k', 'value' => '2']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=AuditRequestForTenantScopeTest`
Expected: FAIL — `forTenant` undefined / column missing.

- [ ] **Step 3: Create the `tenant_parameters` migration, model, factory**

`database/migrations/2026_09_13_000001_create_tenant_parameters_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_parameters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('name');
            $table->text('value');
            $table->unique(['tenant_id', 'name']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_parameters');
    }
};
```

`app/Models/TenantParameter.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Workspace-scoped key/value, the tenant counterpart of UserParameter.
 * Holds what every member of a workspace shares: purchased audit credits,
 * bonus free runs and the pending checkout intent.
 */
class TenantParameter extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'name',
        'value',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
```

`database/factories/TenantParameterFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantParameterFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => $this->faker->slug(2),
            'value' => '0',
        ];
    }
}
```

- [ ] **Step 4: Create the `tenant_id` migration (column only — backfill is Task 6)**

`database/migrations/2026_09_13_000002_add_tenant_id_to_audit_requests_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            // The monthly meter: forTenant + funding + tier + created_at.
            $table->index(['tenant_id', 'funding', 'tier', 'created_at'], 'audit_requests_tenant_meter_index');
        });
    }

    public function down(): void
    {
        Schema::table('audit_requests', function (Blueprint $table) {
            $table->dropIndex('audit_requests_tenant_meter_index');
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};
```

- [ ] **Step 5: Model relations and scope**

`app/Models/AuditRequest.php`:
- Add `'tenant_id'` to `$fillable` right after `'user_id'`.
- Directly above `scopeForUser`, add:

```php
    /**
     * All audits owned by the workspace. Ownership is the tenant the run was
     * made for, never the member who clicked -- every member sees the same
     * history and spends the same quota.
     *
     * @param  Builder<AuditRequest>  $query
     * @return Builder<AuditRequest>
     */
    public function scopeForTenant(Builder $query, Tenant $tenant): Builder
    {
        return $query->where('tenant_id', $tenant->id);
    }
```

- After `user()`, add:

```php
    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
```

`app/Models/Tenant.php` — after `orders()`, add:

```php
    public function auditRequests(): HasMany
    {
        return $this->hasMany(AuditRequest::class);
    }

    public function parameters(): HasMany
    {
        return $this->hasMany(TenantParameter::class);
    }
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --compact --filter=AuditRequestForTenantScopeTest`
Expected: PASS (the test DB migrates fresh).

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint app/Models/AuditRequest.php app/Models/Tenant.php app/Models/TenantParameter.php database/migrations/2026_09_13_000001_create_tenant_parameters_table.php database/migrations/2026_09_13_000002_add_tenant_id_to_audit_requests_table.php database/factories/TenantParameterFactory.php tests/Feature/Models/AuditRequestForTenantScopeTest.php
git add app/Models/AuditRequest.php app/Models/Tenant.php app/Models/TenantParameter.php database/migrations/2026_09_13_000001_create_tenant_parameters_table.php database/migrations/2026_09_13_000002_add_tenant_id_to_audit_requests_table.php database/factories/TenantParameterFactory.php tests/Feature/Models/AuditRequestForTenantScopeTest.php
git commit -m "feat(audit): tenant_id on audit requests and tenant_parameters table"
```

---

### Task 6: Backfill legacy rows onto their tenant

**Files:**
- Create: `database/migrations/2026_09_13_000003_backfill_audit_tenant_ownership.php`
- Create: `tests/Feature/Migrations/AuditTenantBackfillTest.php`

**Interfaces:**
- Consumes: `tenants.created_by`, `tenant_user (tenant_id, user_id, is_default, created_at)`, `user_parameters`, `tenant_parameters`.
- Resolution rule (shared with Task 7's `PrimaryTenantResolver`): tenant `created_by` the user (lowest id) → else the user's `tenant_user` row ordered `is_default desc, created_at asc, tenant_id asc` → else none.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Migrations/AuditTenantBackfillTest.php`:

```php
<?php

namespace Tests\Feature\Migrations;

use App\Models\AuditRequest;
use App\Models\Tenant;
use App\Models\TenantParameter;
use App\Models\User;
use App\Models\UserParameter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTest;

/**
 * Re-runs the backfill migration's data step against seeded legacy rows.
 * The migration is idempotent (it only touches rows with a NULL tenant_id),
 * so calling it again on an already-migrated database is safe.
 */
class AuditTenantBackfillTest extends FeatureTest
{
    private const MIGRATION = 'database/migrations/2026_09_13_000003_backfill_audit_tenant_ownership.php';

    private function runBackfill(): void
    {
        Artisan::call('migrate:refresh', ['--path' => self::MIGRATION, '--force' => true]);
    }

    public function test_a_row_is_assigned_to_the_tenant_its_user_created(): void
    {
        $user = User::factory()->create();
        $joined = Tenant::factory()->create();
        $joined->users()->attach($user);
        $created = Tenant::factory()->create(['created_by' => $user->id]);
        $created->users()->attach($user);
        $request = AuditRequest::factory()->create(['user_id' => $user->id, 'tenant_id' => null]);

        $this->runBackfill();

        $this->assertSame($created->id, $request->fresh()->tenant_id);
    }

    public function test_a_row_matched_by_email_falls_back_to_the_earliest_membership(): void
    {
        $user = User::factory()->create(['email' => 'legacy@example.com']);
        $first = Tenant::factory()->create();
        $second = Tenant::factory()->create();
        DB::table('tenant_user')->insert([
            ['tenant_id' => $second->id, 'user_id' => $user->id, 'created_at' => now()->subDay(), 'updated_at' => now()],
            ['tenant_id' => $first->id, 'user_id' => $user->id, 'created_at' => now()->subDays(2), 'updated_at' => now()],
        ]);
        $request = AuditRequest::factory()->create(['user_id' => null, 'email' => 'legacy@example.com', 'tenant_id' => null]);

        $this->runBackfill();

        $this->assertSame($first->id, $request->fresh()->tenant_id);
    }

    public function test_a_default_membership_beats_an_earlier_one(): void
    {
        $user = User::factory()->create();
        $earlier = Tenant::factory()->create();
        $default = Tenant::factory()->create();
        DB::table('tenant_user')->insert([
            ['tenant_id' => $earlier->id, 'user_id' => $user->id, 'is_default' => false, 'created_at' => now()->subDays(2), 'updated_at' => now()],
            ['tenant_id' => $default->id, 'user_id' => $user->id, 'is_default' => true, 'created_at' => now()->subDay(), 'updated_at' => now()],
        ]);
        $request = AuditRequest::factory()->create(['user_id' => $user->id, 'tenant_id' => null]);

        $this->runBackfill();

        $this->assertSame($default->id, $request->fresh()->tenant_id);
    }

    public function test_a_row_with_no_resolvable_tenant_stays_unclaimed(): void
    {
        $orphan = AuditRequest::factory()->create(['user_id' => null, 'email' => 'nobody@example.com', 'tenant_id' => null]);
        $tenantless = AuditRequest::factory()->create(['user_id' => User::factory()->create()->id, 'tenant_id' => null]);

        $this->runBackfill();

        $this->assertNull($orphan->fresh()->tenant_id);
        $this->assertNull($tenantless->fresh()->tenant_id);
    }

    public function test_an_already_claimed_row_is_not_rehomed(): void
    {
        $user = User::factory()->create();
        $created = Tenant::factory()->create(['created_by' => $user->id]);
        $created->users()->attach($user);
        $elsewhere = Tenant::factory()->create();
        $request = AuditRequest::factory()->create(['user_id' => $user->id, 'tenant_id' => $elsewhere->id]);

        $this->runBackfill();

        $this->assertSame($elsewhere->id, $request->fresh()->tenant_id);
    }

    public function test_user_credits_and_bonus_are_summed_onto_the_tenant_and_removed_from_the_user(): void
    {
        $tenant = Tenant::factory()->create();
        $a = User::factory()->create();
        $b = User::factory()->create();
        $tenant->users()->attach([$a->id, $b->id]);
        UserParameter::create(['user_id' => $a->id, 'name' => 'audit_purchased_credits_deep_ai', 'value' => '2']);
        UserParameter::create(['user_id' => $b->id, 'name' => 'audit_purchased_credits_deep_ai', 'value' => '3']);
        UserParameter::create(['user_id' => $a->id, 'name' => 'audit_bonus_free_runs', 'value' => '1']);
        UserParameter::create(['user_id' => $a->id, 'name' => 'audit_tier_intent', 'value' => 'some-uuid']);
        UserParameter::create(['user_id' => $a->id, 'name' => 'unrelated', 'value' => 'keep']);

        $this->runBackfill();

        $this->assertSame('5', TenantParameter::where('tenant_id', $tenant->id)->where('name', 'audit_purchased_credits_deep_ai')->value('value'));
        $this->assertSame('1', TenantParameter::where('tenant_id', $tenant->id)->where('name', 'audit_bonus_free_runs')->value('value'));
        $this->assertDatabaseMissing('user_parameters', ['name' => 'audit_purchased_credits_deep_ai']);
        $this->assertDatabaseMissing('user_parameters', ['name' => 'audit_bonus_free_runs']);
        $this->assertDatabaseMissing('user_parameters', ['name' => 'audit_tier_intent']);
        $this->assertDatabaseHas('user_parameters', ['user_id' => $a->id, 'name' => 'unrelated']);
    }

    public function test_a_credit_whose_user_has_no_tenant_is_left_in_place(): void
    {
        $loner = User::factory()->create();
        UserParameter::create(['user_id' => $loner->id, 'name' => 'audit_purchased_credits_expert', 'value' => '1']);

        $this->runBackfill();

        $this->assertDatabaseHas('user_parameters', ['user_id' => $loner->id, 'name' => 'audit_purchased_credits_expert']);
        $this->assertSame(0, TenantParameter::count());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=AuditTenantBackfillTest`
Expected: FAIL — migration file not found.

- [ ] **Step 3: Write the backfill migration**

`database/migrations/2026_09_13_000003_backfill_audit_tenant_ownership.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Audits and audit quotas moved from the user to the workspace. Assign
 * every legacy row to the workspace its requester most plausibly meant:
 * the one they created, else their default/earliest membership. Rows that
 * resolve to nothing stay NULL and are claimed later by
 * ClaimAuditRequestsForTenant when the person gets a workspace.
 *
 * Idempotent: only NULL tenant_id rows and only audit-related user
 * parameters are touched. Not reversible -- down() is a no-op by design.
 */
return new class extends Migration
{
    private const MOVED_PARAMS = [
        'audit_purchased_credits_diagnostic',
        'audit_purchased_credits_deep_ai',
        'audit_purchased_credits_expert',
        'audit_bonus_free_runs',
    ];

    public function up(): void
    {
        $tenantByUser = $this->primaryTenantByUserId();

        $this->assignAuditRequests($tenantByUser);
        $this->moveUserParameters($tenantByUser);

        // An in-flight dashboard checkout intent is not worth carrying
        // across the ownership change; the buyer simply starts again.
        DB::table('user_parameters')->where('name', 'audit_tier_intent')->delete();
    }

    public function down(): void
    {
        // Data migration; the columns are dropped by their own migrations.
    }

    /** @return array<int, int> user_id => tenant_id */
    private function primaryTenantByUserId(): array
    {
        $result = [];

        // Earliest-created tenant per creator wins outright.
        foreach (DB::table('tenants')->whereNotNull('created_by')->orderBy('id')->get(['id', 'created_by']) as $row) {
            $result[(int) $row->created_by] ??= (int) $row->id;
        }

        // Otherwise the default membership, then the earliest one.
        $memberships = DB::table('tenant_user')
            ->orderByDesc('is_default')
            ->orderBy('created_at')
            ->orderBy('tenant_id')
            ->get(['user_id', 'tenant_id']);

        foreach ($memberships as $row) {
            $result[(int) $row->user_id] ??= (int) $row->tenant_id;
        }

        return $result;
    }

    /** @param array<int, int> $tenantByUser */
    private function assignAuditRequests(array $tenantByUser): void
    {
        $userIdByEmail = DB::table('users')
            ->get(['id', 'email'])
            ->mapWithKeys(fn ($user) => [strtolower((string) $user->email) => (int) $user->id])
            ->all();

        DB::table('audit_requests')
            ->whereNull('tenant_id')
            ->orderBy('id')
            ->select(['id', 'user_id', 'email'])
            ->chunkById(500, function ($rows) use ($tenantByUser, $userIdByEmail): void {
                foreach ($rows as $row) {
                    $userId = $row->user_id !== null ? (int) $row->user_id : ($userIdByEmail[strtolower((string) $row->email)] ?? null);
                    $tenantId = $userId !== null ? ($tenantByUser[$userId] ?? null) : null;

                    if ($tenantId === null) {
                        continue;
                    }

                    DB::table('audit_requests')->where('id', $row->id)->update(['tenant_id' => $tenantId]);
                }
            });
    }

    /** @param array<int, int> $tenantByUser */
    private function moveUserParameters(array $tenantByUser): void
    {
        $rows = DB::table('user_parameters')->whereIn('name', self::MOVED_PARAMS)->get();

        foreach ($rows as $row) {
            $tenantId = $tenantByUser[(int) $row->user_id] ?? null;

            if ($tenantId === null) {
                continue; // no workspace to move it to; harmless and no longer read
            }

            $existing = DB::table('tenant_parameters')->where('tenant_id', $tenantId)->where('name', $row->name)->first();
            $sum = (int) ($existing->value ?? 0) + (int) $row->value;

            if ($existing === null) {
                DB::table('tenant_parameters')->insert([
                    'tenant_id' => $tenantId,
                    'name' => $row->name,
                    'value' => (string) $sum,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('tenant_parameters')->where('id', $existing->id)->update(['value' => (string) $sum, 'updated_at' => now()]);
            }

            DB::table('user_parameters')->where('id', $row->id)->delete();
        }
    }
};
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact --filter=AuditTenantBackfillTest`
Expected: PASS.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint database/migrations/2026_09_13_000003_backfill_audit_tenant_ownership.php tests/Feature/Migrations/AuditTenantBackfillTest.php
git add database/migrations/2026_09_13_000003_backfill_audit_tenant_ownership.php tests/Feature/Migrations/AuditTenantBackfillTest.php
git commit -m "feat(audit): backfill audit ownership and credits onto tenants"
```

---

### Task 7: `PrimaryTenantResolver`, claim listener, referral bonus to tenant

**Files:**
- Create: `app/Services/PrimaryTenantResolver.php`
- Create: `app/Listeners/Tenant/ClaimAuditRequestsForTenant.php`
- Modify: `app/Listeners/Referral/GrantAuditBonusOnReferral.php`
- Create: `tests/Feature/Services/PrimaryTenantResolverTest.php`
- Create: `tests/Feature/Listeners/ClaimAuditRequestsForTenantTest.php`
- Modify: `tests/Feature/Listeners/GrantAuditBonusOnReferralTest.php`

**Interfaces:**
- Produces: `PrimaryTenantResolver::resolve(User): ?Tenant` (same rule as Task 6). `ClaimAuditRequestsForTenant::handle(TenantCreated|UserJoinedTenant): void` (auto-discovered — Laravel event discovery is on; `HandleAuditTierOrder` is registered nowhere and works).
- `GrantAuditBonusOnReferral` writes `TenantParameter audit_bonus_free_runs` on the referrer's primary tenant; no-op when they have none.
- Note: `AuditEntitlementService::BONUS_PARAM` still exists; the bonus test's `freeRunsLimit($email)` assertion is replaced in Task 8 — here assert on the row only.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Services/PrimaryTenantResolverTest.php`:

```php
<?php

namespace Tests\Feature\Services;

use App\Models\Tenant;
use App\Models\User;
use App\Services\PrimaryTenantResolver;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTest;

class PrimaryTenantResolverTest extends FeatureTest
{
    public function test_the_tenant_the_user_created_wins(): void
    {
        $user = User::factory()->create();
        $joined = Tenant::factory()->create();
        $joined->users()->attach($user, ['is_default' => true]);
        $created = Tenant::factory()->create(['created_by' => $user->id]);
        $created->users()->attach($user);

        $this->assertTrue(app(PrimaryTenantResolver::class)->resolve($user)->is($created));
    }

    public function test_default_membership_then_earliest(): void
    {
        $user = User::factory()->create();
        $earlier = Tenant::factory()->create();
        $default = Tenant::factory()->create();
        DB::table('tenant_user')->insert([
            ['tenant_id' => $earlier->id, 'user_id' => $user->id, 'is_default' => false, 'created_at' => now()->subDays(2), 'updated_at' => now()],
            ['tenant_id' => $default->id, 'user_id' => $user->id, 'is_default' => true, 'created_at' => now()->subDay(), 'updated_at' => now()],
        ]);

        $this->assertTrue(app(PrimaryTenantResolver::class)->resolve($user)->is($default));

        DB::table('tenant_user')->update(['is_default' => false]);

        $this->assertTrue(app(PrimaryTenantResolver::class)->resolve($user)->is($earlier));
    }

    public function test_no_tenant_resolves_to_null(): void
    {
        $this->assertNull(app(PrimaryTenantResolver::class)->resolve(User::factory()->create()));
    }
}
```

`tests/Feature/Listeners/ClaimAuditRequestsForTenantTest.php`:

```php
<?php

namespace Tests\Feature\Listeners;

use App\Events\Tenant\TenantCreated;
use App\Events\Tenant\UserJoinedTenant;
use App\Models\AuditRequest;
use App\Models\Tenant;
use App\Models\User;
use Tests\Feature\FeatureTest;

class ClaimAuditRequestsForTenantTest extends FeatureTest
{
    public function test_tenant_created_claims_unclaimed_rows_by_user_id_and_email(): void
    {
        $user = User::factory()->create(['email' => 'claim@example.com']);
        $tenant = Tenant::factory()->create(['created_by' => $user->id]);
        $byId = AuditRequest::factory()->create(['user_id' => $user->id, 'email' => 'other@example.com']);
        $byEmail = AuditRequest::factory()->create(['user_id' => null, 'email' => 'Claim@Example.com']);
        $stranger = AuditRequest::factory()->create(['user_id' => null, 'email' => 'stranger@example.com']);

        TenantCreated::dispatch($tenant, $user);

        $this->assertSame($tenant->id, $byId->fresh()->tenant_id);
        $this->assertSame($tenant->id, $byEmail->fresh()->tenant_id);
        $this->assertNull($stranger->fresh()->tenant_id);
    }

    public function test_user_joined_tenant_claims_too(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $request = AuditRequest::factory()->create(['user_id' => $user->id]);

        UserJoinedTenant::dispatch($user, $tenant);

        $this->assertSame($tenant->id, $request->fresh()->tenant_id);
    }

    public function test_an_already_claimed_row_is_never_rehomed(): void
    {
        $user = User::factory()->create();
        $first = Tenant::factory()->create();
        $second = Tenant::factory()->create();
        $request = AuditRequest::factory()->create(['user_id' => $user->id, 'tenant_id' => $first->id]);

        UserJoinedTenant::dispatch($user, $second);

        $this->assertSame($first->id, $request->fresh()->tenant_id);
    }
}
```

`tests/Feature/Listeners/GrantAuditBonusOnReferralTest.php` — replace the whole file:

```php
<?php

namespace Tests\Feature\Listeners;

use App\Events\Referral\ReferralSucceeded;
use App\Models\Referral;
use App\Models\Tenant;
use App\Models\TenantParameter;
use App\Models\User;
use App\Services\AuditReport\AuditEntitlementService;
use Tests\Feature\FeatureTest;

class GrantAuditBonusOnReferralTest extends FeatureTest
{
    public function test_referral_success_grants_a_bonus_run_to_the_referrers_workspace(): void
    {
        $referrer = User::factory()->create();
        $tenant = Tenant::factory()->create(['created_by' => $referrer->id]);
        $tenant->users()->attach($referrer);
        $referred = User::factory()->create();
        $referral = Referral::create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'referral_code' => 'testcode',
            'status' => 'rewarded',
        ]);

        ReferralSucceeded::dispatch($referrer, $referred, $referral);
        ReferralSucceeded::dispatch($referrer, $referred, $referral);

        $value = TenantParameter::query()
            ->where('tenant_id', $tenant->id)
            ->where('name', AuditEntitlementService::BONUS_PARAM)
            ->value('value');

        $this->assertSame('2', $value);
    }

    public function test_a_referrer_without_a_workspace_gets_nothing_and_nothing_breaks(): void
    {
        $referrer = User::factory()->create();
        $referred = User::factory()->create();
        $referral = Referral::create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'referral_code' => 'lonely',
            'status' => 'rewarded',
        ]);

        ReferralSucceeded::dispatch($referrer, $referred, $referral);

        $this->assertSame(0, TenantParameter::count());
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter='PrimaryTenantResolverTest|ClaimAuditRequestsForTenantTest|GrantAuditBonusOnReferralTest'`
Expected: FAIL — class not found / rows unchanged / bonus still on `user_parameters`.

- [ ] **Step 3: Implement the resolver**

`app/Services/PrimaryTenantResolver.php`:

```php
<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;

/**
 * The one workspace to credit when something happens to a *person* rather
 * than inside a workspace (a referral reward, a legacy backfill). Same rule
 * as the 2026_09_13 backfill migration: the workspace they created, else
 * their default membership, else the earliest one.
 */
class PrimaryTenantResolver
{
    public function resolve(User $user): ?Tenant
    {
        $created = Tenant::query()->where('created_by', $user->id)->orderBy('id')->first();

        if ($created !== null) {
            return $created;
        }

        return $user->tenants()
            ->orderByPivot('is_default', 'desc')
            ->orderByPivot('created_at')
            ->orderBy('tenants.id')
            ->first();
    }
}
```

- [ ] **Step 4: Implement the claim listener**

`app/Listeners/Tenant/ClaimAuditRequestsForTenant.php`:

```php
<?php

namespace App\Listeners\Tenant;

use App\Events\Tenant\TenantCreated;
use App\Events\Tenant\UserJoinedTenant;
use App\Models\AuditRequest;

/**
 * A landing-page audit is submitted before the visitor has a workspace, so
 * it is stored tenantless. The first workspace the person gets -- created
 * or joined -- takes those rows, exactly once: a claimed row is never
 * re-homed, so joining a second workspace brings nothing along.
 */
class ClaimAuditRequestsForTenant
{
    public function handle(TenantCreated|UserJoinedTenant $event): void
    {
        $user = $event instanceof TenantCreated ? $event->tenantCreator : $event->user;

        AuditRequest::query()
            ->whereNull('tenant_id')
            ->where(function ($query) use ($user): void {
                $query->where('user_id', $user->id)
                    ->orWhereRaw('LOWER(email) = ?', [strtolower($user->email)]);
            })
            ->update(['tenant_id' => $event->tenant->id]);
    }
}
```

If event discovery does not register a union-typed `handle()` for both events, split into two public methods `handleCreated(TenantCreated $event)` / `handleJoined(UserJoinedTenant $event)` that call a private `claim(User $user, Tenant $tenant)` — discovery keys on the parameter type of any `handle*`-prefixed public method. Verify with `php artisan event:list --event=TenantCreated`.

- [ ] **Step 5: Move the referral bonus to the tenant**

Replace `app/Listeners/Referral/GrantAuditBonusOnReferral.php`:

```php
<?php

namespace App\Listeners\Referral;

use App\Events\Referral\ReferralSucceeded;
use App\Models\TenantParameter;
use App\Services\AuditReport\AuditEntitlementService;
use App\Services\PrimaryTenantResolver;

class GrantAuditBonusOnReferral
{
    public function __construct(
        private PrimaryTenantResolver $primaryTenants,
    ) {}

    public function handle(ReferralSucceeded $event): void
    {
        // Free runs are a workspace quota, so the reward lands on the
        // referrer's workspace; a referrer with none has nowhere to spend it.
        $tenant = $this->primaryTenants->resolve($event->referrer);

        if ($tenant === null) {
            return;
        }

        $parameter = TenantParameter::firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => AuditEntitlementService::BONUS_PARAM],
            ['value' => '0'],
        );

        $parameter->update(['value' => (string) ((int) $parameter->value + 1)]);
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact --filter='PrimaryTenantResolverTest|ClaimAuditRequestsForTenantTest|GrantAuditBonusOnReferralTest|ReferralFlowTest'`
Expected: PASS.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint app/Services/PrimaryTenantResolver.php app/Listeners/Tenant/ClaimAuditRequestsForTenant.php app/Listeners/Referral/GrantAuditBonusOnReferral.php tests/Feature/Services/PrimaryTenantResolverTest.php tests/Feature/Listeners/ClaimAuditRequestsForTenantTest.php tests/Feature/Listeners/GrantAuditBonusOnReferralTest.php
git add app/Services/PrimaryTenantResolver.php app/Listeners/Tenant/ClaimAuditRequestsForTenant.php app/Listeners/Referral/GrantAuditBonusOnReferral.php tests/Feature/Services/PrimaryTenantResolverTest.php tests/Feature/Listeners/ClaimAuditRequestsForTenantTest.php tests/Feature/Listeners/GrantAuditBonusOnReferralTest.php
git commit -m "feat(audit): claim pre-signup audits into the first workspace; referral bonus goes to the workspace"
```

---

### Task 8: `AuditEntitlementService` keyed on the tenant

**Files:**
- Modify: `app/Services/AuditReport/AuditEntitlementService.php` (rewrite)
- Modify: `app/Services/AuditRequestService.php:107`
- Modify: `app/Filament/Dashboard/Pages/AuditReports.php` (signature call sites only: lines 126-131, 182-220, 296-319, 382-393)
- Modify: `app/Filament/Dashboard/Resources/AuditRequests/Pages/ListAuditRequests.php:24`
- Modify: `app/Filament/Dashboard/Resources/AuditRequests/AuditRequestResource.php:65-73`
- Modify: `app/Filament/Dashboard/Widgets/RecentAuditsWidget.php:111-119`, `LatestHealthWidget.php:32-40`, `PlanUsageWidget.php:22-44`
- Modify: `app/Filament/Dashboard/Pages/Dashboard.php:34-37`
- Modify: `app/Console/Commands/RunScheduledAudits.php:29`
- Modify: `app/Listeners/Order/HandleAuditTierOrder.php:146-156`
- Modify: `tests/Feature/Services/AuditEntitlementServiceTest.php`, `tests/Feature/Services/AuditSubscriptionEntitlementTest.php`, `tests/Feature/Services/AuditSnapshotEntitlementTest.php`, `tests/Feature/Models/AuditRequestForUserScopeTest.php`

**Interfaces (Produces — every later task uses these exact names):**

```php
// anonymous funnel (email-keyed, no bonus)
freeRunsLimitForEmail(string $email): int
freeRunsUsedForEmail(string $email): int
hasFreeRunForEmail(string $email): bool
consumeFreeRun(AuditRequest $auditRequest): void          // unchanged
// workspace
freeRunsLimit(Tenant $tenant): int
freeRunsUsed(Tenant $tenant): int
hasFreeRun(Tenant $tenant): bool
allowance(?Tenant $tenant, AuditTier $tier): int          // unchanged
runsUsedThisMonth(Tenant $tenant, AuditTier $tier): int
remainingRuns(Tenant $tenant, AuditTier $tier): int
quotaFor(Tenant $tenant, AuditTier $tier): TierQuota
purchasedCreditBalance(Tenant $tenant, AuditTier $tier): int
grantPurchasedCredit(Tenant $tenant, AuditTier $tier, int $quantity = 1): void
spendPurchasedCredit(Tenant $tenant, AuditTier $tier): void
consume(Tenant $tenant, AuditTier $tier, TierQuota $quota): AuditFunding
quotas(Tenant $tenant): array
hasAuditAccess(Tenant $tenant): bool
```

Callers in the dashboard pass `Filament::getTenant()`; it is non-null inside the tenant-scoped panel. Where a call site previously null-checked `$user`, keep the check and additionally `return false` when `Filament::getTenant() === null`.

- [ ] **Step 1: Rewrite the service tests for the new signatures**

Edit `tests/Feature/Services/AuditEntitlementServiceTest.php` with these mechanical rules, then the additions below:

1. Every `$this->service->freeRunsLimit('x@example.com')` / `freeRunsUsed(...)` / `hasFreeRun(...)` **with a string argument** → `freeRunsLimitForEmail(...)` / `freeRunsUsedForEmail(...)` / `hasFreeRunForEmail(...)`.
2. Every call passing `$user, $tenant` → pass `$tenant` only; every call passing `$user, null` → create `$tenant = $this->createTenant(); $tenant->users()->attach($user);` and pass `$tenant`.
3. Every `AuditRequest::factory()->…create(['user_id' => $user->id, …])` in a metering test → add `'tenant_id' => $tenant->id`.
4. `test_registered_user_bonus_extends_limit`: create a tenant for the user, write the bonus as `TenantParameter::create(['tenant_id' => $tenant->id, 'name' => AuditEntitlementService::BONUS_PARAM, 'value' => '2'])`, and assert with `freeRunsLimit($tenant)` / `hasFreeRun($tenant)`; the free-run rows get `'tenant_id' => $tenant->id`.
5. `test_free_runs_alone_grant_audit_access`: same tenant + `TenantParameter` setup; assert `hasAuditAccess($tenant)`.
6. `test_a_buyable_tier_alone_grants_audit_access_at_the_production_default` and `test_no_audit_access_without_free_runs_subscription_requests_or_a_buyable_tier`: use a bare tenant; `hasAuditAccess($tenant)`.
7. `test_a_null_tenant_has_no_allowance` stays (allowance keeps `?Tenant`).
8. `grantPurchasedCredit($user, …)` / `purchasedCreditBalance($user, …)` → `$tenant`.
9. `subscribedTenant()` helper is unchanged (still returns `[$user, $tenant]`).

Then add these tests to the class:

```php
    public function test_two_members_of_one_workspace_share_the_quota(): void
    {
        [$alice, $tenant] = $this->subscribedTenant(['audit_deep_ai_credits' => 2]);
        $bob = $this->createUser();
        $tenant->users()->attach($bob);

        AuditRequest::factory()->create([
            'user_id' => $alice->id,
            'tenant_id' => $tenant->id,
            'tier' => AuditTier::DEEP_AI->value,
            'funding' => AuditFunding::ALLOWANCE->value,
        ]);

        // Bob sees Alice's run against the shared allowance.
        $this->assertSame(1, $this->service->quotaFor($tenant, AuditTier::DEEP_AI)->remaining());

        $this->service->grantPurchasedCredit($tenant, AuditTier::EXPERT);
        $this->assertSame(1, $this->service->purchasedCreditBalance($tenant, AuditTier::EXPERT));
    }

    public function test_one_user_in_two_workspaces_is_metered_per_workspace(): void
    {
        [$user, $first] = $this->subscribedTenant(['audit_deep_ai_credits' => 2]);
        $second = $this->createTenant();
        $second->users()->attach($user);

        AuditRequest::factory()->count(2)->create([
            'user_id' => $user->id,
            'tenant_id' => $first->id,
            'tier' => AuditTier::DEEP_AI->value,
            'funding' => AuditFunding::ALLOWANCE->value,
        ]);

        $this->assertSame(0, $this->service->remainingRuns($first, AuditTier::DEEP_AI));
        $this->assertSame(0, $this->service->runsUsedThisMonth($second, AuditTier::DEEP_AI));
    }

    public function test_workspace_free_runs_count_claimed_rows_and_ignore_other_workspaces(): void
    {
        config(['audit.free_reports_limit' => 2]);
        $tenant = $this->createTenant();
        $other = $this->createTenant();
        AuditRequest::factory()->freeRun()->create(['tenant_id' => $tenant->id]);
        AuditRequest::factory()->freeRun()->create(['tenant_id' => $other->id]);
        AuditRequest::factory()->freeRun()->create(['tenant_id' => null]);

        $this->assertSame(1, $this->service->freeRunsUsed($tenant));
        $this->assertTrue($this->service->hasFreeRun($tenant));
    }

    public function test_email_free_runs_never_read_a_workspace_bonus(): void
    {
        config(['audit.free_reports_limit' => 1]);
        $user = $this->createUser(null, [], ['email' => 'funnel@example.com']);
        $tenant = $this->createTenant();
        $tenant->users()->attach($user);
        TenantParameter::create(['tenant_id' => $tenant->id, 'name' => AuditEntitlementService::BONUS_PARAM, 'value' => '5']);

        $this->assertSame(1, $this->service->freeRunsLimitForEmail('funnel@example.com'));
        $this->assertSame(6, $this->service->freeRunsLimit($tenant));
    }
```

Add `use App\Models\TenantParameter;` and drop the now-unused `use App\Models\UserParameter;`.

`tests/Feature/Services/AuditSubscriptionEntitlementTest.php` and `AuditSnapshotEntitlementTest.php`: apply rules 2, 3 and 8 (their `createTenantFor`/`subscribedTenant` helpers already give a tenant; drop the `$user` argument from `quotaFor`/`remainingRuns`/`runsUsedThisMonth` and add `tenant_id` to the factories).

`tests/Feature/Models/AuditRequestForUserScopeTest.php` — replace `test_has_audit_access_rules` with:

```php
    public function test_has_audit_access_rules(): void
    {
        config(['audit.free_reports_limit' => 3]);
        $entitlements = app(AuditEntitlementService::class);

        // Free-run quota alone → access. This is what lets a directly
        // registered user reach the dashboard audit UI at all.
        $bare = Tenant::factory()->create();
        $this->assertTrue($entitlements->hasAuditAccess($bare));

        // Has an audit → access regardless of quota
        $withAudit = Tenant::factory()->create();
        AuditRequest::factory()->create(['tenant_id' => $withAudit->id]);
        $this->assertTrue($entitlements->hasAuditAccess($withAudit));

        // With the free quota removed, the remaining arms govern on their own.
        config(['audit.free_reports_limit' => 0]);

        // No audits, no free runs, no allowance → still access, because every
        // tier is priced and a workspace that can buy a run can reach the UI.
        $this->assertTrue($entitlements->hasAuditAccess($bare));

        // Empty the catalog and there is genuinely nothing left to grant it.
        config(['pricing.tiers' => []]);
        $this->assertFalse($entitlements->hasAuditAccess($bare));

        // An existing audit still grants access without any quota
        $this->assertTrue($entitlements->hasAuditAccess($withAudit));
    }
```

(Leave `test_for_user_matches_user_id_and_email_but_not_others` for now — it is deleted with the scope in Task 13.)

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter='AuditEntitlementServiceTest|AuditSubscriptionEntitlementTest|AuditSnapshotEntitlementTest|AuditRequestForUserScopeTest'`
Expected: FAIL — `TypeError` (Tenant passed where User expected) / undefined `freeRunsLimitForEmail`.

- [ ] **Step 3: Rewrite the service**

Replace `app/Services/AuditReport/AuditEntitlementService.php` from the `use` block through the end of `consumeFreeRun()` and from `runsUsedThisMonth()` through the end of the class. Keep `allowance()`, `planMetadata()`, `activeSubscriptionsFor()`, `QUOTA_KEYS`, `$activeSubscriptions` and `tierPriceCents()` exactly as they are. The resulting file:

```php
<?php

namespace App\Services\AuditReport;

use App\Constants\AuditFunding;
use App\Constants\AuditTier;
use App\Models\AuditRequest;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantParameter;
use App\Services\SubscriptionService;
use Illuminate\Support\Collection;

/**
 * Who may run an audit, and out of which pool.
 *
 * Every quota is a *workspace* quota: plan allowance (metered monthly),
 * purchased one-time credits (never expire) and the lifetime free-run
 * quota all live on the Tenant, so every member draws from the same pool
 * and sees the same remaining counts. The only email-keyed arm left is
 * the anonymous landing-page funnel (`*ForEmail`), where no workspace
 * exists yet; those rows are claimed into the visitor's first workspace by
 * ClaimAuditRequestsForTenant and count there from then on.
 */
class AuditEntitlementService
{
    public const BONUS_PARAM = 'audit_bonus_free_runs';

    /**
     * Per-request memo of active subscriptions, keyed by tenant id.
     *
     * hasAuditAccess() and quotas() each call allowance()/planMetadata() once
     * per metered tier -- without this, a single Filament render fans out
     * into a handful of duplicate, uncached, non-eager-loaded subscription
     * queries against the same tenant.
     *
     * @var array<int|string, Collection<int, Subscription>>
     */
    private array $activeSubscriptions = [];

    public function __construct(
        private SubscriptionService $subscriptionService,
    ) {}

    // -- Anonymous funnel (no workspace yet) --------------------------------

    /** The configured quota only: a bonus belongs to a workspace, not an inbox. */
    public function freeRunsLimitForEmail(string $email): int
    {
        return (int) config('audit.free_reports_limit');
    }

    public function freeRunsUsedForEmail(string $email): int
    {
        return AuditRequest::query()
            ->where('email', $email)
            ->where('free_run', true)
            ->count();
    }

    public function hasFreeRunForEmail(string $email): bool
    {
        return $this->freeRunsUsedForEmail($email) < $this->freeRunsLimitForEmail($email);
    }

    // -- Workspace ---------------------------------------------------------

    public function freeRunsLimit(Tenant $tenant): int
    {
        return (int) config('audit.free_reports_limit') + $this->tenantParameter($tenant, self::BONUS_PARAM);
    }

    public function freeRunsUsed(Tenant $tenant): int
    {
        return AuditRequest::query()
            ->forTenant($tenant)
            ->where('free_run', true)
            ->count();
    }

    public function hasFreeRun(Tenant $tenant): bool
    {
        return $this->freeRunsUsed($tenant) < $this->freeRunsLimit($tenant);
    }

    /** Spends a free run. Sets both markers so metering and the lifetime
     *  count can never disagree about how a run was funded. */
    public function consumeFreeRun(AuditRequest $auditRequest): void
    {
        $auditRequest->update([
            'free_run' => true,
            'funding' => AuditFunding::FREE->value,
        ]);
    }

    /**
     * Plan-metadata key per metered tier -- one key per tier, no aliases.
     * DIAGNOSTIC is listed like any other: a tenant with no plan still falls
     * back to the lifetime free quota in quotaFor(), so being metered here
     * costs a plan-less user nothing.
     */
    private const QUOTA_KEYS = [
        AuditTier::DIAGNOSTIC->value => 'audit_diagnostic_credits',
        AuditTier::DEEP_AI->value => 'audit_deep_ai_credits',
        AuditTier::EXPERT->value => 'audit_expert_credits',
    ];

    public function allowance(?Tenant $tenant, AuditTier $tier): int
    {
        // … unchanged …
    }

    private function planMetadata(Tenant $tenant, string $key): int
    {
        // … unchanged …
    }

    /**
     * @return Collection<int, Subscription>
     */
    private function activeSubscriptionsFor(Tenant $tenant): Collection
    {
        return $this->activeSubscriptions[$tenant->id] ??= $this->subscriptionService->findActiveTenantSubscriptions($tenant);
    }

    /**
     * Runs this calendar month that came out of the plan.
     *
     * Keyed on `funding`, not `source`: a checkout intent awaiting payment and
     * a purchased run are both dashboard-sourced but neither spends quota.
     */
    public function runsUsedThisMonth(Tenant $tenant, AuditTier $tier): int
    {
        return AuditRequest::query()
            ->forTenant($tenant)
            ->where('funding', AuditFunding::ALLOWANCE->value)
            ->where('tier', $tier->value)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    public function remainingRuns(Tenant $tenant, AuditTier $tier): int
    {
        return $this->quotaFor($tenant, $tier)->remaining();
    }

    public function quotaFor(Tenant $tenant, AuditTier $tier): TierQuota
    {
        // Diagnostic defaults to the workspace's lifetime free-run count, but
        // a plan that grants a monthly Diagnostic allowance (every paid plan
        // does) uses that instead -- same metered semantics every other tier
        // already has, so nothing downstream needs to know Diagnostic is
        // special-cased at all once this is decided.
        $subscriptionAllowance = $this->allowance($tenant, $tier);
        $isLifetime = $tier === AuditTier::DIAGNOSTIC && $subscriptionAllowance === 0;
        $baseLimit = $isLifetime ? $this->freeRunsLimit($tenant) : $subscriptionAllowance;

        return new TierQuota(
            tier: $tier,
            label: $tier->label(),
            // A purchased credit never expires and is never metered
            // monthly, so it always widens the limit rather than resetting
            // with the calendar -- see consume()/spendPurchasedCredit() for
            // how it's actually drawn down.
            limit: $baseLimit + $this->purchasedCreditBalance($tenant, $tier),
            used: $isLifetime ? $this->freeRunsUsed($tenant) : $this->runsUsedThisMonth($tenant, $tier),
            isLifetime: $isLifetime,
            priceCents: $this->tierPriceCents($tier),
        );
    }

    private function purchasedCreditParam(AuditTier $tier): string
    {
        return 'audit_purchased_credits_'.$tier->value;
    }

    public function purchasedCreditBalance(Tenant $tenant, AuditTier $tier): int
    {
        return $this->tenantParameter($tenant, $this->purchasedCreditParam($tier));
    }

    /**
     * Grants a one-time, never-expiring credit for the tier -- what a
     * one-time tier product purchase resolves to when there's no dashboard
     * intent or prior diagnostic to run it against immediately (see
     * HandleAuditTierOrder). Spent later via consume(), whenever any member
     * submits a run for any repo -- not tied to the purchase.
     */
    public function grantPurchasedCredit(Tenant $tenant, AuditTier $tier, int $quantity = 1): void
    {
        $this->adjustTenantParameter($tenant, $this->purchasedCreditParam($tier), $quantity);
    }

    public function spendPurchasedCredit(Tenant $tenant, AuditTier $tier): void
    {
        $this->adjustTenantParameter($tenant, $this->purchasedCreditParam($tier), -1);
    }

    /**
     * Decides which pool a new run draws from, spending it immediately if
     * it's a purchased credit -- called exactly once, at the moment an
     * AuditRequest is actually created (never merely to check availability;
     * use quotaFor()/TierQuota::hasRuns() for that). Plan allowance is
     * always drawn first: it resets every month regardless of use, while a
     * purchased credit never expires, so spending the credit before the
     * allowance would waste it for nothing.
     */
    public function consume(Tenant $tenant, AuditTier $tier, TierQuota $quota): AuditFunding
    {
        if ($quota->isLifetime && $this->hasFreeRun($tenant)) {
            return AuditFunding::FREE;
        }

        if (! $quota->isLifetime && $this->runsUsedThisMonth($tenant, $tier) < $this->allowance($tenant, $tier)) {
            return AuditFunding::ALLOWANCE;
        }

        $this->spendPurchasedCredit($tenant, $tier);

        return AuditFunding::PURCHASE;
    }

    /** @return list<TierQuota> */
    public function quotas(Tenant $tenant): array
    {
        return array_map(
            fn (AuditTier $tier): TierQuota => $this->quotaFor($tenant, $tier),
            AuditTier::cases(),
        );
    }

    private function tierPriceCents(AuditTier $tier): ?int
    {
        return $tier->priceCents();
    }

    public function hasAuditAccess(Tenant $tenant): bool
    {
        if (AuditRequest::forTenant($tenant)->exists()) {
            return true;
        }

        // A fresh workspace has neither a prior request nor a subscription,
        // but still holds the free-run quota. Omitting this deadlocks it:
        // the dashboard UI that creates the first request stays hidden
        // precisely because there is no request yet.
        if ($this->hasFreeRun($tenant)) {
            return true;
        }

        // Any metered tier with a nonzero allowance grants access -- a tenant
        // holding only Expert credits must not be locked out of the nav.
        foreach (array_keys(self::QUOTA_KEYS) as $tierValue) {
            if ($this->allowance($tenant, AuditTier::from($tierValue)) > 0) {
                return true;
            }
        }

        // Finally, a tier the workspace can simply buy is itself access. With
        // the free quota at its production default of zero, this is the arm
        // that keeps a fresh direct signup out of a deadlock: no request, no
        // free run and no subscription used to hide the entire dashboard
        // audit UI, including the only in-app route to a checkout. A plain
        // config lookup (not quotaFor()) -- purchasable() is
        // priceCents !== null, and quotaFor() would re-run hasFreeRun()'s
        // queries for no reason.
        foreach (AuditTier::cases() as $tier) {
            if ($tier->priceCents() !== null) {
                return true;
            }
        }

        return false;
    }

    private function tenantParameter(Tenant $tenant, string $name): int
    {
        return (int) TenantParameter::query()
            ->where('tenant_id', $tenant->id)
            ->where('name', $name)
            ->value('value');
    }

    /** Never dips below zero: a spend on an empty balance is a no-op. */
    private function adjustTenantParameter(Tenant $tenant, string $name, int $delta): void
    {
        $param = TenantParameter::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => $name],
            ['value' => '0'],
        );

        $param->update(['value' => (string) max(0, ((int) $param->value) + $delta)]);
    }
}
```

(Where the listing says `// … unchanged …`, keep the existing method body verbatim.) Remove the `use App\Models\User;` and `use App\Models\UserParameter;` imports.

- [ ] **Step 4: Update every caller's signature (mechanical)**

`app/Services/AuditRequestService.php:107`: `hasFreeRun($auditRequest->email)` → `hasFreeRunForEmail($auditRequest->email)`.

`app/Filament/Dashboard/Pages/AuditReports.php`:
- `defaultTier()`: `remainingRuns($user, $tenant, $tier)` → `remainingRuns($tenant, $tier)`; delete the `$user = auth()->user();` line if it becomes unused.
- `shouldRegisterNavigation()`: replace `hasAuditAccess($user, Filament::getTenant())` with:
  ```php
        $tenant = Filament::getTenant();

        return $tenant !== null && app(AuditEntitlementService::class)->hasAuditAccess($tenant);
  ```
- `launchAudit()`: `quotaFor($user, $tenant, $selected)` → `quotaFor($tenant, $selected)`; `consume($user, $tenant, $selected, $quota)` → `consume($tenant, $selected, $quota)`. (`$user` stays — it fills `name`/`email`/`user_id`.)
- `setSchedule()`: `quotaFor($user, $tenant, $selected)` → `quotaFor($tenant, $selected)`.
- `getViewData()`: `quotas($user, $tenant)` → `quotas($tenant)`.

`ListAuditRequests.php:24`: `quotas(auth()->user(), Filament::getTenant())` → `quotas(Filament::getTenant())`.

`AuditRequestResource.php` (dashboard) `shouldRegisterNavigation()`:

```php
    public static function shouldRegisterNavigation(): bool
    {
        $tenant = Filament::getTenant();

        return auth()->check() && $tenant !== null && app(AuditEntitlementService::class)->hasAuditAccess($tenant);
    }
```

`RecentAuditsWidget::canView()`, `LatestHealthWidget::canView()`, `PlanUsageWidget::canView()` — same body as above (each currently reads `$user = auth()->user(); if (! $user) return false; return …->hasAuditAccess($user, Filament::getTenant());`). `AuditStatsWidget::canView()` already returns `false` unconditionally — leave it; only its `forUser` query changes (Task 9).

`PlanUsageWidget::getViewData()`: `quotas($user, $tenant)` → `quotas($tenant)`; drop the `$user` line if unused.

`Dashboard.php:34-37` — the header action's `->visible(function (): bool { … })` becomes:

```php
                ->visible(function (): bool {
                    $tenant = Filament::getTenant();

                    return auth()->check()
                        && $tenant !== null
                        && app(AuditEntitlementService::class)->hasAuditAccess($tenant);
                }),
```

`RunScheduledAudits.php:29`: `quotaFor($schedule->user, $schedule->tenant, $tier)` → `quotaFor($schedule->tenant, $tier)`.

`HandleAuditTierOrder::grantPurchasedCredit()`:

```php
    private function grantPurchasedCredit(Order $order, string $tierValue): void
    {
        $tenant = $order->tenant;
        $tier = AuditTier::tryFrom($tierValue);

        if ($tenant === null || $tier === null) {
            return;
        }

        $this->entitlementService->grantPurchasedCredit($tenant, $tier);
    }
```

- [ ] **Step 5: Run the service tests to verify they pass**

Run: `php artisan test --compact --filter='AuditEntitlementServiceTest|AuditSubscriptionEntitlementTest|AuditSnapshotEntitlementTest|AuditRequestForUserScopeTest|AuditRequestServiceTest'`
Expected: PASS.

Then run the caller tests to see what this task broke (expected — fixed in Tasks 9–10, do **not** fix here beyond signature errors):

Run: `php artisan test --compact --filter='AuditReports|PlanUsageWidget|RunScheduledAudits|HandleAuditTierOrder|AuditDemoSeeder|SubscriptionServiceTest'`
Expected: failures are only of the kinds "no audits listed" (still `user_id`-keyed data), "credit granted to a different tenant than the test looked at", or `tenant_id` missing on a created request. A `TypeError` or `BadMethodCallException` means a caller was missed — grep `grep -rn "hasFreeRun(\|freeRunsLimit(\|freeRunsUsed(\|quotaFor(\|quotas(\|consume(\|remainingRuns(\|runsUsedThisMonth(\|purchasedCreditBalance(\|grantPurchasedCredit(\|hasAuditAccess(" app` and fix it.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint app/Services/AuditReport/AuditEntitlementService.php app/Services/AuditRequestService.php app/Filament/Dashboard app/Console/Commands/RunScheduledAudits.php app/Listeners/Order/HandleAuditTierOrder.php tests/Feature/Services tests/Feature/Models/AuditRequestForUserScopeTest.php
git add app/Services/AuditReport/AuditEntitlementService.php app/Services/AuditRequestService.php app/Filament/Dashboard/Pages/AuditReports.php app/Filament/Dashboard/Pages/Dashboard.php app/Filament/Dashboard/Resources/AuditRequests/Pages/ListAuditRequests.php app/Filament/Dashboard/Resources/AuditRequests/AuditRequestResource.php app/Filament/Dashboard/Widgets/RecentAuditsWidget.php app/Filament/Dashboard/Widgets/LatestHealthWidget.php app/Filament/Dashboard/Widgets/PlanUsageWidget.php app/Filament/Dashboard/Widgets/AuditStatsWidget.php app/Console/Commands/RunScheduledAudits.php app/Listeners/Order/HandleAuditTierOrder.php tests/Feature/Services/AuditEntitlementServiceTest.php tests/Feature/Services/AuditSubscriptionEntitlementTest.php tests/Feature/Services/AuditSnapshotEntitlementTest.php tests/Feature/Models/AuditRequestForUserScopeTest.php
git commit -m "refactor(audit): entitlement service meters the workspace, not the user"
```

---

### Task 9: Dashboard creates and lists audits by workspace

**Files:**
- Modify: `app/Filament/Dashboard/Pages/AuditReports.php` (`launchAudit`, `purchase`, `setSchedule*`, `getViewData`)
- Modify: `app/Filament/Dashboard/Resources/AuditRequests/AuditRequestResource.php` (`getEloquentQuery`, table columns)
- Modify: `app/Filament/Dashboard/Widgets/RecentAuditsWidget.php`, `LatestHealthWidget.php`, `AuditStatsWidget.php`
- Modify: `app/Listeners/Order/HandleAuditTierOrder.php` (`INTENT_PARAM` lookup → tenant)
- Modify: `tests/Feature/Filament/Dashboard/AuditReportsPageTest.php`, `AuditReportsPurchaseTest.php`, `AuditReportsTierSelectionTest.php`, `AuditReportsRenderTest.php`, `PlanUsageWidgetTest.php`, and any dashboard widget/resource test that seeds `AuditRequest` with `user_id` (find with `grep -rln "AuditRequest::factory\|AuditRequest::create" tests/Feature/Filament/Dashboard`)
- Create: `tests/Feature/Filament/Dashboard/WorkspaceSharedAuditHistoryTest.php`

**Interfaces:**
- Consumes: Task 8 signatures; `AuditRequest::forTenant`; `TenantParameter`.
- Produces: every dashboard-created `AuditRequest` carries `tenant_id`; the checkout intent lives in `TenantParameter` under `HandleAuditTierOrder::INTENT_PARAM`.

- [ ] **Step 1: Write the failing shared-history test**

Create `tests/Feature/Filament/Dashboard/WorkspaceSharedAuditHistoryTest.php`:

```php
<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\AuditRequestStatus;
use App\Filament\Dashboard\Pages\AuditReports;
use App\Filament\Dashboard\Resources\AuditRequests\Pages\ListAuditRequests;
use App\Jobs\GenerateAuditReport;
use App\Models\AuditRequest;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class WorkspaceSharedAuditHistoryTest extends FeatureTest
{
    private Tenant $tenant;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        config(['audit.free_reports_limit' => 3]);
        $this->alice = $this->createUser();
        $this->bob = $this->createUser();
        $this->tenant = Tenant::factory()->create(['created_by' => $this->alice->id]);
        $this->tenant->users()->attach([$this->alice->id, $this->bob->id]);
    }

    public function test_a_run_launched_by_one_member_is_owned_by_the_workspace(): void
    {
        Queue::fake();
        $this->actingAs($this->alice);
        Filament::setTenant($this->tenant);

        Livewire::actingAs($this->alice)
            ->test(AuditReports::class)
            ->set('repoUrl', 'https://github.com/example/shared')
            ->call('launchAudit');

        $request = AuditRequest::where('repo_url', 'https://github.com/example/shared')->firstOrFail();
        $this->assertSame($this->tenant->id, $request->tenant_id);
        $this->assertSame($this->alice->id, $request->user_id);
        $this->assertSame(AuditRequestStatus::QUEUED->value, $request->status);
        Queue::assertPushed(GenerateAuditReport::class);
    }

    public function test_a_teammate_sees_the_run_in_audit_history(): void
    {
        $request = AuditRequest::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->alice->id,
            'repo_url' => 'https://github.com/example/teammate-visible',
        ]);
        AuditRequest::factory()->create([
            'tenant_id' => Tenant::factory()->create()->id,
            'repo_url' => 'https://github.com/example/foreign',
        ]);

        $this->actingAs($this->bob);
        Filament::setTenant($this->tenant);

        Livewire::actingAs($this->bob)
            ->test(ListAuditRequests::class)
            ->assertCanSeeTableRecords([$request])
            ->assertSee('teammate-visible')
            ->assertDontSee('foreign')
            ->assertSee($this->alice->name);
    }

    public function test_a_member_of_another_workspace_sees_nothing(): void
    {
        AuditRequest::factory()->create(['tenant_id' => $this->tenant->id, 'repo_url' => 'https://github.com/example/private']);
        $outsider = $this->createUser();
        $elsewhere = Tenant::factory()->create(['created_by' => $outsider->id]);
        $elsewhere->users()->attach($outsider);

        $this->actingAs($outsider);
        Filament::setTenant($elsewhere);

        Livewire::actingAs($outsider)
            ->test(ListAuditRequests::class)
            ->assertDontSee('example/private');
    }

    public function test_the_run_page_lists_a_teammates_reports_and_schedules(): void
    {
        $request = AuditRequest::factory()->create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->alice->id,
            'repo_url' => 'https://github.com/example/run-page',
        ]);
        \App\Models\AuditReport::factory()->create(['audit_request_id' => $request->id, 'user_id' => $this->alice->id]);
        \App\Models\AuditSchedule::create([
            'user_id' => $this->alice->id,
            'tenant_id' => $this->tenant->id,
            'repo_url' => 'https://github.com/example/run-page',
            'frequency' => 'weekly',
            'tier' => \App\Constants\AuditTier::DEEP_AI->value,
            'day_of_week' => 1,
        ]);

        $this->actingAs($this->bob);
        Filament::setTenant($this->tenant);

        Livewire::actingAs($this->bob)
            ->test(AuditReports::class)
            ->assertSee('example/run-page');
    }
}
```

Check `AuditSchedule::$fillable` (`sed -n 14,20p app/Models/AuditSchedule.php`) and add/remove keys in the `create([...])` call so every key is fillable.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=WorkspaceSharedAuditHistoryTest`
Expected: FAIL — `tenant_id` null on launch; teammate list empty.

- [ ] **Step 3: Stamp `tenant_id` on every dashboard-created request and move the intent to the tenant**

`app/Filament/Dashboard/Pages/AuditReports.php`:

In `launchAudit()`'s `AuditRequest::create([...])` add `'tenant_id' => $tenant->id,` after `'user_id' => $user->id,`.

In `purchase()`:
- Add `$tenant = Filament::getTenant();` after `$user = auth()->user();`.
- Add `'tenant_id' => $tenant->id,` to the `AuditRequest::create([...])`.
- Replace the `UserParameter::updateOrCreate(...)` with:

```php
        TenantParameter::updateOrCreate(
            ['tenant_id' => $tenant->id, 'name' => HandleAuditTierOrder::INTENT_PARAM],
            ['value' => $auditRequest->uuid],
        );
```

Swap `use App\Models\UserParameter;` for `use App\Models\TenantParameter;` (keep `UserParameter` only if something else in the file still uses it — `grep -n UserParameter` to check).

In `setSchedule()`, `setScheduleDay()`, `setScheduleMonthDay()`, `setScheduleBranch()`: every `->where('user_id', $user->id)` on `AuditSchedule` → `->where('tenant_id', $tenant->id)` (add `$tenant = Filament::getTenant();` where the method lacks it, and `if ($tenant === null) { return; }`). In `setSchedule()`'s `updateOrCreate` change the match array to `['tenant_id' => $tenant->id, 'repo_url' => $repoUrl]` and move `'user_id' => $user->id` into the values array (created-by).

In `getViewData()`:
- Replace `$reports = $user->auditReports()->with('auditRequest')->latest()->get();` with:

```php
        $reports = AuditReport::query()
            ->whereHas('auditRequest', fn (Builder $query) => $query->forTenant($tenant))
            ->with('auditRequest')
            ->latest()
            ->get();
```

  (add `use Illuminate\Database\Eloquent\Builder;` and `use App\Models\AuditReport;` if missing).
- Replace `AuditSchedule::query()->where('user_id', $user->id)` with `AuditSchedule::query()->where('tenant_id', $tenant->id)`.

`app/Listeners/Order/HandleAuditTierOrder.php` `intentRequestFor()`:

```php
        $intent = TenantParameter::query()
            ->where('tenant_id', $order->tenant_id)
            ->where('name', self::INTENT_PARAM)
            ->first();
```

and the fallback `->where('user_id', $order->user_id)` → `->where('tenant_id', $order->tenant_id)`. Swap the `UserParameter` import for `TenantParameter`.

- [ ] **Step 4: List by workspace**

`AuditRequestResource::getEloquentQuery()` (dashboard):

```php
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            // @phpstan-ignore-next-line method.notFound (forTenant is AuditRequest's own scope; Larastan can't see it through the parent's generic Builder<Model> return type)
            ->forTenant(Filament::getTenant())
            ->with(['report', 'user']);
    }
```

Add a column after `repo_url`:

```php
                TextColumn::make('user.name')
                    ->label(__('Requested by'))
                    ->default(fn (AuditRequest $record): string => $record->name)
                    ->toggleable(),
```

`RecentAuditsWidget`: both `AuditRequest::forUser(auth()->user())` / `forUser($user)` → `AuditRequest::forTenant(Filament::getTenant())`. `LatestHealthWidget`: both `forUser($user)` → `forTenant(Filament::getTenant())`. `AuditStatsWidget`: `forUser($user)` → `forTenant(Filament::getTenant())`. Remove `$user` locals that become unused.

- [ ] **Step 5: Update the existing dashboard tests**

Rules for `tests/Feature/Filament/Dashboard/*`:
- Every `AuditRequest::factory()->…create([... 'user_id' => $user->id ...])` that the test expects to *see* in the dashboard → add `'tenant_id' => $tenant->id` (the tenant the test already sets via `Filament::setTenant($tenant)`).
- `AuditReportsPurchaseTest`: intent assertions move from `UserParameter` (`user_id`) to `TenantParameter` (`tenant_id`).
- `PlanUsageWidgetTest`: quota calls per Task 8 rules; seeded runs get `tenant_id`.
- Assertions that a launched request has `user_id === $user->id` stay; add `tenant_id === $tenant->id` alongside where convenient.

- [ ] **Step 6: Run the dashboard tests to verify they pass**

Run: `php artisan test --compact --filter='WorkspaceSharedAuditHistoryTest|AuditReports|PlanUsageWidget|RecentAudits|LatestHealth|AuditStats|Dashboard'`
Expected: PASS.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint app/Filament/Dashboard app/Listeners/Order/HandleAuditTierOrder.php tests/Feature/Filament/Dashboard
git add app/Filament/Dashboard app/Listeners/Order/HandleAuditTierOrder.php tests/Feature/Filament/Dashboard
git commit -m "feat(audit): dashboard runs, history and schedules belong to the workspace"
```

---

### Task 10: Orders and scheduled runs stamp the workspace

**Files:**
- Modify: `app/Listeners/Order/HandleAuditTierOrder.php` (`handle` create block, `sourceRequestFor`)
- Modify: `app/Console/Commands/RunScheduledAudits.php:51-64`
- Modify: `tests/Feature/Listeners/HandleAuditTierOrderTest.php`
- Modify: `tests/Feature/Console/RunScheduledAuditsTest.php`

**Interfaces:**
- Consumes: `Order::tenant()` / `$order->tenant_id`; `AuditSchedule::$tenant_id`.

- [ ] **Step 1: Update the listener tests**

In `tests/Feature/Listeners/HandleAuditTierOrderTest.php`:
- `orderFor()` currently creates the order with `'tenant_id' => Tenant::factory()->create()->id`. Change it to accept the tenant: `private function orderFor(User $user, string $slug, ?Tenant $tenant = null): Order` and use `'tenant_id' => ($tenant ?? $this->tenantFor($user))->id`, adding:

```php
    private function tenantFor(User $user): Tenant
    {
        $tenant = Tenant::factory()->create(['created_by' => $user->id]);
        $tenant->users()->attach($user);

        return $tenant;
    }
```

  Do the same for `completeOrderFor()` if it builds its own order.
- Every seeded source diagnostic (`AuditRequest::factory()->…create([... 'user_id' => $user->id])`) that the listener must find → also `'tenant_id' => $tenant->id` where `$tenant` is the one passed to `orderFor`.
- `test_an_order_with_no_intent_or_prior_diagnostic_grants_a_purchased_credit_instead_of_failing_silently`: capture `$tenant = $this->tenantFor($user);`, pass it to `completeOrderFor`, and assert `purchasedCreditBalance($tenant, AuditTier::DEEP_AI)`.
- Intent tests: `UserParameter::create(['user_id' => …, 'name' => INTENT_PARAM, …])` → `TenantParameter::create(['tenant_id' => $tenant->id, …])`, and the "intent row deleted" assertion → `TenantParameter::where('tenant_id', $tenant->id)->where('name', …)->first()`.
- Add:

```php
    public function test_the_purchased_run_is_owned_by_the_orders_workspace(): void
    {
        Queue::fake();
        $user = $this->createUser();
        $tenant = $this->tenantFor($user);
        AuditRequest::factory()->create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'tier' => AuditTier::DIAGNOSTIC->value,
            'repo_url' => 'https://github.com/example/owned',
        ]);

        $this->completeOrderFor($user, 'audit-deep-ai', $tenant);

        $run = AuditRequest::where('tier', AuditTier::DEEP_AI->value)->firstOrFail();
        $this->assertSame($tenant->id, $run->tenant_id);
    }

    public function test_a_teammates_diagnostic_is_a_valid_source_for_the_order(): void
    {
        Queue::fake();
        $alice = $this->createUser();
        $tenant = $this->tenantFor($alice);
        $bob = $this->createUser();
        $tenant->users()->attach($bob);
        AuditRequest::factory()->create([
            'user_id' => $alice->id,
            'tenant_id' => $tenant->id,
            'tier' => AuditTier::DIAGNOSTIC->value,
            'repo_url' => 'https://github.com/example/team',
        ]);

        $this->completeOrderFor($bob, 'audit-deep-ai', $tenant);

        $this->assertSame(1, AuditRequest::where('tier', AuditTier::DEEP_AI->value)->where('repo_url', 'https://github.com/example/team')->count());
    }
```

  (Adjust `completeOrderFor`'s signature to forward the tenant.)

In `tests/Feature/Console/RunScheduledAuditsTest.php`: seeded schedules already have `tenant_id`; add to the test that asserts the created request: `$this->assertSame($schedule->tenant_id, $created->tenant_id);` and give any seeded allowance-funded requests the schedule's `tenant_id`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter='HandleAuditTierOrderTest|RunScheduledAuditsTest'`
Expected: FAIL — `tenant_id` null on the purchased/scheduled run; teammate source not found.

- [ ] **Step 3: Stamp the tenant in the listener and command**

`HandleAuditTierOrder::handle()` — in the `AuditRequest::create([...])` add `'tenant_id' => $order->tenant_id,` after `'user_id' => $source->user_id,`.

`sourceRequestFor()`:

```php
    /**
     * The workspace's most recent diagnostic run -- any member's, since the
     * report belongs to the workspace (AuditRequest::scopeForTenant).
     */
    private function sourceRequestFor(Order $order): ?AuditRequest
    {
        $tenant = $order->tenant;

        if ($tenant === null) {
            return null;
        }

        return AuditRequest::query()
            ->forTenant($tenant)
            ->where('tier', AuditTier::DIAGNOSTIC->value)
            ->latest('id')
            ->first();
    }
```

Remove `use App\Models\User;` if nothing else in the file uses it.

`RunScheduledAudits.php` — in `AuditRequest::create([...])` add `'tenant_id' => $schedule->tenant_id,` after `'user_id' => $schedule->user->id,`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter='HandleAuditTierOrderTest|RunScheduledAuditsTest|HandleAuditUnlockOrder|SendAuditUnlockReminders'`
Expected: PASS.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint app/Listeners/Order/HandleAuditTierOrder.php app/Console/Commands/RunScheduledAudits.php tests/Feature/Listeners/HandleAuditTierOrderTest.php tests/Feature/Console/RunScheduledAuditsTest.php
git add app/Listeners/Order/HandleAuditTierOrder.php app/Console/Commands/RunScheduledAudits.php tests/Feature/Listeners/HandleAuditTierOrderTest.php tests/Feature/Console/RunScheduledAuditsTest.php
git commit -m "feat(audit): purchased and scheduled runs are owned by the workspace"
```

---

### Task 11: Report download authorised by workspace membership

**Files:**
- Modify: `app/Models/AuditReport.php`
- Modify: `app/Http/Controllers/AuditReportController.php:90-105`
- Modify: `tests/Feature/Http/Controllers/AuditReportControllerTest.php:107-135`
- Modify: `tests/Feature/Services/AuditReportUnlockTest.php` (guest unlock → claim assertion)

**Interfaces:**
- Produces: `AuditReport::isViewableBy(User $user): bool`.

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/Http/Controllers/AuditReportControllerTest.php` replace `test_download_requires_ownership` with:

```php
    public function test_download_is_allowed_for_workspace_members_and_denied_to_outsiders(): void
    {
        $this->withExceptionHandling();
        Storage::disk('local')->put('audit-reports/owned.pdf', '%PDF-1.4');
        $owner = $this->createUser();
        $teammate = $this->createUser();
        $stranger = $this->createUser();
        $tenant = Tenant::factory()->create(['created_by' => $owner->id]);
        $tenant->users()->attach([$owner->id, $teammate->id]);
        $report = AuditReport::factory()->create(['user_id' => $owner->id, 'pdf_path' => 'audit-reports/owned.pdf']);
        $report->auditRequest->update(['tenant_id' => $tenant->id]);

        $this->actingAs($stranger)->get(route('reports.download', $report))->assertStatus(403);
        $this->actingAs($teammate)->get(route('reports.download', $report))->assertStatus(200);
        $this->actingAs($owner)->get(route('reports.download', $report))->assertStatus(200);
    }

    public function test_download_of_an_unclaimed_report_falls_back_to_the_personal_owner_rule(): void
    {
        $this->withExceptionHandling();
        Storage::disk('local')->put('audit-reports/unclaimed.pdf', '%PDF-1.4');
        $owner = $this->createUser(null, [], ['email' => 'owner@example.com']);
        $stranger = $this->createUser();
        $report = AuditReport::factory()->create(['user_id' => null, 'pdf_path' => 'audit-reports/unclaimed.pdf']);
        $report->auditRequest->update(['tenant_id' => null, 'user_id' => null, 'email' => 'Owner@Example.com']);

        $this->actingAs($stranger)->get(route('reports.download', $report))->assertStatus(403);
        $this->actingAs($owner)->get(route('reports.download', $report))->assertStatus(200);
    }
```

Add `use App\Models\Tenant;`.

In `tests/Feature/Services/AuditReportUnlockTest.php` add (adapting to that file's existing helpers for a signed unlock URL — read it first):

```php
    public function test_unlocking_as_a_guest_creates_a_workspace_that_claims_the_request(): void
    {
        config(['app.create_tenant_on_user_registration' => true]);
        $report = AuditReport::factory()->locked()->create();
        $report->auditRequest->update(['email' => 'guest@example.com', 'email_verified_at' => now(), 'tenant_id' => null]);

        $this->get(URL::temporarySignedRoute('reports.unlock', now()->addDay(), ['auditReport' => $report->uuid]));

        $user = User::where('email', 'guest@example.com')->firstOrFail();
        $tenant = $user->tenants()->firstOrFail();
        $this->assertSame($tenant->id, $report->auditRequest->fresh()->tenant_id);
    }
```

If the unlock flow in that test file needs a paid order or other setup before a tenant is created, mirror the nearest existing "guest creates account" test's arrangement and keep only the final two assertions as the new behaviour.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter='AuditReportControllerTest|AuditReportUnlockTest'`
Expected: FAIL — teammate gets 403; claim assertion fails only if event discovery missed the listener (Task 7 covered it — if this fails, fix discovery there).

- [ ] **Step 3: Implement `isViewableBy` and use it in `download()`**

`app/Models/AuditReport.php` — add:

```php
    /**
     * Who may open the file behind an authenticated route. A workspace report
     * is any member's; a report whose request was never claimed into a
     * workspace (landing-page funnel, not yet registered) keeps the personal
     * rule. Admins triage everyone's runs from the admin panel.
     */
    public function isViewableBy(User $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $request = $this->auditRequest;

        if ($request->tenant_id !== null) {
            return $user->tenants()->where('tenants.id', $request->tenant_id)->exists();
        }

        return $this->user_id === $user->id
            || strtolower((string) $request->email) === strtolower((string) $user->email);
    }
```

`AuditReportController::download()` — replace the `abort_unless(... isAdmin(), 403)` block with:

```php
        abort_unless($auditReport->isViewableBy(auth()->user()), 403);
```

and trim the docblock comment above it to:

```php
        // Membership of the request's workspace, or the personal owner rule
        // for an unclaimed report; admins pass (AuditReport::isViewableBy).
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --compact --filter='AuditReportControllerTest|AuditReportUnlockTest|AuditReportPageTest'`
Expected: PASS.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint app/Models/AuditReport.php app/Http/Controllers/AuditReportController.php tests/Feature/Http/Controllers/AuditReportControllerTest.php tests/Feature/Services/AuditReportUnlockTest.php
git add app/Models/AuditReport.php app/Http/Controllers/AuditReportController.php tests/Feature/Http/Controllers/AuditReportControllerTest.php tests/Feature/Services/AuditReportUnlockTest.php
git commit -m "feat(audit): report download authorised by workspace membership"
```

---

### Task 12: Admin panel shows the workspace

**Files:**
- Modify: `app/Filament/Admin/Resources/AuditRequests/AuditRequestResource.php:193-230`
- Modify: `tests/Feature/Filament/Admin/Resources/AuditRequestResourceTest.php`

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Filament/Admin/Resources/AuditRequestResourceTest.php` (mirror its existing `actingAs(admin)` + `Livewire::test(ListAuditRequests::class)` pattern — read the file for the exact page class import):

```php
    public function test_the_list_shows_and_filters_by_workspace(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Acme Workspace']);
        $mine = AuditRequest::factory()->create(['tenant_id' => $tenant->id, 'repo_url' => 'https://github.com/acme/app']);
        $other = AuditRequest::factory()->create(['tenant_id' => null, 'repo_url' => 'https://github.com/nobody/app']);

        $this->actingAs($this->createAdminUser());

        Livewire::test(ListAuditRequests::class)
            ->assertSee('Acme Workspace')
            ->filterTable('tenant_id', $tenant->id)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$other]);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=test_the_list_shows_and_filters_by_workspace`
Expected: FAIL — no `tenant_id` filter.

- [ ] **Step 3: Add the column and filter**

After `TextColumn::make('email')->searchable(),` add:

```php
                TextColumn::make('tenant.name')
                    ->label(__('Workspace'))
                    ->placeholder(__('Unclaimed'))
                    ->toggleable(),
```

In `->filters([` add before `SelectFilter::make('status')`:

```php
                SelectFilter::make('tenant_id')
                    ->label(__('Workspace'))
                    ->relationship('tenant', 'name')
                    ->searchable()
                    ->preload(),
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact --filter=AuditRequestResourceTest`
Expected: PASS.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint app/Filament/Admin/Resources/AuditRequests/AuditRequestResource.php tests/Feature/Filament/Admin/Resources/AuditRequestResourceTest.php
git add app/Filament/Admin/Resources/AuditRequests/AuditRequestResource.php tests/Feature/Filament/Admin/Resources/AuditRequestResourceTest.php
git commit -m "feat(admin): audit list shows and filters by workspace"
```

---

### Task 13: Remove `scopeForUser`, demo seeder, full gates

**Files:**
- Modify: `app/Models/AuditRequest.php` (delete `scopeForUser`)
- Modify: `database/seeders/Demo/AuditDemoSeeder.php` (seeded requests get `tenant_id`)
- Modify/Rename: `tests/Feature/Models/AuditRequestForUserScopeTest.php` → merge remaining test into `AuditRequestForTenantScopeTest.php`
- Modify: `tests/Feature/Seeders/AuditDemoSeederTest.php`
- Modify: `CLAUDE.md` (root) — audit section

- [ ] **Step 1: Delete the scope and find every remaining caller**

Remove `scopeForUser` (docblock + method) from `app/Models/AuditRequest.php`. Then:

```bash
grep -rn "forUser(" app tests database   # expect no output
grep -rn "UserParameter" app/Services/AuditReport app/Filament/Dashboard/Pages/AuditReports.php app/Listeners/Order/HandleAuditTierOrder.php   # expect no output
```

Fix anything found the same way the earlier tasks did.

- [ ] **Step 2: Seeder and its test**

In `database/seeders/Demo/AuditDemoSeeder.php`, every `AuditRequest::create([...])` gets `'tenant_id' => $tenant->id,` (the seeder already resolves `$tenant = $this->tenantFor($user)` — pass it into the method that creates the requests). In `tests/Feature/Seeders/AuditDemoSeederTest.php`, replace `forUser($user)` lookups with `forTenant($user->tenants()->first())` and assert the seeded rows carry that `tenant_id`.

- [ ] **Step 3: Move the surviving scope test**

Delete `tests/Feature/Models/AuditRequestForUserScopeTest.php` after moving `test_has_audit_access_rules` (as rewritten in Task 8) into `tests/Feature/Models/AuditRequestForTenantScopeTest.php` (add the `AuditEntitlementService` import). The `forUser` matching test is dropped with the scope.

- [ ] **Step 4: Full suite, format, static analysis**

```bash
php artisan test --compact
vendor/bin/pint
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress | tail -5
```

Expected: all tests pass (re-run once on a `QueryException` storm — shared test DB); Pint clean; PHPStan shows only the one pre-existing `AuditGroupDeltaService` error.

- [ ] **Step 5: Update the root `CLAUDE.md` audit section**

In `/var/www/html/flexpick.net/CLAUDE.md`, in "The Audit pipeline" bullet list, replace the sentence starting "Access is gated by `AuditEntitlementService`" so the paragraph reads:

> - Access is gated by `AuditEntitlementService`, and **every quota belongs to the workspace (`Tenant`)**: a free-run quota (`config('audit.free_reports_limit')` + the tenant's `audit_bonus_free_runs` `TenantParameter`), purchased one-time credits (`TenantParameter audit_purchased_credits_{tier}`), and a per-tier subscription allowance read from the plan's product metadata, metered per calendar month on `audit_requests.tenant_id`. One key per tier, no aliases — `audit_diagnostic_credits`, `audit_deep_ai_credits`, `audit_expert_credits`. Diagnostic falls back to the free-run quota only when the plan grants it no allowance. Landing-page requests are tenantless until `ClaimAuditRequestsForTenant` attaches them to the visitor's first workspace; the `*ForEmail` methods serve that funnel. The catalog has three tiers (Diagnostic $49, Deep AI $119, Expert $999); `automated` was retired on 2026-08-24.

Also change `AuditRequest, UUID-keyed, claimable by email before signup via scopeForUser` → `AuditRequest, UUID-keyed, owned by a Tenant via scopeForTenant; claimable by email before signup`.

- [ ] **Step 6: Commit**

```bash
git add app/Models/AuditRequest.php database/seeders/Demo/AuditDemoSeeder.php tests/Feature/Models/AuditRequestForTenantScopeTest.php tests/Feature/Seeders/AuditDemoSeederTest.php ../CLAUDE.md
git rm -q tests/Feature/Models/AuditRequestForUserScopeTest.php
git commit -m "refactor(audit): drop the user-keyed scope; workspace ownership is the only rule"
```

---

### Task 14: Deploy notes and branch wrap-up

**Files:**
- Modify: `docs/superpowers/specs/2026-09-13-workspace-audits-and-referral-pricing-design.md` (status line + deviations)

- [ ] **Step 1: Record the deviations in the spec**

Set `Status: Implemented` and append a section:

```markdown
## E. Implementation notes (2026-09-13)

- `AuditReport::isViewableBy()` guards `download()` only; `show()` remains signed-URL-only (A.7 amended).
- `SendAuditUnlockReminders` creates no requests; untouched (A.6 amended).
- The run page (`AuditReports`) lists reports and schedules by tenant; `audit_schedules.user_id` is kept as created-by.
- Deploy: run `php artisan migrate` (three new migrations, the third backfills data and is idempotent). Then, on the server, `npm run build` is not required — no CSS/JS changed.
```

- [ ] **Step 2: Commit and verify the branch**

```bash
git add docs/superpowers/specs/2026-09-13-workspace-audits-and-referral-pricing-design.md
git commit -m "docs: record implementation deviations for workspace audits"
git log --oneline main..HEAD
```

Expected: the spec commit plus 13 task commits on `growth-retention`. Hand off to `superpowers:finishing-a-development-branch`.
