# Unified Frontend Layout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restyle every backend-served public page (auth, pricing, checkout, verification, legal) to the landing site's dark gold design, and brand-align the Filament user dashboard.

**Architecture:** All theming flows through the existing Tailwind 4 `@theme` tokens (`resources/css/colors.css`), one custom daisyUI dark theme (shared by the public bundle and the Filament dashboard bundle), and three Blade layout components (`app`, `focus`, `focus-center`) that carry all public pages. Layout slot names stay unchanged; no Livewire/controller logic changes.

**Tech Stack:** Laravel 13 Blade + Tailwind CSS 4 + daisyUI 5 (backend), Filament 5 panels, Vite. Fonts: DM Sans (body), Syne 700/800 (headings), JetBrains Mono 400/500 (labels).

**Spec:** `docs/superpowers/specs/2026-07-13-unified-frontend-layout-design.md`

## Global Constraints

- Backend commands run from the repo root via `docker compose exec laravel.test <cmd>` (container workdir = backend app). Backend asset build: `docker compose exec laravel.test npm run build`.
- Backend tests: `docker compose exec laravel.test php artisan test --compact --filter=<Name>`.
- Format changed PHP with `docker compose exec laravel.test vendor/bin/pint <files>` before committing. Blade/CSS files are not Pint targets.
- **Palette (exact values):** canvas `#0b0a09`; card surface `#16130f`; borders `rgba(255,255,255,0.06)` chrome / `rgba(255,255,255,0.12)` inputs+cards (Tailwind: `border-white/5`, `border-white/10`); body text cream `#e8e6de`; bright text `#f5f5f0`; gold `#d4a853`; coral `#e2694a` / error `#dc6b5a`.
- **Fonts (exact request):** `https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=Syne:wght@700;800&family=JetBrains+Mono:wght@400;500&display=swap` (same as the landing).
- Public pages are dark-only; no theme toggle.
- Layout slot names (`$left`, `$right`, `$slot`, `$title`) must not change.
- Do NOT touch: `resources/views/reports/*` (light document pages), `components/layouts/email.blade.php`, error pages, `app/Providers/Filament/AdminPanelProvider.php`, any routes/controllers/Livewire classes.
- The Filament dashboard theme (`resources/css/filament/dashboard/theme.css`) already imports `colors.css` — token changes flow into it automatically; its daisyUI theme line must switch to the shared `flexpick` theme (Task 6).

---

### Task 1: Design tokens, shared daisyUI dark theme, and fonts

**Files:**
- Modify: `backend/resources/css/colors.css` (full rewrite)
- Create: `backend/resources/css/flexpick-daisyui.css` (shared daisyUI theme definition)
- Modify: `backend/resources/css/app.css`
- Modify: `backend/resources/css/styles.css:1-9` (the `@theme` font/size block only)
- Modify: `backend/resources/views/components/layouts/partials/head.blade.php` (font link)

**Interfaces:**
- Consumes: nothing.
- Produces: Tailwind utilities used by every later task — `bg-ink`, `text-cream-100`, `text-cream-200`, `font-heading`, `font-mono`, gold `primary-*` scale, coral `secondary-*` scale; daisyUI theme name `flexpick` (semantic classes `bg-base-100`, `btn-primary`, `input`, `text-error` flip to dark/gold). Task 6 imports `flexpick-daisyui.css` into the Filament bundle.

- [ ] **Step 1: Rewrite the color tokens**

Replace the full contents of `backend/resources/css/colors.css`:

```css
@theme {
    /* Gold — primary accent (landing #d4a853) */
    --color-primary-50: #faf6ec;
    --color-primary-100: #f3e8cd;
    --color-primary-200: #ecdaad;
    --color-primary-300: #e3c88b;
    --color-primary-400: #dbb86d;
    --color-primary-500: #d4a853;
    --color-primary-600: #b98e41;
    --color-primary-700: #967232;
    --color-primary-800: #735723;
    --color-primary-900: #513d17;
    --color-primary-950: #33260c;

    /* Coral — secondary accent (landing #e2694a) */
    --color-secondary-50: #fcefeb;
    --color-secondary-100: #f7d4c9;
    --color-secondary-200: #f2b9a7;
    --color-secondary-300: #ec9d85;
    --color-secondary-400: #e78367;
    --color-secondary-500: #e2694a;
    --color-secondary-600: #c55538;
    --color-secondary-700: #9c432c;
    --color-secondary-800: #733120;
    --color-secondary-900: #4b2015;
    --color-secondary-950: #2f130b;

    /* Dark canvas surfaces (landing "Stabilize" design) */
    --color-ink: #0b0a09;
    --color-surface: #16130f;

    /* Cream text tones */
    --color-cream-100: #f5f5f0;
    --color-cream-200: #e8e6de;
}
```

- [ ] **Step 2: Create the shared daisyUI theme**

