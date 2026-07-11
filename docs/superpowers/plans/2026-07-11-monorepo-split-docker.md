# Monorepo Split + Docker Dev Environment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `flexpick.net` (Astro landing) becomes the only marketing surface; the Laravel backend serves only auth/plans/checkout/dashboard on `app.flexpick.net`; one `docker compose up` at repo root boots both apps for development.

**Architecture:** Frontend gets a `productApp.url` config key (exposed via the existing `flexpick:config` virtual module, overridable with the `PUBLIC_APP_URL` env var) that all product-app links use. Backend replaces the SaaSykit marketing home with a redirect (`guest → /pricing`, `auth → dashboard`), adds a lean `/pricing` page reusing existing plan components, and removes public blog/roadmap routes. A root `compose.yml` `include:`s the backend's Sail compose (ngrok moved behind a profile) and adds a node:22 frontend service.

**Tech Stack:** Astro 6 (frontend), Laravel 13 + Blade components (backend), Docker Compose ≥ 2.20 (`include:` support).

**Spec:** `docs/superpowers/specs/2026-07-11-monorepo-split-docker-design.md`

## Global Constraints

- Frontend Node.js >= 22.12.0; all frontend commands run from `frontend/`; `npm run check` is the CI gate.
- Frontend code style: single quotes, semicolons, 120-char width, 2-space indent (`.prettierrc.cjs`); run `npm run fix` if check fails on formatting.
- Backend PHP 8.4, Laravel 13; all backend commands run from `backend/`; tests via `php artisan test --compact`; format with `vendor/bin/pint --dirty` before committing.
- Backend tests need MySQL + Redis running (use the Docker services: `docker compose up -d mysql redis` from `backend/`, or the root compose once Task 6 is done). The suite runs `migrate:fresh` + seeds once per process.
- Keep the route name `home` alive on `/` — ~15 PHP call sites and ~13 Blade templates reference `route('home')`.
- Production URLs: `https://flexpick.net` (landing), `https://app.flexpick.net` (backend). Dev: `http://localhost:4321` / `http://localhost:8080`.
- Commit after each task.

---

### Task 1: Frontend — `productApp` config key in the virtual module

**Files:**
- Modify: `frontend/vendor/integration/utils/configBuilder.ts`
- Modify: `frontend/vendor/integration/index.ts:29` and the virtual-module string at `index.ts:48-55`
- Modify: `frontend/vendor/integration/types.d.ts`
- Modify: `frontend/src/config.yaml`

**Interfaces:**
- Consumes: existing `configBuilder`/virtual-module pattern.
- Produces: `import { PRODUCT_APP } from 'flexpick:config'` → `{ url: string }` (no trailing slash). Tasks 2 and the audit-pipeline plan depend on this exact export name.

- [ ] **Step 1: Add the type + parser to `configBuilder.ts`**

Add after the existing interface declarations (around line 83, before `getSite`):

```ts
export interface ProductAppConfig {
  url: string;
}
```

Add `productApp?: ProductAppConfig;` to the `Config` type (after `analytics?: unknown;`):

```ts
export type Config = {
  site?: SiteConfig;
  metadata?: MetaDataConfig;
  i18n?: I18NConfig;
  apps?: {
    blog?: AppBlogConfig;
  };
  ui?: unknown;
  analytics?: unknown;
  productApp?: ProductAppConfig;
};
```

Add the parser next to `getAnalytics` (around line 194):

```ts
const getProductApp = (config: Config): ProductAppConfig => {
  const _default = {
    url: 'https://app.flexpick.net',
  };

  const merged = merge({}, _default, config?.productApp ?? {}) as ProductAppConfig;

  if (process.env.PUBLIC_APP_URL) {
    merged.url = process.env.PUBLIC_APP_URL;
  }

  merged.url = merged.url.replace(/\/+$/, '');

  return merged;
};
```

