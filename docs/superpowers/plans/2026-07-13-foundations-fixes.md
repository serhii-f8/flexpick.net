# Foundations Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix Docker storage permissions, auth redirects and landing-page nav state, complete the monetization/demo seeders, and style the Free Audit checkbox.

**Architecture:** Four independent fixes on the existing SaaSykit (Laravel 13 + Filament) backend and static Astro 6 landing page. The Docker fix extends the Sail entrypoint; auth fixes touch `LoginController`, `RouteServiceProvider`, and add one tiny JSON endpoint consumed by a progressive-enhancement script on the landing page; seeders extend existing idempotent patterns; the checkbox is pure CSS.

**Tech Stack:** Laravel 13 / PHP 8.4 (backend, in `backend/`), Astro 6 (frontend, in `frontend/`), Laravel Sail Docker images (`backend/docker/8.4`, `backend/docker/8.5`).

**Spec:** `docs/superpowers/specs/2026-07-13-foundations-fixes-design.md`

## Global Constraints

- Backend commands run from the repo root via Docker: `docker compose exec laravel.test <cmd>` (the container's workdir is the backend app). If the containers are not running, start them with `docker compose up -d`. Direct host execution from `backend/` works too if host PHP 8.4 is available.
- Backend tests: `docker compose exec laravel.test php artisan test --compact --filter=<Name>`. Tests extend `Tests\Feature\FeatureTest` (migrates fresh once per process, seeds `TestingDatabaseSeeder`, calls `withoutExceptionHandling()`).
- Format every changed PHP file before committing: `docker compose exec laravel.test vendor/bin/pint <changed files>`.
- Frontend checks run from `frontend/`: `npm run check` (CI gate) — or inside Docker: `docker compose exec frontend npm run check`.
- Frontend code style: Prettier 120-char width, single quotes; inline styles are the established idiom in `index.astro`/`ContactModal.astro` — follow it.
- Commit after every task with a conventional-commit message.
- Never seed payment-provider IDs for Stripe/Paddle/etc.: SaaSykit creates provider products/prices on the fly at first checkout (`StripeProvider::findOrCreateStripe*`); placeholder IDs would break real test checkouts. This deliberately satisfies the spec's "Stripe identifiers or clearly marked test placeholders" bullet with "none are required" (documented in a seeder comment, Task 5).
- The products/plans schema has no display-order column; ordering on `/pricing` follows creation order and `is_popular` highlights the Growth tier. This is the agreed interpretation of the spec's "display order" bullet.

---

### Task 1: Docker entrypoint self-heal for Laravel writable directories

The Sail entrypoint remaps the `sail` user to `$WWWUSER` at boot but never repairs files created by root or an older UID mapping. One root-owned subdirectory under `storage/framework/cache/data/` permanently breaks cache writes (the current `/admin` 500).

**Files:**
- Modify: `backend/docker/8.4/start-container`
- Modify: `backend/docker/8.5/start-container` (identical file — apply the same change)

**Interfaces:**
- Consumes: `WWWUSER` env var (already set in `backend/.env` and passed by `backend/compose.yml`).
- Produces: nothing consumed by other tasks; `storage/` and `bootstrap/cache/` writable by the PHP process after every container start.

- [ ] **Step 1: Rewrite both start-container scripts**

Replace the full contents of `backend/docker/8.4/start-container` AND `backend/docker/8.5/start-container` (they are identical; keep them identical) with:

```bash
#!/usr/bin/env bash

if [ "$SUPERVISOR_PHP_USER" != "root" ] && [ "$SUPERVISOR_PHP_USER" != "sail" ]; then
    echo "You should set SUPERVISOR_PHP_USER to either 'sail' or 'root'."
    exit 1
fi

if [ ! -z "$WWWUSER" ]; then
    usermod -u $WWWUSER sail
fi

# Self-heal Laravel writable directories: files created by root (or under a
# previous UID mapping) would otherwise permanently break cache, session,
# view, and log writes for the sail user.
for dir in /var/www/html/storage /var/www/html/bootstrap/cache; do
    if [ -d "$dir" ]; then
        find "$dir" ! -user sail -exec chown sail:sail {} +
        chmod -R u+rwX "$dir"
    fi
done

if [ ! -d /.composer ]; then
    mkdir /.composer
fi

chmod -R ugo+rw /.composer

if [ $# -gt 0 ]; then
    if [ "$SUPERVISOR_PHP_USER" = "root" ]; then
        exec "$@"
    else
        exec gosu $WWWUSER "$@"
    fi
else
    exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
fi
```

- [ ] **Step 2: Rebuild the backend image and restart**

Run from the repo root:

```bash
docker compose build laravel.test
docker compose up -d
```

Expected: build succeeds, `laravel.test` container is running (`docker compose ps` shows it `Up`).

- [ ] **Step 3: Simulate root-owned breakage and verify the entrypoint repairs it**

```bash
docker compose exec -u root laravel.test bash -c 'mkdir -p storage/framework/cache/data/zz && touch storage/framework/cache/data/zz/probe && chown -R root:root storage/framework/cache/data/zz'
docker compose restart laravel.test
sleep 5
docker compose exec laravel.test stat -c "%U" storage/framework/cache/data/zz/probe
```

Expected: last command prints `sail`.

Cleanup: `docker compose exec laravel.test rm -rf storage/framework/cache/data/zz`

- [ ] **Step 4: Verify /admin no longer 500s and Laravel can write everywhere**

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8080/admin
docker compose exec laravel.test php artisan config:cache
docker compose exec laravel.test php artisan config:clear
docker compose exec laravel.test bash -c 'php artisan tinker --execute="cache()->put(\"perm-probe\", 1); echo cache()->get(\"perm-probe\");"'
```

Expected: curl prints `302` or `200` (anything but `500`); config cache/clear succeed; tinker prints `1`.

- [ ] **Step 5: Commit**

```bash
git add backend/docker/8.4/start-container backend/docker/8.5/start-container
git commit -m "fix(docker): self-heal Laravel writable dir ownership on container start"
```

---

### Task 2: Auth-status endpoint for the landing page

A session-aware JSON endpoint the static landing site can query cross-origin to learn whether the visitor is logged in. Lives in `routes/web.php` (needs the `web` middleware group for sessions) at the path `/api/auth/status`; Laravel's global `HandleCors` middleware matches paths from `config/cors.php` regardless of route file.

**Files:**
- Create: `backend/app/Http/Controllers/AuthStatusController.php`
- Modify: `backend/routes/web.php` (add route after the existing `/dashboard` route at line ~52)
- Modify: `backend/config/cors.php`
- Test: `backend/tests/Feature/Http/Controllers/AuthStatusControllerTest.php`

**Interfaces:**
- Consumes: `Auth::check()` on the `web` guard; `CORS_ALLOWED_ORIGINS` env (already defaults to `https://flexpick.net,http://localhost:4321`).
- Produces: `GET /api/auth/status` → `200 {"authenticated": true|false}` with `Cache-Control: no-store, private` and CORS credentials headers. Task 4's landing script calls this exact URL and reads the `authenticated` boolean.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Http/Controllers/AuthStatusControllerTest.php`:

```php
<?php

namespace Tests\Feature\Http\Controllers;

use Tests\Feature\FeatureTest;

class AuthStatusControllerTest extends FeatureTest
{
    public function test_guest_is_reported_unauthenticated(): void
    {
        $this->getJson('/api/auth/status')
            ->assertOk()
            ->assertExactJson(['authenticated' => false])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_logged_in_user_is_reported_authenticated(): void
    {
        $user = $this->createUser();

        $this->actingAs($user)
            ->getJson('/api/auth/status')
            ->assertOk()
            ->assertExactJson(['authenticated' => true]);
    }

    public function test_cors_allows_landing_origin_with_credentials(): void
    {
        $this->getJson('/api/auth/status', ['Origin' => 'http://localhost:4321'])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:4321')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --compact --filter=AuthStatusControllerTest`
Expected: FAIL — `Symfony\Component\HttpKernel\Exception\NotFoundHttpException` (route does not exist; `FeatureTest` disables exception handling).

- [ ] **Step 3: Implement controller, route, and CORS config**

Create `backend/app/Http/Controllers/AuthStatusController.php`:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class AuthStatusController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()
            ->json(['authenticated' => Auth::check()])
            ->header('Cache-Control', 'no-store, private');
    }
}
```

In `backend/routes/web.php`, add `use App\Http\Controllers\AuthStatusController;` alongside the existing `use` statements, then add directly after the `/dashboard` route (line ~52):

```php
Route::get('/api/auth/status', AuthStatusController::class)->name('auth.status');
```

In `backend/config/cors.php`, change two lines:

```php
'paths' => ['api/audit-requests', 'api/auth/status'],
```

and

```php
'supports_credentials' => true,
```

(Credentials are safe here because `allowed_origins` is an explicit list, never `*`. The existing `api/audit-requests` POST sends no cookies, so it is unaffected.)

In `backend/.env.example`, directly under the `CORS_ALLOWED_ORIGINS` line (~line 128), add the production session-cookie note:

```dotenv
# Production must set SESSION_DOMAIN=.flexpick.net so the landing site's
# /api/auth/status calls carry the app session cookie cross-subdomain.
# In local dev, cookies on localhost are shared across ports — leave unset.
#SESSION_DOMAIN=.flexpick.net
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --compact --filter=AuthStatusControllerTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint app/Http/Controllers/AuthStatusController.php routes/web.php config/cors.php tests/Feature/Http/Controllers/AuthStatusControllerTest.php
git add backend/app/Http/Controllers/AuthStatusController.php backend/routes/web.php backend/config/cors.php backend/.env.example backend/tests/Feature/Http/Controllers/AuthStatusControllerTest.php
git commit -m "feat(backend): session-aware auth-status endpoint for the landing page"
```

---

### Task 3: Fix login redirect (referer bug) and HOME constant

`LoginController::showLoginForm()` seeds the intended URL from the `Referer` header unconditionally, so logging in from the landing page (external origin) or the backend home page bounces the user back there instead of the dashboard. `RouteServiceProvider::HOME` (`/`) is also used by `VerificationController`, `ResetPasswordController`, and `ConfirmPasswordController` as `$redirectTo`, sending users to the landing redirect after email verification / password reset.

**Files:**
- Modify: `backend/app/Http/Controllers/Auth/LoginController.php:37-46`
- Modify: `backend/app/Providers/RouteServiceProvider.php:20`
- Test: `backend/tests/Feature/Http/Controllers/Auth/LoginControllerTest.php` (append tests)

**Interfaces:**
- Consumes: `RedirectAwareTrait::getRedirectUrl(?User)` (unchanged — already prefers intended URL, then `/admin` for admins, then `route('dashboard')`); `route('dashboard')` → `UserDashboardService::getUserDashboardUrl()`.
- Produces: no new symbols. Behavior contract: external/home/login/register referers never become the intended URL; `RouteServiceProvider::HOME === '/dashboard'`.

- [ ] **Step 1: Write the failing tests**

Append to `backend/tests/Feature/Http/Controllers/Auth/LoginControllerTest.php` (inside the class; the file already imports `User`, `WithFaker`, `FeatureTest`):

```php
    public function test_login_from_external_landing_referer_redirects_to_dashboard(): void
    {
        $email = $this->faker->email;
        $this->createUser(null, [], ['email' => $email, 'password' => bcrypt('password123')]);

        $this->get(route('login'), ['referer' => 'https://flexpick.net/']);

        $this->post(route('login'), ['email' => $email, 'password' => 'password123'])
            ->assertRedirect(route('dashboard'));
    }

    public function test_login_from_home_referer_redirects_to_dashboard(): void
    {
        $email = $this->faker->email;
        $this->createUser(null, [], ['email' => $email, 'password' => bcrypt('password123')]);

        $this->get(route('login'), ['referer' => route('home')]);

        $this->post(route('login'), ['email' => $email, 'password' => 'password123'])
            ->assertRedirect(route('dashboard'));
    }

    public function test_login_from_internal_page_returns_to_that_page(): void
    {
        $email = $this->faker->email;
        $this->createUser(null, [], ['email' => $email, 'password' => bcrypt('password123')]);

        $this->get(route('login'), ['referer' => route('pricing')]);

        $this->post(route('login'), ['email' => $email, 'password' => 'password123'])
            ->assertRedirect(route('pricing'));
    }

    public function test_login_after_protected_page_redirects_back_to_it(): void
    {
        $this->withExceptionHandling(); // auth middleware must redirect, not throw

        $email = $this->faker->email;
        $this->createUser(null, [], ['email' => $email, 'password' => bcrypt('password123')]);

        $this->get('/dashboard?src=email')->assertRedirect(route('login'));

        $this->post(route('login'), ['email' => $email, 'password' => 'password123'])
            ->assertRedirect(url('/dashboard?src=email'));
    }

    public function test_admin_login_redirects_to_admin_panel(): void
    {
        $email = $this->faker->email;
        $this->createUser(null, [], ['email' => $email, 'password' => bcrypt('password123'), 'is_admin' => true]);

        $this->post(route('login'), ['email' => $email, 'password' => 'password123'])
            ->assertRedirect(route('filament.admin.pages.dashboard'));
    }

    public function test_home_constant_points_to_dashboard(): void
    {
        $this->assertSame('/dashboard', \App\Providers\RouteServiceProvider::HOME);
    }