Create `backend/resources/css/flexpick-daisyui.css`:

```css
/* FlexPick dark theme — shared by the public bundle (app.css) and the
   Filament dashboard bundle (filament/dashboard/theme.css). */
@plugin "daisyui/theme" {
    name: 'flexpick';
    default: true;
    color-scheme: dark;
    --color-base-100: #16130f;
    --color-base-200: #0f0d0b;
    --color-base-300: #211c16;
    --color-base-content: #e8e6de;
    --color-primary: #d4a853;
    --color-primary-content: #0b0a09;
    --color-secondary: #e2694a;
    --color-secondary-content: #0b0a09;
    --color-accent: #d4a853;
    --color-accent-content: #0b0a09;
    --color-neutral: #262019;
    --color-neutral-content: #e8e6de;
    --color-info: #97deff;
    --color-info-content: #0b0a09;
    --color-success: #9ac27a;
    --color-success-content: #0b0a09;
    --color-warning: #d4a853;
    --color-warning-content: #0b0a09;
    --color-error: #dc6b5a;
    --color-error-content: #0b0a09;
    --radius-selector: 0.5rem;
    --radius-field: 0.5rem;
    --radius-box: 0.75rem;
}
```

- [ ] **Step 3: Wire the theme into the public bundle**

In `backend/resources/css/app.css`, replace:

```css
@plugin "daisyui" {
    themes: light --default;
}
```

with:

```css
@plugin "daisyui" {
    themes: false;
}
@import './flexpick-daisyui.css';
```