Add to the default export map (lines 196-203):

```ts
export default (config: Config) => ({
  SITE: getSite(config),
  I18N: getI18N(config),
  METADATA: getMetadata(config),
  APP_BLOG: getAppBlog(config),
  UI: getUI(config),
  ANALYTICS: getAnalytics(config),
  PRODUCT_APP: getProductApp(config),
});
```

- [ ] **Step 2: Export it from the virtual module in `index.ts`**

Line 29, destructure the new key:

```ts
const { SITE, I18N, METADATA, APP_BLOG, UI, ANALYTICS, PRODUCT_APP } = configBuilder(rawJsonConfig);
```

In the `load()` template string (lines 48-55), add one line before the closing backtick:

```ts
                    export const PRODUCT_APP = ${JSON.stringify(PRODUCT_APP)};
```

- [ ] **Step 3: Update the ambient declaration `types.d.ts`**

Replace the file's contents with:

```ts
declare module 'flexpick:config' {
  import type {
    SiteConfig,
    I18NConfig,
    MetaDataConfig,
    AppBlogConfig,
    UIConfig,
    AnalyticsConfig,
    ProductAppConfig,
  } from './config';

  export const SITE: SiteConfig;
  export const I18N: I18NConfig;
  export const METADATA: MetaDataConfig;
  export const APP_BLOG: AppBlogConfig;
  export const UI: UIConfig;
  export const ANALYTICS: AnalyticsConfig;
  export const PRODUCT_APP: ProductAppConfig;
}
```

(The `./config` import path mirrors the existing pattern — it resolves loosely, same as the six types already there. Do not restructure it.)

- [ ] **Step 4: Add the key to `src/config.yaml`**

Add a top-level section after the `site:` block:

```yaml
productApp:
  url: 'https://app.flexpick.net'
```

- [ ] **Step 5: Verify the build exposes the value**

Run from `frontend/`:

```bash
npm run check
npm run build
grep -ro "app.flexpick.net" dist/ | head -1
PUBLIC_APP_URL=http://localhost:8080 npm run build
```

Expected: `check` passes; first `build` succeeds. The `grep` will find nothing yet (no component uses `PRODUCT_APP` until Task 2) — that's fine; both builds completing without errors is the gate for this task.

- [ ] **Step 6: Commit**

```bash
git add frontend/vendor/integration frontend/src/config.yaml
git commit -m "feat(frontend): add productApp.url config exposed via flexpick:config"
```

---

### Task 2: Frontend — product-app links on the landing

**Files:**
- Modify: `frontend/src/pages/index.astro` (frontmatter ~line 3, nav lines 67-75, footer CONTACT column lines 903-914)

**Interfaces:**
- Consumes: `PRODUCT_APP` from Task 1.
- Produces: visible "Log in" links; no JS contract.

- [ ] **Step 1: Import the config in the frontmatter**

At the top of `index.astro`'s frontmatter (next to the existing imports, e.g. after line 3):

```ts
import { PRODUCT_APP } from 'flexpick:config';
```

- [ ] **Step 2: Add a "Log in" link to the sticky nav**

In the nav link group (lines 67-75), insert before the `Get a free audit` button (after the FAQ link on line 71):

```html
<a href={`${PRODUCT_APP.url}/login`} class="fp-navlink" rel="nofollow">Log in</a>
```

- [ ] **Step 3: Add a client-area link to the footer CONTACT column**

In the CONTACT column (after the `mailto:` link at line 910), add:

```html
<a href={`${PRODUCT_APP.url}/login`} class="fp-footlink" style="margin-top: 10px;" rel="nofollow">Client login</a>
```

- [ ] **Step 4: Verify**

```bash
npm run check && npm run build && grep -o "app.flexpick.net/login" dist/index.html | head -2
```