```

- [ ] **Step 2: Run tests to verify the new ones fail**

Run: `docker compose exec laravel.test php artisan test --compact --filter=LoginControllerTest`
Expected: FAIL — `test_login_from_external_landing_referer_redirects_to_dashboard` and `test_login_from_home_referer_redirects_to_dashboard` fail (redirect goes to the referer instead of `route('dashboard')`); `test_home_constant_points_to_dashboard` fails (`'/' !== '/dashboard'`). The other new tests and all 4 pre-existing tests pass.

- [ ] **Step 3: Implement the fix**

In `backend/app/Http/Controllers/Auth/LoginController.php`, replace `showLoginForm()` (lines 37–46) with:

```php
    public function showLoginForm()
    {
        if (Redirect::getIntendedUrl() === null && $this->isReturnablePreviousUrl(url()->previous())) {
            Redirect::setIntendedUrl(url()->previous()); // make sure we redirect back to the page we came from
        }

        return view('auth.login', [
            'isOtpLoginEnabled' => config('app.otp_login_enabled'),
        ]);
    }

    private function isReturnablePreviousUrl(?string $previousUrl): bool
    {
        if (empty($previousUrl)) {
            return false;
        }

        if (parse_url($previousUrl, PHP_URL_HOST) !== parse_url(config('app.url'), PHP_URL_HOST)) {
            return false; // external referer (e.g. the landing site) — never "return" there after login
        }

        $excluded = array_map(
            fn (string $url): string => rtrim($url, '/'),
            [route('home'), route('login'), route('register')],
        );

        return ! in_array(rtrim($previousUrl, '/'), $excluded, true);
    }