(daisyUI 5's `themes:` list only accepts built-in themes — `themes: false` disables them, and the custom `flexpick` theme registers itself as default via the `@plugin "daisyui/theme"` block with `default: true`.)

(Keep everything else in the file — imports, `@custom-variant dark`, `@source` lines — unchanged.)

- [ ] **Step 4: Swap the font tokens**

In `backend/resources/css/styles.css`, inside the `@theme` block, replace the `--font-sans` declaration:

```css
    --font-sans: 'Poppins', 'Instrument Sans', ui-sans-serif, system-ui, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji',
    'Segoe UI Symbol', 'Noto Color Emoji';
```

with:

```css
    --font-sans: 'DM Sans', ui-sans-serif, system-ui, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji',
    'Segoe UI Symbol', 'Noto Color Emoji';
    --font-heading: 'Syne', ui-sans-serif, system-ui, sans-serif;
    --font-mono: 'JetBrains Mono', ui-monospace, 'SF Mono', Menlo, monospace;
```

- [ ] **Step 5: Swap the Google Fonts link**

In `backend/resources/views/components/layouts/partials/head.blade.php`, replace:

```html
<link href="https://fonts.googleapis.com/css2?family=Poppins:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap" rel="stylesheet">
```

with:

```html
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=Syne:wght@700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
```

(Keep the two `preconnect` lines above it.)

- [ ] **Step 6: Build and verify no regressions**

Run: `docker compose exec laravel.test npm run build`
Expected: Vite build succeeds (Tailwind compiles the new tokens and daisyUI theme without errors).

Run: `docker compose exec laravel.test php artisan test --compact`
Expected: PASS — token changes alone must not break any behavior test.

- [ ] **Step 7: Commit**

```bash
git add backend/resources/css/colors.css backend/resources/css/flexpick-daisyui.css backend/resources/css/app.css backend/resources/css/styles.css backend/resources/views/components/layouts/partials/head.blade.php
git commit -m "feat(backend): landing design tokens, flexpick daisyUI dark theme, DM Sans/Syne/JetBrains Mono"
```

---

### Task 2: Wordmark component and the `app` layout family (header, footer, nav)

**Files:**
- Create: `backend/resources/views/components/wordmark.blade.php`
- Modify: `backend/config/app.php` (add `frontend_url` after the `logo` array, ~line 185)
- Modify: `backend/.env.example` (add `FRONTEND_URL`)
- Modify: `backend/resources/views/components/layouts/app.blade.php` (full rewrite)
- Modify: `backend/resources/views/components/layouts/app/header.blade.php` (full rewrite)
- Modify: `backend/resources/views/components/layouts/app/footer.blade.php` (full rewrite)
- Modify: `backend/resources/views/components/nav/item.blade.php` (full rewrite)
- Modify: `backend/resources/views/components/layouts/simple.blade.php` (body class only)
- Test: `backend/tests/Feature/Http/LayoutBrandingTest.php` (new)

**Interfaces:**
- Consumes: Task 1 utilities (`bg-ink`, `text-cream-*`, `font-heading`, `font-mono`).
- Produces: `<x-wordmark />` — renders `data-brand="flexpick"` markup (Tasks 3 and 6 reuse it); `config('app.frontend_url')` — landing site URL (Task 3's layouts may link to it); the smoke-test file Task 3 extends.

- [ ] **Step 1: Write the failing smoke test**

Create `backend/tests/Feature/Http/LayoutBrandingTest.php`:

```php
<?php

namespace Tests\Feature\Http;

use Tests\Feature\FeatureTest;

class LayoutBrandingTest extends FeatureTest
{
    /**
     * Pages rendered through the shared `app` layout must show the FlexPick
     * wordmark and the dark landing canvas.
     */
    public function test_app_layout_pages_render_flexpick_branding_on_dark_canvas(): void
    {
        foreach ([route('pricing'), '/terms-of-service', '/privacy-policy'] as $url) {
            $response = $this->get($url);

            $response->assertOk();
            $response->assertSee('data-brand="flexpick"', false);
            $response->assertSee('bg-ink', false);
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --compact --filter=LayoutBrandingTest`
Expected: FAIL — `data-brand="flexpick"` not found on the pricing page.

- [ ] **Step 3: Create the wordmark component and frontend_url config**

Create `backend/resources/views/components/wordmark.blade.php`:

```blade
<span data-brand="flexpick" {{ $attributes->merge(['class' => 'font-heading font-extrabold text-xl tracking-tight text-cream-100']) }}>Flex<span class="text-primary-500">Pick</span></span>
```

In `backend/config/app.php`, directly after the `'logo' => [...]` array (~line 185), add:

```php
    // Public marketing/landing site (static Astro app; separate origin from this backend)
    'frontend_url' => env('FRONTEND_URL', 'https://flexpick.net'),
```

In `backend/.env.example`, directly under the `CORS_ALLOWED_ORIGINS` line, add:

```dotenv
FRONTEND_URL=http://localhost:4321
```

Also add `FRONTEND_URL=http://localhost:4321` to your local `backend/.env`.

- [ ] **Step 4: Rewrite the app layout**

Replace the full contents of `backend/resources/views/components/layouts/app.blade.php`:

```blade
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- CSRF Token -->
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @include('components.layouts.partials.head')
</head>
<body class="bg-ink text-cream-200 font-sans flex flex-col min-h-screen" x-data>
    <div id="app" class="flex flex-col grow">
        <x-layouts.app.header class="shrink-0"/>

        <div class="grow">
            <div class="mx-auto">
                {{ $slot }}
            </div>
        </div>

        <x-layouts.app.footer class="shrink-0" />

        @include('components.layouts.partials.tail')
    </div>
    <x-impersonate::banner/>
</body>
</html>
```

- [ ] **Step 5: Rewrite the header**

Replace the full contents of `backend/resources/views/components/layouts/app/header.blade.php`:

```blade
<nav class="sticky top-0 z-50 bg-ink/70 backdrop-blur-md border-b border-white/5">
    <livewire:announcement.view />
    <div class="navbar max-w-(--breakpoint-xl) items-center mx-auto px-4">
        <div class="navbar-start">
            <div class="dropdown">
                <div tabindex="0" role="button" class="btn btn-ghost lg:hidden me-1 text-cream-200">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h8m-8 6h16" /></svg>
                </div>
                <ul tabindex="0" class="menu menu-lg dropdown-content mt-3 z-1 p-2 border border-white/10 bg-base-200 rounded-box w-52">
                    <x-layouts.app.navigation-links></x-layouts.app.navigation-links>
                </ul>
            </div>
            <a href="{{ config('app.frontend_url') }}" class="flex justify-center items-center">
                <x-wordmark />
            </a>
        </div>
        <div class="navbar-center hidden lg:flex">
            <x-nav>
                <x-layouts.app.navigation-links></x-layouts.app.navigation-links>
            </x-nav>
        </div>
        <div class="navbar-end gap-4">
            @auth
                <x-layouts.app.user-menu></x-layouts.app.user-menu>
            @else
                <x-link class="hidden md:block font-mono text-xs tracking-[0.12em] uppercase text-cream-200/70 hover:text-cream-100" href="{{route('login')}}">{{ __('Login') }}</x-link>
                <x-button-link.primary elementType="a" href="{{ route('pricing') }}">{{ __('Get started') }}</x-button-link.primary>
            @endauth
        </div>
    </div>
</nav>
```

- [ ] **Step 6: Rewrite the footer**

Replace the full contents of `backend/resources/views/components/layouts/app/footer.blade.php`:

```blade
<footer class="border-t border-white/5 mt-12">
    <div class="mx-auto w-full max-w-(--breakpoint-xl) p-4 py-10">
        <div class="md:flex md:justify-between md:items-start gap-8">
            <div class="mb-6 md:mb-0">
                <a href="{{ config('app.frontend_url') }}" class="flex items-center">
                    <x-wordmark />
                </a>
                <p class="font-mono text-[11px] tracking-[0.14em] text-cream-200/50 mt-4">{{ __('WE RESCUE AI-BUILT CODEBASES') }}</p>
            </div>
            <div class="mb-6 md:mb-0">
                <p class="font-mono text-[10px] tracking-[0.16em] text-cream-200/50 mb-4">{{ __('LEGAL') }}</p>
                <ul class="flex flex-col gap-2 text-sm">
                    <li>
                        <a href="{{route('privacy-policy')}}" class="text-cream-200/70 hover:text-cream-100">{{ __('Privacy Policy') }}</a>
                    </li>
                    <li>
                        <a href="{{route('terms-of-service')}}" class="text-cream-200/70 hover:text-cream-100">{{ __('Terms of Service') }}</a>
                    </li>
                </ul>
            </div>
            <div>
                <p class="font-mono text-[10px] tracking-[0.16em] text-cream-200/50 mb-4">{{ __('CONTACT') }}</p>
                <a href="mailto:hello@flexpick.net" class="text-cream-200/70 hover:text-cream-100 text-sm">hello@flexpick.net</a>
            </div>
        </div>
        <hr class="my-8 border-white/5" />
        <div class="sm:flex sm:items-center sm:justify-between">
          <span class="text-xs text-cream-200/50 sm:text-center">© {{ date('Y') }} <a href="{{ config('app.frontend_url') }}" class="hover:underline text-cream-200/70">{{ config('app.name') }}™</a>. {{ __('All rights reserved.') }}
          </span>
            <div class="flex gap-3 mt-4 sm:justify-center sm:mt-0">
                @if (!empty(config('app.social_links.facebook')))
                    <x-link.social-icon name="facebook" title="{{ __('Facebook page') }}" link="{{config('app.social_links.facebook')}}" class="text-cream-200/60 border-white/10 hover:text-cream-100"/>
                @endif
                @if (!empty(config('app.social_links.instagram')))
                    <x-link.social-icon name="instagram" title="{{ __('Instagram page') }}" link="{{config('app.social_links.instagram')}}" class="text-cream-200/60 border-white/10 hover:text-cream-100"/>
                @endif
                @if (!empty(config('app.social_links.youtube')))
                    <x-link.social-icon name="youtube" title="{{ __('YouTube channel') }}" link="{{config('app.social_links.youtube')}}" class="text-cream-200/60 border-white/10 hover:text-cream-100"/>
                @endif
                @if (!empty(config('app.social_links.x')))
                    <x-link.social-icon name="x" title="{{ __('Twitter page') }}" link="{{config('app.social_links.x')}}" class="text-cream-200/60 border-white/10 hover:text-cream-100"/>
                @endif
                @if (!empty(config('app.social_links.linkedin')))
                    <x-link.social-icon name="linkedin" title="{{ __('Linkedin page') }}" link="{{config('app.social_links.linkedin')}}" class="text-cream-200/60 border-white/10 hover:text-cream-100"/>
                @endif
                @if (!empty(config('app.social_links.github')))
                    <x-link.social-icon name="github" title="{{ __('Github page') }}" link="{{config('app.social_links.github')}}" class="text-cream-200/60 border-white/10 hover:text-cream-100"/>
                @endif
                @if (!empty(config('app.social_links.discord')))
                    <x-link.social-icon name="discord" title="{{ __('Discord community') }}" link="{{config('app.social_links.discord')}}" class="text-cream-200/60 border-white/10 hover:text-cream-100"/>
                @endif
            </div>
        </div>
    </div>
</footer>
```

- [ ] **Step 7: Restyle nav items and the simple layout**

Replace the full contents of `backend/resources/views/components/nav/item.blade.php`:

```blade
@props(['route' => '#'])

@php($selected = request()->routeIs($route))
@php($selectedClass = $selected ? 'text-cream-100' : 'text-cream-200/60')

<li {{ $attributes }}>
    <a href="{{ str_starts_with($route, '#') ? (route('home') . $route) : route($route) }}" class="font-mono text-xs tracking-[0.12em] uppercase block py-2 px-3 md:p-0 rounded hover:text-cream-100 transition-colors {{ $selectedClass }}">
        {{ $slot }}
    </a>
</li>
```

In `backend/resources/views/components/layouts/simple.blade.php`, change the body tag:

```blade
<body class="text-primary-900" x-data>
```

to:

```blade
<body class="bg-ink text-cream-200 font-sans" x-data>
```

- [ ] **Step 8: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --compact --filter=LayoutBrandingTest`
Expected: PASS.

Run: `docker compose exec laravel.test php artisan test --compact tests/Feature/Http/Controllers`
Expected: PASS — pricing/home/legal page tests unaffected.

- [ ] **Step 9: Build and commit**

```bash
docker compose exec laravel.test npm run build
git add backend/resources/views/components/wordmark.blade.php backend/config/app.php backend/.env.example backend/resources/views/components/layouts/app.blade.php backend/resources/views/components/layouts/app/header.blade.php backend/resources/views/components/layouts/app/footer.blade.php backend/resources/views/components/nav/item.blade.php backend/resources/views/components/layouts/simple.blade.php backend/tests/Feature/Http/LayoutBrandingTest.php
git commit -m "feat(backend): dark landing-style app layout, header, footer, and FlexPick wordmark"
```

---

### Task 3: `focus` and `focus-center` layouts (auth + checkout shells)

**Files:**
- Modify: `backend/resources/views/components/layouts/focus.blade.php` (full rewrite)
- Modify: `backend/resources/views/components/layouts/focus-center.blade.php` (full rewrite)
- Test: `backend/tests/Feature/Http/LayoutBrandingTest.php` (extend)

**Interfaces:**
- Consumes: `<x-wordmark />` from Task 2; Task 1 utilities.
- Produces: restyled shells for login, register, password email/reset/confirm, 2FA (focus) and checkout, thank-you, verify, invitations (focus-center). Slots `$left`, `$right`, `$slot`, and the `backButton` prop keep their exact names.

- [ ] **Step 1: Extend the failing smoke test**

Append to `backend/tests/Feature/Http/LayoutBrandingTest.php` (inside the class):

```php
    public function test_focus_layout_pages_render_flexpick_branding_on_dark_canvas(): void
    {
        foreach ([route('login'), route('register'), route('password.request')] as $url) {
            $response = $this->get($url);

            $response->assertOk();
            $response->assertSee('data-brand="flexpick"', false);
            $response->assertSee('bg-ink', false);
        }
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --compact --filter=LayoutBrandingTest`
Expected: FAIL — the new test can't find `data-brand="flexpick"` on the login page; the Task 2 test still passes.

- [ ] **Step 3: Rewrite the focus layout**

Replace the full contents of `backend/resources/views/components/layouts/focus.blade.php`:

```blade
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- CSRF Token -->
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @include('components.layouts.partials.head')
</head>
<body class="bg-ink text-cream-200 font-sans">
    <div id="app">

        <div class="w-full">
            <div class="flex flex-col-reverse flex-wrap md:flex-nowrap md:flex-row md:min-h-screen">
                 <div class="md:basis-3/5 flex flex-col">
                     <div class="hidden md:block p-6">
                         <a href="{{route('home')}}">
                            <x-wordmark />
                         </a>
                     </div>

                     {{$left}}
                 </div>
                <div class="md:basis-2/5 md:min-h-screen bg-white/2 border-s border-white/5 flex flex-col">
                    <div class="flex justify-between md:justify-end">
                        <div class="md:hidden p-6">
                            <a href="{{route('home')}}">
                                <x-wordmark />
                            </a>
                        </div>

                        <div class="self-end m-4">
                            <x-link href="{{route('home')}}" class="flex items-center font-mono text-[11px] tracking-[0.12em] uppercase text-cream-200/50 hover:text-cream-100">{{__('< back home')}}</x-link>
                        </div>
                    </div>

                    <div class="hidden md:flex items-center gap-3 md:px-12 pt-20">
                        <span class="w-10 h-0.5 bg-primary-500"></span>
                        <span class="font-mono text-[11px] tracking-[0.14em] text-cream-200/50">{{ __('FLEXPICK') }}</span>
                    </div>

                    {{$right}}
                </div>
            </div>
        </div>

        @include('components.layouts.partials.tail')
    </div>
</body>
</html>
```

- [ ] **Step 4: Rewrite the focus-center layout**

Replace the full contents of `backend/resources/views/components/layouts/focus-center.blade.php`:

```blade
@props(['backButton' => true])

<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- CSRF Token -->
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @include('components.layouts.partials.head')
</head>
<body {{ $attributes->merge(['class' => 'bg-ink text-cream-200 font-sans w-full']) }} >
    <div id="app">

        <div class="flex justify-between">
            <a href="{{route('home')}}" class="inline-block m-6">
                <x-wordmark />
            </a>

            @if($backButton)
                <div class="self-end m-4">
                    <x-link href="{{route('home')}}" class="flex items-center font-mono text-[11px] tracking-[0.12em] uppercase text-cream-200/50 hover:text-cream-100">{{__('<< back')}}</x-link>
                </div>
            @endif
        </div>

        <div>
            {{$slot}}
        </div>

        @include('components.layouts.partials.tail', ['skipCookieContentBar' => true])
    </div>
</body>
</html>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --compact --filter=LayoutBrandingTest`
Expected: PASS (both test methods).

Run: `docker compose exec laravel.test php artisan test --compact tests/Feature/Http/Controllers/Auth tests/Feature/Livewire/Auth`
Expected: PASS — auth flows (login, register, passwords, OTP, 2FA) unaffected.

- [ ] **Step 6: Build and commit**

```bash
docker compose exec laravel.test npm run build
git add backend/resources/views/components/layouts/focus.blade.php backend/resources/views/components/layouts/focus-center.blade.php backend/tests/Feature/Http/LayoutBrandingTest.php
git commit -m "feat(backend): dark focus and focus-center layouts for auth and checkout shells"
```

---

### Task 4: Shared component restyle (buttons, headings, form styling)

**Files:**
- Modify: `backend/resources/views/components/button-link/default.blade.php`
- Modify: `backend/resources/views/components/button-link/primary.blade.php`
- Modify: `backend/resources/views/components/button-link/secondary.blade.php`
- Modify: `backend/resources/views/components/input/field.blade.php:8-9` (default prop classes)
- Modify: `backend/resources/css/styles.css` (append a components layer)

**Interfaces:**
- Consumes: Task 1 tokens.
- Produces: gold/outline button variants and dark form styling used by every page (auth forms, checkout, pricing CTAs). No component prop or slot changes — only classes.

- [ ] **Step 1: Restyle the button components**

Replace the full contents of `backend/resources/views/components/button-link/default.blade.php`:

```blade
@props(['elementType' => 'a', 'isDisabled' => false])

@php
    $class = 'inline-block cursor-pointer leading-6 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500/70 rounded-lg text-sm font-semibold px-5 py-2.5 text-center transition ';
@endphp

@if($elementType === 'a')
<a
    {{ $attributes->merge(['class' => $class]) }}
    {{ $attributes }}
    {{ $isDisabled ? 'disabled' : '' }}
>
    {{ $slot }}
</a>
@else
<button
    {{ $attributes->merge(['class' => $class]) }}
    {{ $attributes }}
    {{ $isDisabled ? 'disabled' : '' }}
>
    {{ $slot }}
</button>
@endif
```

Replace the full contents of `backend/resources/views/components/button-link/primary.blade.php`:

```blade
<x-button-link.default
    {{ $attributes }}
    {{ $attributes->merge(['class' => 'text-ink bg-primary-500 hover:bg-primary-400']) }}
>
    {{ $slot }}
</x-button-link.default>
```

Replace the full contents of `backend/resources/views/components/button-link/secondary.blade.php`:

```blade
<x-button-link.default
    {{ $attributes }}
    {{ $attributes->merge(['class' => 'text-cream-100 bg-transparent border border-white/15 hover:border-primary-500/60']) }}
>
    {{ $slot }}
</x-button-link.default>
```

- [ ] **Step 2: Fix the input component's light defaults**

In `backend/resources/views/components/input/field.blade.php` lines 8–9, change:

```blade
    'labelClass' => 'text-gray-900',
    'inputClass' => 'text-gray-900 bg-primary-50',
```

to:

```blade
    'labelClass' => 'text-cream-200',
    'inputClass' => 'text-cream-100 bg-white/5',
```

- [ ] **Step 3: Add the shared dark form/heading styling**

Append to the end of `backend/resources/css/styles.css`:

```css
/* ===== FlexPick landing design language (public pages) ===== */
@layer base {
    h1, h2, h3 {
        font-family: var(--font-heading);
        color: var(--color-cream-100);
    }
}

@layer components {
    /* Landing-style form fields — mirrors the landing's .fp-input spec */
    #app input.input,
    #app select.select,
    #app textarea.textarea {
        background: rgba(255, 255, 255, 0.04);
        border: 1px solid rgba(255, 255, 255, 0.12);
        color: var(--color-cream-100);
        border-radius: 0.5rem;
        transition: border-color 0.2s;
    }

    #app input.input:focus,
    #app select.select:focus,
    #app textarea.textarea:focus {
        outline: 2px solid rgba(212, 168, 83, 0.7);
        outline-offset: 2px;
        border-color: rgba(212, 168, 83, 0.5);
    }

    /* Landing-style field labels — mirrors .fp-label */
    #app .fieldset-legend {
        font-family: var(--font-mono);
        font-size: 10px;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: rgba(232, 230, 222, 0.5);
    }

    /* Inline links — landing uses gold */
    #app .link {
        color: var(--color-primary-500);
        text-decoration: none;
    }

    #app .link:hover {
        color: var(--color-primary-400);
        text-decoration: underline;
    }
}
```

(The `#app` scope keeps these rules off the Filament dashboard, whose bundle compiles separately, and off the report pages, which define their own styles and have no `#app` wrapper.)