Expected: check passes; grep prints `app.flexpick.net/login` twice (nav + footer).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/pages/index.astro
git commit -m "feat(frontend): link landing nav/footer to product app login"
```

---

### Task 3: Backend — lean `/pricing` page

**Files:**
- Create: `backend/resources/views/pricing.blade.php`
- Modify: `backend/routes/web.php` (add route near the `/` route, lines 34-36)
- Test: `backend/tests/Feature/Http/Controllers/PricingPageTest.php`

**Interfaces:**
- Consumes: existing Blade components `<x-plans.all>` (attrs: `calculate-saving-rates`, `show-default-product`) and `<x-products.all />`; layout `<x-layouts.app>`.
- Produces: named route `pricing` (GET `/pricing`, `sitemapped` middleware). Tasks 4-5 link to `route('pricing')`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Http/Controllers/PricingPageTest.php`:

```php
<?php

namespace Tests\Feature\Http\Controllers;

use Tests\Feature\FeatureTest;

class PricingPageTest extends FeatureTest
{
    public function test_pricing_page_renders(): void
    {
        $response = $this->get(route('pricing'));

        $response->assertStatus(200);
        $response->assertSee(__('Plans & Pricing'));
    }

    public function test_pricing_page_shows_auth_links_for_guests(): void
    {
        $response = $this->get(route('pricing'));

        $response->assertSee(route('login'));
        $response->assertSee(route('register'));
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

Run: `php artisan test --compact --filter=PricingPageTest`
Expected: FAIL — `Route [pricing] not defined.`

- [ ] **Step 3: Add the route**

In `backend/routes/web.php`, directly below the `/` route block (after line 36):

```php
Route::get('/pricing', function () {
    return view('pricing');
})->name('pricing')->middleware('sitemapped');
```

- [ ] **Step 4: Create the view**

Create `backend/resources/views/pricing.blade.php` (the two component invocations are copied verbatim from the old `home.blade.php:619-621`):

```blade
<x-layouts.app>
    <x-slot name="title">
        {{ __('Plans & Pricing') }}
    </x-slot>

    <div class="mx-4 mt-16">
        <x-heading.h6 class="text-center mt-10 text-primary-500">
            {{ __('Plans & Pricing') }}
        </x-heading.h6>
        <x-heading.h2 class="text-primary-900 text-center">
            {{ __('Pick the plan that fits') }}
        </x-heading.h2>

        @guest
            <p class="text-center mt-4">
                <x-link href="{{ route('register') }}">{{ __('Create an account') }}</x-link>
                {{ __('or') }}
                <x-link href="{{ route('login') }}">{{ __('log in') }}</x-link>
                {{ __('to manage your subscription.') }}
            </p>
        @endguest
    </div>

    <div class="pricing">
        <x-plans.all calculate-saving-rates="true" show-default-product="1"/>
        <x-products.all />
    </div>
</x-layouts.app>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=PricingPageTest`
Expected: PASS (2 tests)

- [ ] **Step 6: Commit**

```bash
git add backend/routes/web.php backend/resources/views/pricing.blade.php backend/tests/Feature/Http/Controllers/PricingPageTest.php
git commit -m "feat(backend): add lean /pricing page with plan and product components"
```

---

### Task 4: Backend — `/` becomes a redirect (keep route name `home`)

**Files:**
- Modify: `backend/routes/web.php:34-36`
- Delete: `backend/resources/views/home.blade.php`
- Modify: `backend/tests/Feature/AppTest.php`
- Modify: `backend/tests/Feature/Http/Controllers/HomeControllerTest.php`

**Interfaces:**
- Consumes: `route('pricing')` (Task 3), `UserDashboardService::getUserDashboardUrl(User): string` (existing — returns `route('home')` when the user has no tenant; guard against that or `/` would redirect to itself in a loop).
- Produces: `/` (name `home`) → 302 to `/pricing` (guest / tenantless user) or the Filament dashboard URL (user with a tenant). No longer `sitemapped`.

- [ ] **Step 1: Write the failing tests**

Replace the two home-200 assertions. In `backend/tests/Feature/Http/Controllers/HomeControllerTest.php`, replace the entire file with:

```php
<?php