```

In `backend/app/Providers/RouteServiceProvider.php` line 20, change:

```php
    public const HOME = '/dashboard';
```

(`/dashboard` is the auth-gated route in `routes/web.php:50` that resolves the user's Filament dashboard via `UserDashboardService`; new users get a tenant via the `CreateTenantIfNeeded` listener, so it never dead-ends.)

- [ ] **Step 4: Run the full auth-related test surface**

Run: `docker compose exec laravel.test php artisan test --compact --filter=LoginControllerTest`
Expected: PASS (10 tests: 4 pre-existing + 6 new).

Run: `docker compose exec laravel.test php artisan test --compact tests/Feature/Http/Controllers/Auth tests/Feature/Livewire/Auth`
Expected: PASS — no regressions in registration, password reset, verification, or OTP login tests.

- [ ] **Step 5: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint app/Http/Controllers/Auth/LoginController.php app/Providers/RouteServiceProvider.php tests/Feature/Http/Controllers/Auth/LoginControllerTest.php
git add backend/app/Http/Controllers/Auth/LoginController.php backend/app/Providers/RouteServiceProvider.php backend/tests/Feature/Http/Controllers/Auth/LoginControllerTest.php
git commit -m "fix(backend): never adopt external or home referers as post-login destination; HOME=/dashboard"
```