- [ ] **Step 4: Build and run the behavior suites that render forms**

Run: `docker compose exec laravel.test npm run build`
Expected: build succeeds.

Run: `docker compose exec laravel.test php artisan test --compact tests/Feature/Http/Controllers tests/Feature/Livewire`
Expected: PASS — component class changes cannot break behavior tests; this catches Blade syntax errors.

- [ ] **Step 5: Commit**

```bash
git add backend/resources/views/components/button-link backend/resources/views/components/input/field.blade.php backend/resources/css/styles.css
git commit -m "feat(backend): gold button variants and dark form styling for public pages"
```

---

### Task 5: Page-level cleanup sweep

Individual pages still carry light-theme utilities that fight the dark canvas. This is a mechanical sweep with an exact substitution map.

**Files:**
- Modify (as found by the grep in Step 1, expected set): `backend/resources/views/auth/login.blade.php`, `auth/register.blade.php`, `auth/2fa.blade.php`, `auth/verify.blade.php`, `auth/thank-you.blade.php`, `auth/passwords/email.blade.php`, `auth/passwords/reset.blade.php`, `auth/passwords/confirm.blade.php`, `pricing.blade.php`, `checkout/*.blade.php`, `subscription/*.blade.php`, `verify/*.blade.php`, `invitations/*.blade.php`, `pages/*.blade.php`, `audit/status.blade.php`