namespace Tests\Feature\Http\Controllers;

use Tests\Feature\FeatureTest;

class HomeControllerTest extends FeatureTest
{
    public function test_guest_is_redirected_to_pricing(): void
    {
        $response = $this->get(route('home'));

        $response->assertRedirect(route('pricing'));
    }

    public function test_user_with_tenant_is_redirected_to_dashboard(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $this->actingAs($user);

        $response = $this->get(route('home'));

        $response->assertRedirect(route('filament.dashboard.pages.dashboard', ['tenant' => $tenant]));
    }

    public function test_user_without_tenant_is_redirected_to_pricing(): void
    {
        $user = $this->createUser();
        $this->actingAs($user);

        $response = $this->get(route('home'));

        $response->assertRedirect(route('pricing'));
    }
}
```

Note: check the actual signatures of `createUser()` / `createTenant()` in `tests/Feature/FeatureTest.php` before running — if `createUser()` requires a tenant argument shape different from shown, adapt the calls (the intent is: one user attached to a tenant, one user with no tenants).

In `backend/tests/Feature/AppTest.php`, the four existing tests `$this->get('/')` and assert 200 / tracking scripts. Point them at the pricing page instead — change every `$this->get('/')` to `$this->get('/pricing')` (tracking scripts and cookie-consent render via the shared layout, which `/pricing` uses).

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=HomeControllerTest`
Expected: FAIL — `/` currently returns 200 with the landing view.

- [ ] **Step 3: Replace the home route**

In `backend/routes/web.php`, replace lines 34-36:

```php
Route::get('/', function () {
    return view('home');
})->name('home')->middleware('sitemapped');
```

with:

```php
Route::get('/', function (UserDashboardService $dashboardService) {
    if (auth()->check()) {
        $dashboardUrl = $dashboardService->getUserDashboardUrl(auth()->user());

        if ($dashboardUrl !== route('home')) {
            return redirect($dashboardUrl);
        }
    }

    return redirect()->route('pricing');
})->name('home');
```

(`UserDashboardService` is already imported at the top of the file. Note `sitemapped` is removed — a redirect must not be in the sitemap; `/pricing` replaces it there via Task 3.)

- [ ] **Step 4: Delete the landing view**

```bash
rm backend/resources/views/home.blade.php
```

- [ ] **Step 5: Run the tests**

Run: `php artisan test --compact --filter="HomeControllerTest|AppTest|PricingPageTest"`
Expected: PASS. Then run the whole suite to catch anything else that rendered `view('home')`:

Run: `php artisan test --compact`
Expected: PASS except `BlogControllerTest` / `RoadmapControllerTest` must still pass here (their routes are untouched until Task 5). If any other test fails because it expected the landing markup, repoint it at `/pricing` following the AppTest pattern.

- [ ] **Step 6: Commit**

```bash
git add -A backend/routes/web.php backend/resources/views backend/tests
git commit -m "feat(backend): replace marketing home with redirect to pricing/dashboard"
```

---

### Task 5: Backend — remove public blog & roadmap, fix all references

**Files:**
- Modify: `backend/routes/web.php` (blog group lines ~147-154, roadmap group lines ~181-189, remove now-unused `BlogController`/`RoadmapController` imports)
- Modify: `backend/resources/views/components/layouts/app/navigation-links.blade.php`
- Modify: `backend/resources/views/components/layouts/app/header.blade.php:27`
- Modify: `backend/resources/views/components/layouts/app/footer.blade.php:11`
- Modify: `backend/app/Filament/Admin/Resources/BlogPosts/Pages/EditBlogPost.php` (two `route('blog.view', ...)` closures)
- Modify: `backend/app/Console/Commands/GenerateSitemap.php` (blog post/category URL appending, lines ~58-74)
- Delete: `backend/tests/Feature/Http/Controllers/BlogControllerTest.php`, `backend/tests/Feature/Http/Controllers/RoadmapControllerTest.php`
- Test: `backend/tests/Feature/Http/Controllers/RemovedMarketingRoutesTest.php`

