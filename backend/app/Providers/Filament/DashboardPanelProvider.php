<?php

namespace App\Providers\Filament;

use App\Constants\AnnouncementPlacement;
use App\Constants\TenancyPermissionConstants;
use App\Filament\Dashboard\Pages\CreateWorkspace;
use App\Filament\Dashboard\Pages\Dashboard;
use App\Filament\Dashboard\Pages\TenantSettings;
use App\Filament\Dashboard\Pages\TwoFactorAuth\TwoFactorAuth;
use App\Filament\Dashboard\Resources\Subscriptions\SubscriptionResource;
use App\Http\Middleware\UpdateUserLastSeenAt;
use App\Livewire\AddressForm;
use App\Models\Tenant;
use App\Services\SubscriptionService;
use App\Services\TenantPermissionService;
use Filament\Actions\Action;
use Filament\Enums\ThemeMode;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Jeffgreco13\FilamentBreezy\BreezyCore;

class DashboardPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('dashboard')
            ->path('dashboard')
            ->colors([
                'primary' => Color::hex('#d4a853'),
                // Warm neutrals so Filament's own chrome (sidebar, topbar,
                // sections, tables) sits on the landing page's ink rather
                // than a cool slate. 950 is the landing background, 900 its
                // surface tint, 50 the light-mode paper.
                'gray' => [
                    50 => '#f8f6f1',
                    100 => '#f0ece4',
                    200 => '#e5dfd3',
                    300 => '#cfc7b8',
                    400 => '#a49b8c',
                    500 => '#7d7466',
                    600 => '#5b5347',
                    700 => '#3a332a',
                    800 => '#221d17',
                    900 => '#15120e',
                    950 => '#0b0a09',
                ],
                'danger' => Color::hex('#e2694a'),
                'warning' => Color::hex('#c98a3b'),
                'success' => Color::hex('#8fb573'),
                'info' => Color::hex('#7fb7d6'),
            ])
            ->brandName('FlexPick')
            ->brandLogo(asset('images/flexpick-wordmark-dark-text.svg'))
            ->darkModeBrandLogo(asset('images/flexpick-wordmark.svg'))
            ->brandLogoHeight('1.75rem')
            ->defaultThemeMode(ThemeMode::Dark)
            ->font('DM Sans')
            ->sidebarCollapsibleOnDesktop()
            ->databaseNotifications()
            ->maxContentWidth(Width::SevenExtraLarge)
            ->userMenuItems([
                Action::make('buy-more')
                    ->label(__('Buy More / Upgrade'))
                    ->icon('heroicon-s-shopping-cart')
                    ->url(fn () => self::upgradeUrl()),
                Action::make('admin-panel')
                    ->label(__('Admin Panel'))
                    ->visible(
                        fn () => auth()->user()->isAdmin()
                    )
                    ->url(fn () => route('filament.admin.pages.dashboard'))
                    ->icon('heroicon-s-cog-8-tooth'),
                Action::make('workspace-settings')
                    ->label(__('Workspace Settings'))
                    ->visible(
                        function () {
                            $tenantPermissionService = app(TenantPermissionService::class);

                            return $tenantPermissionService->tenantUserHasPermissionTo(
                                Filament::getTenant(),
                                auth()->user(),
                                TenancyPermissionConstants::PERMISSION_UPDATE_TENANT_SETTINGS
                            );
                        }
                    )
                    ->icon('heroicon-s-cog-8-tooth')
                    ->url(fn () => TenantSettings::getUrl()),
                Action::make('two-factor-auth')
                    ->label(__('2-Factor Authentication'))
                    ->visible(
                        fn () => config('app.two_factor_auth_enabled')
                    )
                    ->url(fn () => TwoFactorAuth::getUrl())
                    ->icon('heroicon-s-lock-closed'),
            ])
            ->discoverResources(in: app_path('Filament/Dashboard/Resources'), for: 'App\\Filament\\Dashboard\\Resources')
            ->discoverPages(in: app_path('Filament/Dashboard/Pages'), for: 'App\\Filament\\Dashboard\\Pages')
            ->pages([
                Dashboard::class,
                CreateWorkspace::class,
            ])
            ->favicon(asset('images/favicon.ico'))
            ->viteTheme('resources/css/filament/dashboard/theme.css')
            ->discoverWidgets(in: app_path('Filament/Dashboard/Widgets'), for: 'App\\Filament\\Dashboard\\Widgets')
            // No AccountWidget: sign-out lives in the user menu, and the home
            // page leads with codebase health instead of a greeting.
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                UpdateUserLastSeenAt::class,
            ])
            ->renderHook('panels::head.start', function () {
                return view('components.layouts.partials.analytics')->render().view('filament.partials.brand-fonts')->render();
            })
            ->navigationGroups([
                // Keyed by the exact label each resource declares
                // (getNavigationGroup() / $navigationGroup): Filament's sort
                // only matches a resource's group to its position in this
                // list via that exact string, via array_search() against
                // this array's keys. An unkeyed, sequential list (the
                // previous shape here) can't be found by that lookup, so
                // every resource's group silently fell through to the same
                // "not found" fallback sort value and ordered arbitrarily --
                // "Team Management" (Roles/Teams/Invitations/Users) rendered
                // above "Audits" despite Audits being listed first, because
                // "Team" (the old label here) matches no resource at all.
                //
                // No icon here on purpose: Filament throws if a navigation
                // group and its items both carry icons, and the items below it
                // have their own.
                'Audits' => NavigationGroup::make()
                    ->label(__('Audits')),
                'Billing' => NavigationGroup::make()
                    ->label(__('Billing'))
                    ->collapsed(),
                'Partner' => NavigationGroup::make()
                    ->label(__('Partner')),
                'Team Management' => NavigationGroup::make()
                    ->label(__('Team Management'))
                    ->collapsed(),
            ])
            ->navigationItems([
                // Plain link, not a resource -- lives in the same 'Billing'
                // group as Orders/Subscriptions/Transactions above, matching
                // its group() string to their $navigationGroup exactly for
                // the same lookup navigationGroups() relies on.
                NavigationItem::make(__('Buy More / Upgrade'))
                    ->group('Billing')
                    ->icon('heroicon-s-shopping-cart')
                    ->sort(-1)
                    ->url(fn () => self::upgradeUrl()),
            ])
            ->renderHook(
                PanelsRenderHook::BODY_START,
                fn (): string => Blade::render("@livewire('announcement.view', ['placement' => '".AnnouncementPlacement::USER_DASHBOARD->value."'])")
            )
            ->authMiddleware([
                Authenticate::class,
            ])->plugins([
                BreezyCore::make()
                    ->myProfile(
                        shouldRegisterUserMenu: true, // Sets the 'account' link in the panel User Menu (default = true)
                        shouldRegisterNavigation: false, // Adds a main navigation item for the My Profile page (default = false)
                        hasAvatars: false, // Enables the avatar upload form component (default = false)
                        slug: 'my-profile' // Sets the slug for the profile page (default = 'my-profile')
                    )
                    ->myProfileComponents([
                        AddressForm::class,
                    ]),
            ])
            ->tenantMenuItems([
                Action::make('create')
                    ->label(__('New Workspace'))
                    ->url(fn () => CreateWorkspace::getUrl())
                    ->icon('heroicon-o-plus-circle')
                    ->visible(fn () => config('app.allow_user_to_create_tenants_from_dashboard', false)),
            ])
            ->tenantMenu()
            ->tenant(Tenant::class, 'uuid');
    }

    /**
     * Routes to the in-place Change Plan page when the current tenant has an
     * active, self-service-eligible subscription -- a plain /pricing link
     * would otherwise silently create a second workspace for a tenant that
     * already has one (a tenant can only ever hold one active subscription;
     * see TenantCreationService::findUserTenantsForNewSubscription()).
     * Everyone else (no subscription yet, or one that isn't self-service --
     * e.g. cash/partner-managed) still goes to /pricing, which remains
     * correct for a first plan or a one-time credit top-up.
     */
    private static function upgradeUrl(): string
    {
        $subscription = app(SubscriptionService::class)->findChangeablePlanSubscription(Filament::getTenant());

        return $subscription !== null
            ? SubscriptionResource::getUrl('change-plan', ['record' => $subscription->uuid])
            : route('pricing');
    }
}