---

### Task 4: Landing page swaps Login → Dashboard for authenticated visitors

Progressive enhancement on the static landing page: default render keeps the two "Log in" links; a small script queries the Task 2 endpoint with credentials and, only on `authenticated: true`, rewrites them to "Dashboard".

**Files:**
- Modify: `frontend/src/pages/index.astro` (nav link ~line 141, footer link ~line 1061, new script)

**Interfaces:**
- Consumes: `GET ${PRODUCT_APP.url}/api/auth/status` from Task 2 (`{"authenticated": bool}`); `PRODUCT_APP` from the `flexpick:config` virtual module (already imported at the top of `index.astro`).
- Produces: nothing consumed elsewhere. `data-auth-entry` marks every swappable auth link.

- [ ] **Step 1: Tag the two login links**

In `frontend/src/pages/index.astro`, change the nav link (~line 141):

```astro
          <a href={`${PRODUCT_APP.url}/login`} class="fp-navlink" rel="nofollow" data-auth-entry>Log in</a>
```

and the footer link (~line 1061):

```astro
          <a href={`${PRODUCT_APP.url}/login`} class="fp-footlink" style="margin-top: 10px;" rel="nofollow" data-auth-entry
            >Client login</a
          >
```

- [ ] **Step 2: Add the auth-swap script**

In `frontend/src/pages/index.astro`, immediately before the existing closing of the page's script section (add as its own `<script>` block next to the other `<script>` blocks at the bottom of the file):

```astro
<script>
  import { PRODUCT_APP } from 'flexpick:config';

  async function swapAuthLinks() {
    try {
      const res = await fetch(`${PRODUCT_APP.url}/api/auth/status`, {
        credentials: 'include',
        signal: AbortSignal.timeout(4000),
      });
      if (!res.ok) return;
      const data = await res.json();
      if (!data.authenticated) return;
      document.querySelectorAll('[data-auth-entry]').forEach((el) => {
        el.textContent = 'Dashboard';
        el.setAttribute('href', `${PRODUCT_APP.url}/dashboard`);
      });
    } catch {
      // Leave the default "Log in" links on any failure or timeout.
    }
  }

  swapAuthLinks();
  document.addEventListener('astro:after-swap', swapAuthLinks);
</script>
```

- [ ] **Step 3: Verify frontend checks and build pass**

Run from `frontend/`: `npm run check && npm run build`
Expected: astro check, eslint, prettier all pass; build completes to `dist/`.

- [ ] **Step 4: Manual smoke test (requires Docker stack up)**

1. Open `http://localhost:4321` logged out → nav shows "Log in".
2. Log into `http://localhost:8080/login` (any seeded user), then reload `http://localhost:4321` → nav and footer show "Dashboard" linking to `http://localhost:8080/dashboard`.

Expected: both states render correctly; no console errors.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/pages/index.astro
git commit -m "feat(frontend): landing nav shows Dashboard for authenticated visitors"
```

---

### Task 5: Wire the audit catalog into the default seed and enrich it for the pricing page

`AuditMonetizationSeeder` already creates the $5 unlock and three tiers idempotently but is orphaned (nothing calls it) and its `Product` rows lack `features`/`is_popular`, which the `/pricing` page renders.

**Files:**
- Modify: `backend/database/seeders/AuditMonetizationSeeder.php`
- Modify: `backend/database/seeders/DatabaseSeeder.php`
- Test: `backend/tests/Feature/Seeders/AuditMonetizationSeederTest.php` (extend)

**Interfaces:**
- Consumes: `Currency` `USD` and `Interval` `month` rows (seeded by `CurrenciesSeeder`/`IntervalsSeeder`, already in both `DatabaseSeeder` and `TestingDatabaseSeeder`).
- Produces: catalog rows keyed by slug — `OneTimeProduct` `audit-report-unlock`; `Product`+`Plan` pairs `audit-starter`/`audit-starter-monthly`, `audit-growth`/`audit-growth-monthly`, `audit-scale`/`audit-scale-monthly`. Task 6 resolves plans by these exact `<slug>-monthly` slugs. `Product.metadata.audit_analyses_per_month` drives `AuditEntitlementService::subscriptionAllowance()`.

- [ ] **Step 1: Write the failing tests**

In `backend/tests/Feature/Seeders/AuditMonetizationSeederTest.php`, add `use Database\Seeders\DatabaseSeeder;` to the imports, append inside the existing `test_seeds_unlock_product_and_plans_idempotently` method (after the `foreach` loop):

```php
        $growth = Product::where('slug', 'audit-growth')->firstOrFail();
        $this->assertTrue((bool) $growth->is_popular);
        $this->assertNotEmpty($growth->features);
        $this->assertNotEmpty($unlock->features);