**Interfaces:**
- Consumes: `route('pricing')` (Task 3).
- Produces: `/blog*` and `/roadmap*` return 404; header/nav/footer no longer reference removed routes or landing anchors.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Http/Controllers/RemovedMarketingRoutesTest.php`:

```php
<?php

namespace Tests\Feature\Http\Controllers;

use Illuminate\Routing\Exceptions\UrlGenerationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Feature\FeatureTest;

class RemovedMarketingRoutesTest extends FeatureTest
{
    public function test_blog_routes_are_gone(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->get('/blog');
    }

    public function test_roadmap_routes_are_gone(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->get('/roadmap');
    }

    public function test_pricing_page_has_no_dead_links(): void
    {
        $response = $this->get(route('pricing'));

        $response->assertStatus(200);
        $response->assertDontSee('/blog');
        $response->assertDontSee('/roadmap');
    }
}
```

(`FeatureTest` calls `withoutExceptionHandling()`, so missing routes throw `NotFoundHttpException` instead of rendering a 404 response.)

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter=RemovedMarketingRoutesTest`
Expected: FAIL — `/blog` and `/roadmap` return 200.

- [ ] **Step 3: Remove the route groups**

In `backend/routes/web.php` delete the blog group:

```php
// blog
Route::controller(BlogController::class)
    ->prefix('/blog')
    ->group(function () {
        Route::get('/', 'all')->name('blog')->middleware('sitemapped');
        Route::get('/category/{slug}', 'category')->name('blog.category');
        Route::get('/{slug}', 'view')->name('blog.view');
    });
```

and the roadmap group:

```php
// roadmap

Route::controller(RoadmapController::class)
    ->prefix('/roadmap')
    ->group(function () {
        Route::get('/', 'index')->name('roadmap');
        Route::get('/i/{itemSlug}', 'viewItem')->name('roadmap.viewItem');
        Route::get('/suggest', 'suggest')->name('roadmap.suggest')->middleware('auth');
    });
```

Also delete the now-unused imports at the top of the file: `use App\Http\Controllers\BlogController;` and `use App\Http\Controllers\RoadmapController;`. (Controllers, models, and Filament blog admin stay — only public routes go.)

- [ ] **Step 4: Fix the shared navigation**

Replace the full contents of `backend/resources/views/components/layouts/app/navigation-links.blade.php` (the `#features`/`#tech-stack`/`#pricing`/`#faq` anchors resolved against the deleted landing, and `roadmap`/`blog` route names no longer exist):

```blade
<x-nav.item route="pricing">{{ __('Pricing') }}</x-nav.item>
@guest
    <x-nav.item route="login" class="md:hidden">{{ __('Login') }}</x-nav.item>
@endguest
```

In `backend/resources/views/components/layouts/app/header.blade.php` line 27, change the guest "Get started" button target from the dead anchor to the pricing route:

```blade
<x-button-link.secondary elementType="a" href="{{ route('pricing') }}">{{ __('Get started') }}</x-button-link.secondary>
```

In `backend/resources/views/components/layouts/app/footer.blade.php` line 11, delete the Blog link line:

```blade
<x-link class="text-white" href="{{route('blog')}}">{{ __('Blog') }}</x-link>
```

(Exact markup may differ slightly — delete the whole `<x-link ...route('blog')...>` element. Privacy/Terms links stay.)

- [ ] **Step 5: Fix Filament + sitemap references to blog routes**