**Interfaces:**
- Consumes: Task 1 tokens, Task 3 layouts.
- Produces: no new symbols — a clean grep (Step 4) is the deliverable.

- [ ] **Step 1: Find every offending utility**

Run from `backend/`:

```bash
grep -rn "shadow-xl\|text-primary-900\|text-primary-800\|text-red-500\|bg-white \|bg-white\"\|text-gray-900\|text-gray-800" resources/views/auth resources/views/checkout resources/views/subscription resources/views/verify resources/views/invitations resources/views/pages resources/views/pricing.blade.php resources/views/audit 2>/dev/null
```

Expected: a hit list of files and lines (roughly 15–30 hits).

- [ ] **Step 2: Apply the substitution map to every hit**

| Old class | New class | Rationale |
|---|---|---|
| `shadow-xl` (on `card`/`bg-base-100`) | `border border-white/10` | landing cards use borders, not shadows |
| `text-primary-900` | `text-cream-100` | dark-on-dark otherwise |
| `text-primary-800` | `text-cream-200` | same |
| `text-red-500` | `text-error` | daisyUI semantic error color (coral) |
| `bg-white` | `bg-base-100` | dark card surface |
| `text-gray-900` / `text-gray-800` | `text-cream-100` | dark-on-dark otherwise |

Example — `backend/resources/views/auth/login.blade.php` line 4 changes from:

```blade
            <div class="card w-full md:max-w-xl bg-base-100 shadow-xl p-4 md:p-8">
```

to:

```blade
            <div class="card w-full md:max-w-xl bg-base-100 border border-white/10 p-4 md:p-8">
```

Apply mechanically to every hit from Step 1. Do NOT touch `resources/views/reports/`, `resources/views/emails/`, `resources/views/errors/`, `resources/views/filament/`, or `resources/views/components/layouts/email.blade.php` even if they appear in a broader search — they are out of scope.

- [ ] **Step 3: Handle the pricing page heading**

In `backend/resources/views/pricing.blade.php`, the heading block becomes:

```blade
        <x-heading.h6 class="text-center mt-10 text-primary-500">
            {{ __('Plans & Pricing') }}
        </x-heading.h6>
        <x-heading.h2 class="text-cream-100 text-center">
            {{ __('Pick the plan that fits') }}
        </x-heading.h2>
```

(Only `text-primary-900` → `text-cream-100` changes; the gold `text-primary-500` eyebrow stays.)

- [ ] **Step 4: Verify the sweep is complete**

Re-run the Step 1 grep.
Expected: zero hits.

Run: `docker compose exec laravel.test php artisan test --compact`
Expected: full suite PASS.

- [ ] **Step 5: Build and commit**