```

and add a new test method:

```php
    public function test_default_database_seeder_includes_audit_catalog(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, OneTimeProduct::where('slug', 'audit-report-unlock')->count());
        $this->assertSame(3, Product::whereIn('slug', ['audit-starter', 'audit-growth', 'audit-scale'])->count());
        $this->assertSame(3, Plan::whereIn('slug', ['audit-starter-monthly', 'audit-growth-monthly', 'audit-scale-monthly'])->where('is_active', true)->count());
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose exec laravel.test php artisan test --compact --filter=AuditMonetizationSeederTest`
Expected: FAIL — `assertTrue((bool) $growth->is_popular)` fails (seeder doesn't set it) and the new test fails on the product counts (DatabaseSeeder doesn't call the audit seeder).

- [ ] **Step 3: Enrich the seeder and wire it in**

Replace the full contents of `backend/database/seeders/AuditMonetizationSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Constants\PlanType;
use App\Models\Currency;
use App\Models\Interval;
use App\Models\OneTimeProduct;
use App\Models\Plan;
use App\Models\Product;
use Illuminate\Database\Seeder;

class AuditMonetizationSeeder extends Seeder
{
    /**
     * Payment-provider identifiers are intentionally NOT seeded: SaaSykit
     * creates provider products/prices on the fly at first checkout
     * (e.g. StripeProvider::findOrCreateStripe*), so placeholder IDs would
     * point at nonexistent Stripe objects and break test checkouts.
     */
    public function run(): void
    {
        $usd = Currency::where('code', 'USD')->firstOrFail();
        $month = Interval::where('slug', 'month')->firstOrFail();

        $unlock = OneTimeProduct::updateOrCreate(['slug' => 'audit-report-unlock'], [
            'name' => 'Full audit report unlock',
            'description' => 'Unlock every finding, recommendation, and the fix-first plan of one codebase audit report, including PDF export.',
            'features' => [
                ['feature' => 'All findings & recommendations'],
                ['feature' => 'Fix-first action plan'],
                ['feature' => 'PDF export'],
            ],
            'max_quantity' => 1,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $unlock->prices()->updateOrCreate(['currency_id' => $usd->id], ['price' => 500]);

        $tiers = [
            ['slug' => 'audit-starter', 'name' => 'Audit Starter', 'allowance' => 5, 'price' => 1000, 'is_popular' => false],
            ['slug' => 'audit-growth', 'name' => 'Audit Growth', 'allowance' => 20, 'price' => 3000, 'is_popular' => true],
            ['slug' => 'audit-scale', 'name' => 'Audit Scale', 'allowance' => 50, 'price' => 6000, 'is_popular' => false],
        ];

        foreach ($tiers as $tier) {
            $product = Product::updateOrCreate(['slug' => $tier['slug']], [
                'name' => $tier['name'],
                'description' => $tier['allowance'].' codebase analyses per month, fully detailed with PDF export.',
                'features' => [
                    ['feature' => $tier['allowance'].' codebase analyses / month'],
                    ['feature' => 'Full detailed reports'],
                    ['feature' => 'PDF export'],
                    ['feature' => 'Re-audit trends'],
                ],
                'is_popular' => $tier['is_popular'],
                'metadata' => ['audit_analyses_per_month' => $tier['allowance']],
                'is_default' => false,
            ]);

            $plan = Plan::updateOrCreate(['slug' => $tier['slug'].'-monthly'], [
                'name' => $tier['name'].' Monthly',
                'product_id' => $product->id,
                'interval_id' => $month->id,
                'interval_count' => 1,
                'has_trial' => false,
                'is_active' => true,
                'is_visible' => true,
                'type' => PlanType::FLAT_RATE->value,
            ]);

            $plan->prices()->updateOrCreate(['currency_id' => $usd->id], ['price' => $tier['price']]);
        }
    }
}
```

In `backend/database/seeders/DatabaseSeeder.php`, append to the `callOnce` array:

```php
        $this->callOnce([
            IntervalsSeeder::class,
            CurrenciesSeeder::class,
            OAuthLoginProvidersSeeder::class,
            PaymentProvidersSeeder::class,
            RolesAndPermissionsSeeder::class,
            EmailProvidersSeeder::class,
            VerificationProvidersSeeder::class,
            AuditMonetizationSeeder::class,
        ]);
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose exec laravel.test php artisan test --compact --filter=AuditMonetizationSeederTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint database/seeders/AuditMonetizationSeeder.php database/seeders/DatabaseSeeder.php tests/Feature/Seeders/AuditMonetizationSeederTest.php
git add backend/database/seeders/AuditMonetizationSeeder.php backend/database/seeders/DatabaseSeeder.php backend/tests/Feature/Seeders/AuditMonetizationSeederTest.php
git commit -m "feat(backend): wire audit catalog into default seed with pricing-page features"
```

---

### Task 6: Demo users covering every subscription and quota state

Deterministic, idempotent demo dataset for manual testing: one user per entitlement state, each with a tenant and a spread of audit requests/reports.

**Files:**
- Create: `backend/database/seeders/Demo/AuditDemoSeeder.php`
- Modify: `backend/database/seeders/Demo/DemoDatabaseSeeder.php` (call the new seeder at the end of `run()`)
- Test: `backend/tests/Feature/Seeders/AuditDemoSeederTest.php`

**Interfaces:**
- Consumes: plan slugs `audit-starter-monthly` / `audit-growth-monthly` / `audit-scale-monthly` (Task 5); `PaymentProvider` slug `stripe` (`PaymentProvidersSeeder`); `TenantPermissionService::assignTenantUserRole()`; `AuditRequest`/`AuditReport` models and `AuditReportFactory` (`->locked()` state); `SubscriptionStatus` enum; `TenancyPermissionConstants::ROLE_ADMIN`.
- Produces: demo users (password `password`) at `audit-starter-demo@flexpick.net`, `audit-growth-demo@flexpick.net`, `audit-scale-demo@flexpick.net`, `audit-trial-demo@flexpick.net`, `audit-cancelled-demo@flexpick.net`, `audit-expired-demo@flexpick.net`, `audit-free-demo@flexpick.net`, `audit-exhausted-demo@flexpick.net`. Later specs' dashboards are manually tested against these accounts.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Seeders/AuditDemoSeederTest.php`:

```php
<?php

namespace Tests\Feature\Seeders;

use App\Models\AuditRequest;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AuditReport\AuditEntitlementService;
use Database\Seeders\Demo\AuditDemoSeeder;
use Tests\Feature\FeatureTest;

class AuditDemoSeederTest extends FeatureTest
{
    public function test_seeds_demo_users_with_expected_entitlements_idempotently(): void
    {
        $this->seed(AuditDemoSeeder::class);
        $this->seed(AuditDemoSeeder::class); // idempotent

        $entitlements = app(AuditEntitlementService::class);

        // Active tiers expose their monthly allowance through the tenant subscription
        foreach ([['audit-starter-demo@flexpick.net', 5], ['audit-growth-demo@flexpick.net', 20], ['audit-scale-demo@flexpick.net', 50], ['audit-trial-demo@flexpick.net', 5]] as [$email, $allowance]) {
            $user = User::where('email', $email)->firstOrFail();
            $this->assertSame($allowance, $entitlements->subscriptionAllowance($user->tenants()->firstOrFail()), $email);
            $this->assertSame(1, $user->subscriptions()->count(), $email);
        }

        // Cancelled and expired subscriptions grant no allowance
        foreach (['audit-cancelled-demo@flexpick.net', 'audit-expired-demo@flexpick.net'] as $email) {
            $user = User::where('email', $email)->firstOrFail();
            $this->assertSame(0, $entitlements->subscriptionAllowance($user->tenants()->firstOrFail()), $email);
        }

        // Free-quota states
        $this->assertTrue($entitlements->hasFreeRun('audit-free-demo@flexpick.net'));
        $this->assertFalse($entitlements->hasFreeRun('audit-exhausted-demo@flexpick.net'));
        $this->assertSame(3, AuditRequest::where('email', 'audit-exhausted-demo@flexpick.net')->where('free_run', true)->count());

        // Idempotency: no duplicate users or subscriptions
        $this->assertSame(1, User::where('email', 'audit-starter-demo@flexpick.net')->count());
        $this->assertSame(1, Subscription::whereHas('user', fn ($q) => $q->where('email', 'audit-cancelled-demo@flexpick.net'))->count());

        // Completed demo audits carry a report
        $sent = AuditRequest::where('email', 'audit-starter-demo@flexpick.net')->where('status', 'sent')->first();
        $this->assertNotNull($sent);
        $this->assertNotNull($sent->report);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --compact --filter=AuditDemoSeederTest`
Expected: FAIL — `Class "Database\Seeders\Demo\AuditDemoSeeder" not found`.

- [ ] **Step 3: Implement the seeder**

Create `backend/database/seeders/Demo/AuditDemoSeeder.php`:

```php
<?php

namespace Database\Seeders\Demo;

use App\Constants\AuditRequestStatus;
use App\Constants\SubscriptionStatus;
use App\Constants\TenancyPermissionConstants;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantPermissionService;
use Carbon\CarbonInterface;
use Database\Seeders\AuditMonetizationSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AuditDemoSeeder extends Seeder
{
    public const PASSWORD = 'password';

    public function __construct(
        private TenantPermissionService $tenantPermissionService,
    ) {}

    public function run(): void
    {
        $this->call(AuditMonetizationSeeder::class); // catalog must exist (idempotent)

        $starter = $this->subscribedUser('audit-starter-demo@flexpick.net', 'Audit Starter Demo', 'audit-starter-monthly', SubscriptionStatus::ACTIVE, now()->addMonth());
        $growth = $this->subscribedUser('audit-growth-demo@flexpick.net', 'Audit Growth Demo', 'audit-growth-monthly', SubscriptionStatus::ACTIVE, now()->addMonth());
        $scale = $this->subscribedUser('audit-scale-demo@flexpick.net', 'Audit Scale Demo', 'audit-scale-monthly', SubscriptionStatus::ACTIVE, now()->addMonth());
        $this->subscribedUser('audit-trial-demo@flexpick.net', 'Audit Trial Demo', 'audit-starter-monthly', SubscriptionStatus::ACTIVE, now()->addMonth(), trialEndsAt: now()->addWeek());
        $this->subscribedUser('audit-cancelled-demo@flexpick.net', 'Audit Cancelled Demo', 'audit-growth-monthly', SubscriptionStatus::CANCELED, now()->addWeeks(2));
        $this->subscribedUser('audit-expired-demo@flexpick.net', 'Audit Expired Demo', 'audit-starter-monthly', SubscriptionStatus::PAST_DUE, now()->subWeek());

        // Subscribed users get dashboard-sourced audits in a spread of statuses
        $this->seedAuditRequests($starter, count: 4, source: 'dashboard');
        $this->seedAuditRequests($growth, count: 3, source: 'dashboard');
        $this->seedAuditRequests($scale, count: 2, source: 'dashboard');

        // Free-quota users: 1 of 3 used vs 3 of 3 used
        $free = $this->user('audit-free-demo@flexpick.net', 'Audit Free Demo');
        $this->tenantFor($free);
        $this->seedAuditRequests($free, count: 1, source: 'web', freeRun: true);

        $exhausted = $this->user('audit-exhausted-demo@flexpick.net', 'Audit Exhausted Demo');
        $this->tenantFor($exhausted);
        $this->seedAuditRequests($exhausted, count: 3, source: 'web', freeRun: true);
    }

    private function user(string $email, string $name): User
    {
        return User::where('email', $email)->first()
            ?? User::factory()->create(['email' => $email, 'name' => $name, 'password' => bcrypt(self::PASSWORD)]);
    }

    private function tenantFor(User $user): Tenant
    {
        $tenant = $user->tenants()->first();

        if ($tenant === null) {
            $tenant = Tenant::factory()->create(['created_by' => $user->id]);
            $tenant->users()->attach($user);
            $this->tenantPermissionService->assignTenantUserRole($tenant, $user, TenancyPermissionConstants::ROLE_ADMIN);
        }

        return $tenant;
    }

    private function subscribedUser(
        string $email,
        string $name,
        string $planSlug,
        SubscriptionStatus $status,
        CarbonInterface $endsAt,
        ?CarbonInterface $trialEndsAt = null,
    ): User {
        $user = $this->user($email, $name);
        $tenant = $this->tenantFor($user);
        $plan = Plan::where('slug', $planSlug)->firstOrFail();
        $price = $plan->prices()->firstOrFail();
        $stripe = PaymentProvider::where('slug', 'stripe')->firstOrFail();

        $subscription = $user->subscriptions()->where('plan_id', $plan->id)->first();

        if ($subscription === null) {
            $user->subscriptions()->create([
                'uuid' => Str::uuid(),
                'plan_id' => $plan->id,
                'price' => $price->price,
                'currency_id' => $price->currency_id,
                'status' => $status->value,
                'ends_at' => $endsAt,
                'trial_ends_at' => $trialEndsAt,
                'payment_provider_id' => $stripe->id,
                'interval_id' => $plan->interval_id,
                'interval_count' => $plan->interval_count,
                'tenant_id' => $tenant->id,
            ]);
        } else {
            $subscription->update(['status' => $status->value, 'ends_at' => $endsAt, 'trial_ends_at' => $trialEndsAt]);
        }

        return $user;
    }

    private function seedAuditRequests(User $user, int $count, string $source, bool $freeRun = false): void
    {
        $statuses = [
            AuditRequestStatus::SENT,
            AuditRequestStatus::ANALYZING,
            AuditRequestStatus::QUEUED,
            AuditRequestStatus::FAILED,
            AuditRequestStatus::AWAITING_ACCESS,
        ];

        for ($i = 0; $i < $count; $i++) {
            $status = $statuses[$i % count($statuses)];

            $request = AuditRequest::updateOrCreate(
                [
                    'email' => $user->email,
                    'repo_url' => 'https://github.com/flexpick-demo/'.Str::slug($user->name).'-repo-'.($i + 1),
                ],
                [
                    'name' => $user->name,
                    'message' => 'Demo audit request seeded for manual testing.',
                    'status' => $status->value,
                    'email_verified_at' => now(),
                    'free_run' => $freeRun,
                    'source' => $source,
                    'user_id' => $user->id,
                ],
            );

            if ($status === AuditRequestStatus::SENT && $request->report === null) {
                AuditReport::factory()->locked()->create([
                    'audit_request_id' => $request->id,
                    'user_id' => $user->id,
                ]);
            }
        }
    }
}
```

In `backend/database/seeders/Demo/DemoDatabaseSeeder.php`, add at the end of `run()` (after the OauthLoginProvider line):

```php
        $this->call(AuditDemoSeeder::class);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --compact --filter=AuditDemoSeederTest`
Expected: PASS.

Also re-run the catalog test (the demo seeder calls it internally):
Run: `docker compose exec laravel.test php artisan test --compact --filter="AuditMonetizationSeederTest|AuditDemoSeederTest"`
Expected: PASS (3 tests).

- [ ] **Step 5: Format and commit**

```bash
docker compose exec laravel.test vendor/bin/pint database/seeders/Demo/AuditDemoSeeder.php database/seeders/Demo/DemoDatabaseSeeder.php tests/Feature/Seeders/AuditDemoSeederTest.php
git add backend/database/seeders/Demo/AuditDemoSeeder.php backend/database/seeders/Demo/DemoDatabaseSeeder.php backend/tests/Feature/Seeders/AuditDemoSeederTest.php
git commit -m "feat(backend): demo users covering every subscription and audit-quota state"
```

---

### Task 7: Custom-styled Free Audit consent checkbox

Replace the native checkbox in the audit modal with the landing design language. Pure CSS; the native input stays in the DOM (visually hidden) so accessibility, form serialization (`fd.get('consent') === 'on'`), and label-click behavior are unchanged.

**Files:**
- Modify: `frontend/src/components/widgets/ContactModal.astro:68-77` (checkbox markup)
- Modify: `frontend/src/pages/index.astro` (add CSS inside the existing `<style is:global>` block at line ~1093, next to the `.fp-input` rules at ~line 1559)

**Interfaces:**
- Consumes: existing `.fp-field-group` (flex column, gap 7px) and the modal's palette — border `rgba(255,255,255,0.12)`, gold accent `#d4a853`, page background `#0b0a09`.
- Produces: CSS classes `fp-checkbox-row`, `fp-checkbox-input`, `fp-checkbox-box`, `fp-checkbox-text` (used only by the modal). Submit handler keeps reading `name="consent"` — do not rename the input.

- [ ] **Step 1: Replace the checkbox markup**

In `frontend/src/components/widgets/ContactModal.astro`, replace lines 68–77 (the consent `<label>`) with:

```astro
        <label class="fp-field-group fp-checkbox-row">
          <input type="checkbox" name="consent" class="fp-checkbox-input" />
          <span class="fp-checkbox-box" aria-hidden="true">
            <svg viewBox="0 0 12 12" fill="none">
              <path d="M2 6.5 4.8 9 10 3.5" stroke="#0b0a09" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
            </svg>
          </span>
          <span class="fp-checkbox-text">
            Send me occasional tips and product updates. No spam, unsubscribe anytime. See our
            <a href="/privacy" style="color: #d4a853;">privacy policy</a>.
          </span>
        </label>
```

- [ ] **Step 2: Add the checkbox CSS**

In `frontend/src/pages/index.astro`, inside the `<style is:global>` block, directly after the `.fp-input:focus` rule (~line 1572), add:

```css
  .fp-checkbox-row {
    flex-direction: row;
    align-items: flex-start;
    gap: 10px;
    font-size: 12px;
    color: rgba(232, 230, 222, 0.55);
    cursor: pointer;
  }
  .fp-checkbox-input {
    position: absolute;
    width: 1px;
    height: 1px;
    margin: -1px;
    padding: 0;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
    border: 0;
  }
  .fp-checkbox-box {
    flex: 0 0 auto;
    box-sizing: border-box;
    width: 18px;
    height: 18px;
    margin-top: 1px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 5px;
    transition:
      border-color 0.2s,
      background 0.2s;
  }
  .fp-checkbox-box svg {
    width: 12px;
    height: 12px;
    opacity: 0;
    transition: opacity 0.15s;
  }
  .fp-checkbox-row:hover .fp-checkbox-box {
    border-color: rgba(212, 168, 83, 0.5);
  }
  .fp-checkbox-input:focus-visible + .fp-checkbox-box {
    outline: 2px solid rgba(212, 168, 83, 0.7);
    outline-offset: 3px;
  }
  .fp-checkbox-input:checked + .fp-checkbox-box {
    background: #d4a853;
    border-color: #d4a853;
  }
  .fp-checkbox-input:checked + .fp-checkbox-box svg {
    opacity: 1;
  }
  .fp-checkbox-input:disabled + .fp-checkbox-box,
  .fp-checkbox-input:disabled ~ .fp-checkbox-text {
    opacity: 0.4;
    cursor: not-allowed;
  }
```

(The global `input:focus-visible` outline rule at ~line 1624 targets the now-hidden 1px input — the `:focus-visible + .fp-checkbox-box` rule above supplies the visible focus ring instead, using the same outline style.)

- [ ] **Step 3: Verify frontend checks and build pass**

Run from `frontend/`: `npm run check && npm run build`
Expected: astro check, eslint, prettier all pass; build completes.

- [ ] **Step 4: Manual smoke test**

Open `http://localhost:4321`, open the Free Audit modal:
1. Checkbox and label sit on one line; unchecked box shows the subtle border.
2. Hover → gold border tint. Keyboard Tab → visible gold focus ring. Click label text → toggles. Checked → gold box with dark check mark.
3. Submit with the box checked and confirm the request payload contains `"marketing_consent": true` (browser devtools network tab).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/components/widgets/ContactModal.astro frontend/src/pages/index.astro
git commit -m "feat(frontend): custom-styled consent checkbox in the free audit modal"
```

---

### Task 8: Full regression gate

**Files:** none (verification only).

**Interfaces:**
- Consumes: everything above.
- Produces: green suite proving no regressions.

- [ ] **Step 1: Run the full backend test suite**

Run: `docker compose exec laravel.test php artisan test --compact`
Expected: PASS — 0 failures (suite was 526 tests green before this plan; now +~12).

- [ ] **Step 2: Run static analysis**

Run: `docker compose exec laravel.test vendor/bin/phpstan analyse`
Expected: no new errors.

- [ ] **Step 3: Run the frontend CI gate**

Run from `frontend/`: `npm run check`
Expected: PASS.

- [ ] **Step 4: Verify the acceptance criteria end-to-end (manual)**

1. `docker compose up -d` from a cold start → `http://localhost:8080/admin` loads (no permission 500).
2. `docker compose exec laravel.test php artisan migrate:fresh --seed` → `/pricing` shows the three audit tiers.
3. `docker compose exec laravel.test php artisan db:seed --class="Database\Seeders\Demo\DemoDatabaseSeeder"` → demo accounts exist; log in as `audit-starter-demo@flexpick.net` / `password` → lands on the Filament dashboard, not the landing page.
4. Landing page at `http://localhost:4321` shows "Dashboard" while logged in, "Log in" after logout.

- [ ] **Step 5: Commit any stragglers and stop**

If verification produced no changes, nothing to commit. Report results.