`backend/app/Filament/Admin/Resources/BlogPosts/Pages/EditBlogPost.php` has two header/form actions with `->url(fn (BlogPost $resource) => route('blog.view', $resource->slug), true)`. Rendering the edit page would now throw `RouteNotFoundException`. Delete both `Action::make('view')...` blocks entirely (in `getHeaderActions()` keep the `ActionGroup::make([DeleteAction::make()])`; in `getFormActions()` return just `parent::getFormActions()`).

In `backend/app/Console/Commands/GenerateSitemap.php`, delete the blog-post and blog-category appending (the `$blogService->getAllPostsQuery()->chunk(...)` block and the `BlogPostCategory::whereHas(...)` block plus its `foreach`), and remove the now-unused imports/constructor params they relied on (`BlogService`, `BlogPostCategory` — check the top of the file and the `handle()` signature). The command should now emit only `sitemapped` GET routes.

- [ ] **Step 6: Sweep for leftovers**

```bash
grep -rn "route('blog\|route('roadmap\|route(\"blog\|route(\"roadmap" backend/app backend/resources/views --include="*.php" | grep -v "views/blog/" | grep -v "views/roadmap/" | grep -v "components/blog/" | grep -v "components/roadmap/" | grep -v "livewire/roadmap"
```

Expected: no output. (Views under `views/blog/`, `views/roadmap/`, `components/blog/`, `components/roadmap/`, `livewire/roadmap/` are unreachable dead views — leave them; deleting is optional cleanup out of scope. If the grep shows any *other* file, remove that reference the same way as Step 4/5.)

- [ ] **Step 7: Delete obsolete tests and run the suite**

```bash
rm backend/tests/Feature/Http/Controllers/BlogControllerTest.php backend/tests/Feature/Http/Controllers/RoadmapControllerTest.php
php artisan test --compact
```

Expected: full suite PASS, including `RemovedMarketingRoutesTest`. If Livewire/Filament tests reference roadmap components (`tests/Feature/Livewire/`), check whether they test the public page (delete) or the component in isolation (keep).

- [ ] **Step 8: Commit**

```bash
git add -A backend
git commit -m "feat(backend): remove public blog and roadmap surfaces"
```

---

### Task 6: Backend compose — ngrok behind a profile

**Files:**
- Modify: `backend/compose.yml` (depends_on lines 25-29, ngrok service lines 78-86)

**Interfaces:**
- Produces: `docker compose up` from `backend/` boots without `NGROK_AUTHTOKEN`; `docker compose --profile ngrok up` includes ngrok.

- [ ] **Step 1: Edit `backend/compose.yml`**

In the `laravel.test` service, remove `- ngrok` from `depends_on` (a profiled service cannot be a hard dependency of an unprofiled one):

```yaml
        depends_on:
            - mysql
            - mailpit
            - redis
```

In the `ngrok` service, add a profile as the first key under the service name:

```yaml
    ngrok:
        profiles: [ngrok]
        image: ngrok/ngrok:alpine
        environment:
            NGROK_AUTHTOKEN: ${NGROK_AUTHTOKEN}
        networks:
            - sail
        ports:
            - "${FORWARD_NGROK_PORT:-4040}:4040"
        command: [ "http", "--domain=${NGROK_STATIC_DOMAIN}", "laravel.test:80" ]
```

- [ ] **Step 2: Verify config parses and ngrok is excluded by default**

Run from `backend/`:

```bash
docker compose config --services
docker compose --profile ngrok config --services
```

Expected: first command lists `laravel.test mysql mailpit redis` (no ngrok); second includes `ngrok`.

- [ ] **Step 3: Commit**

```bash
git add backend/compose.yml
git commit -m "chore(backend): move ngrok behind a compose profile"
```

---

### Task 7: Root compose + first-run docs

**Files:**
- Create: `compose.yml` (repo root)
- Modify: `CLAUDE.md` (repo root — dev-environment section)