```bash
docker compose exec laravel.test npm run build
git add backend/resources/views
git commit -m "fix(backend): sweep light-theme utilities from public pages for the dark canvas"
```

---

### Task 6: Filament user dashboard brand alignment

**Files:**
- Create: `backend/public/images/flexpick-wordmark.svg`
- Modify: `backend/app/Providers/Filament/DashboardPanelProvider.php:43-45,83`
- Modify: `backend/resources/css/filament/dashboard/theme.css` (daisyUI theme swap)
- Test: `backend/tests/Feature/Filament/DashboardPanelBrandingTest.php` (new)

**Interfaces:**
- Consumes: `Color::hex()` (`Filament\Support\Colors\Color`), `ThemeMode` (`Filament\Enums\ThemeMode`), `flexpick-daisyui.css` from Task 1.
- Produces: dashboard panel with FlexPick branding. Admin panel (`AdminPanelProvider`) untouched.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Feature/Filament/DashboardPanelBrandingTest.php`:

```php
<?php

namespace Tests\Feature\Filament;

use Filament\Enums\ThemeMode;
use Filament\Facades\Filament;
use Tests\Feature\FeatureTest;

class DashboardPanelBrandingTest extends FeatureTest
{
    public function test_dashboard_panel_is_flexpick_branded(): void
    {
        $panel = Filament::getPanel('dashboard');

        $this->assertSame('FlexPick', $panel->getBrandName());
        $this->assertSame(ThemeMode::Dark, $panel->getDefaultThemeMode());
        $this->assertStringContainsString('flexpick-wordmark.svg', (string) $panel->getBrandLogo());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec laravel.test php artisan test --compact --filter=DashboardPanelBrandingTest`
Expected: FAIL — brand name is null/`Laravel`, theme mode is `ThemeMode::System`.

- [ ] **Step 3: Create the wordmark SVG**

Create `backend/public/images/flexpick-wordmark.svg`:

```svg
<svg xmlns="http://www.w3.org/2000/svg" width="120" height="28" viewBox="0 0 120 28" role="img" aria-label="FlexPick">
  <text x="0" y="21" font-family="Syne, Arial, sans-serif" font-size="20" font-weight="800" fill="#f5f5f0">Flex<tspan fill="#d4a853">Pick</tspan></text>
</svg>
```

- [ ] **Step 4: Brand the panel**

In `backend/app/Providers/Filament/DashboardPanelProvider.php`, add to the imports:

```php
use Filament\Enums\ThemeMode;
```

Replace lines 43–45:

```php
            ->colors([
                'primary' => Color::Teal,
            ])
```

with:

```php
            ->colors([
                'primary' => Color::hex('#d4a853'),
            ])
            ->brandName('FlexPick')
            ->brandLogo(asset('images/flexpick-wordmark.svg'))
            ->brandLogoHeight('1.75rem')
            ->defaultThemeMode(ThemeMode::Dark)
            ->font('DM Sans')
```

In `backend/resources/css/filament/dashboard/theme.css`, replace:

```css
@plugin "daisyui" {
    themes: light --default;
}
```

with:

```css
@plugin "daisyui" {
    themes: false;
}
@import '../../flexpick-daisyui.css';
```

(Same daisyUI 5 pattern as Task 1: built-ins disabled, custom theme self-registers as default.)

Note on the spec's "favicon matching the landing": the panel already sets `->favicon(asset('images/favicon.ico'))` and the landing ships no favicon asset of its own, so the existing favicon stays — nothing to copy.

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose exec laravel.test php artisan test --compact --filter=DashboardPanelBrandingTest`
Expected: PASS.

Run: `docker compose exec laravel.test php artisan test --compact tests/Feature/Filament`
Expected: PASS — existing dashboard/admin Filament tests unaffected.

- [ ] **Step 6: Format, build, and commit**

```bash
docker compose exec laravel.test vendor/bin/pint app/Providers/Filament/DashboardPanelProvider.php tests/Feature/Filament/DashboardPanelBrandingTest.php
docker compose exec laravel.test npm run build
git add backend/public/images/flexpick-wordmark.svg backend/app/Providers/Filament/DashboardPanelProvider.php backend/resources/css/filament/dashboard/theme.css backend/tests/Feature/Filament/DashboardPanelBrandingTest.php
git commit -m "feat(backend): FlexPick brand alignment for the Filament user dashboard"
```

---

### Task 7: Full regression gate and visual pass

**Files:** none (verification only).

**Interfaces:**
- Consumes: everything above.
- Produces: verified branch.

- [ ] **Step 1: Full backend suite and static analysis**

Run: `docker compose exec laravel.test php artisan test --compact`
Expected: PASS, 0 failures.

Run: `docker compose exec laravel.test vendor/bin/phpstan analyse`
Expected: no new errors.

- [ ] **Step 2: Production asset build**

Run: `docker compose exec laravel.test npm run build`
Expected: clean build of both bundles (`app.css` and `filament/dashboard/theme.css`).

- [ ] **Step 3: Manual visual pass (browser, logged out and logged in, ~375px and ~1280px widths)**

Walk the full inventory at `http://localhost:8080`; every page must show the dark canvas, cream text, gold accents, wordmark, and readable forms:

1. `/login`, `/register`, `/password/reset` (request + emailed reset form), password confirm, `/2fa` (enable 2FA on a demo user)
2. `/email/verify`, `/registration/thank-you`
3. `/pricing` (plan cards, features, CTAs)
4. `/checkout/plan/audit-starter-monthly` (as `audit-free-demo@flexpick.net`), product checkout via `/buy/product/audit-report-unlock`
5. Subscription change page (as `audit-starter-demo@flexpick.net`), already-subscribed page
6. `/terms-of-service`, `/privacy-policy`, `/invitations`, audit status page (from a seeded audit request)
7. `/dashboard` — dark Filament with gold primary and FlexPick wordmark; `/admin` — unchanged stock look
8. `/reports/sample` — still the light document design

Fix any page that renders unreadable (dark-on-dark / light remnants) using the Task 5 substitution map, re-run the Task 5 grep, and amend to the Task 5 commit or commit separately as `fix(backend): visual pass fixes`.

- [ ] **Step 4: Report**

Summarize: pages verified, fixes applied during the visual pass, any follow-ups deferred.