**Interfaces:**
- Consumes: `backend/compose.yml` (Task 6), `PUBLIC_APP_URL` env override (Task 1).
- Produces: `docker compose up -d` at repo root → backend on :8080, frontend dev server on :4321, Mailpit UI on :8025.

- [ ] **Step 1: Create root `compose.yml`**

```yaml
include:
    - path: backend/compose.yml

services:
    frontend:
        image: node:22-alpine
        working_dir: /app
        command: sh -c "npm install && npm run dev -- --host 0.0.0.0"
        environment:
            PUBLIC_APP_URL: 'http://localhost:8080'
        volumes:
            - ./frontend:/app
        ports:
            - '4321:4321'
        restart: unless-stopped
```

Notes: `include:` resolves the backend file's relative paths (`./docker/8.4`, volume `.:/var/www/html`) against `backend/`, so the Sail setup works unchanged. `--host 0.0.0.0` is required — Astro's dev server binds localhost-only by default and would be unreachable from outside the container.

- [ ] **Step 2: Verify the merged config**

```bash
docker compose config --services
```

Expected output (order may vary): `frontend laravel.test mysql mailpit redis`.

- [ ] **Step 3: Boot and smoke-test**

Prerequisite: `backend/.env` exists (`cp backend/.env.example backend/.env`) with `APP_PORT=8080`, `APP_URL=http://localhost:8080`, `WWWGROUP`/`WWWUSER` set (e.g. `WWWGROUP=1000`, `WWWUSER=1000` appended to `backend/.env` — Sail reads them from the compose env).

```bash
docker compose up -d
docker compose exec laravel.test composer install
docker compose exec laravel.test php artisan key:generate
docker compose exec laravel.test php artisan migrate:fresh --seed
curl -sI http://localhost:8080 | head -1
curl -sI http://localhost:4321 | head -1
```

Expected: backend returns `HTTP/1.1 302 Found` (the Task 4 redirect); frontend returns `HTTP/1.1 200 OK` (allow ~60s for the first `npm install`; watch `docker compose logs -f frontend`).

- [ ] **Step 4: Document the first-run flow in root `CLAUDE.md`**

Add a `## Docker dev environment` section to the repo-root `CLAUDE.md` (after "Repository Layout"):

```markdown
## Docker dev environment

One command boots both apps (requires Docker Compose >= 2.20):

​```bash
cp backend/.env.example backend/.env   # first time only; ensure APP_PORT=8080, WWWUSER/WWWGROUP set
docker compose up -d                   # backend :8080, frontend :4321, Mailpit UI :8025
docker compose exec laravel.test composer install
docker compose exec laravel.test php artisan key:generate
docker compose exec laravel.test php artisan migrate:fresh --seed
​```

- Backend Vite HMR (optional): `docker compose exec laravel.test npm install && docker compose exec laravel.test npm run dev`
- Queue worker (needed for emails/report pipeline): `docker compose exec laravel.test php artisan horizon`
- ngrok (webhook testing, needs `NGROK_AUTHTOKEN` in `backend/.env`): `docker compose --profile ngrok up -d`
- Frontend-only or backend-only workflows still work from each app directory as before.
```

(Remove the zero-width escapes around the backticks when writing the actual file — shown here only to nest the code fence.)

- [ ] **Step 5: Commit**

```bash
git add compose.yml CLAUDE.md
git commit -m "feat: root docker compose for full dev environment"
```

---

## Final verification

- [ ] From a clean `docker compose down -v && docker compose up -d` + first-run flow: `http://localhost:4321` shows the landing with "Log in" pointing at `http://localhost:8080/login`; `http://localhost:8080/` redirects to `/pricing`; register → checkout click-through reaches a Filament dashboard; `/blog` and `/roadmap` 404.
- [ ] `cd frontend && npm run check` passes.
- [ ] `cd backend && php artisan test --compact` passes and `vendor/bin/phpstan analyse` reports no new errors.
