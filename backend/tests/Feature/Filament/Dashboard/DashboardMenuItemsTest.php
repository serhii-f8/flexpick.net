<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\SubscriptionStatus;
use App\Constants\TenancyPermissionConstants;
use App\Filament\Dashboard\Pages\AuditReports;
use App\Filament\Dashboard\Pages\Dashboard;
use App\Filament\Dashboard\Pages\PartnerPricingSettings;
use App\Filament\Dashboard\Pages\Users;
use App\Filament\Dashboard\Resources\AuditRequests\AuditRequestResource;
use App\Filament\Dashboard\Resources\Invitations\InvitationResource;
use App\Filament\Dashboard\Resources\Orders\OrderResource;
use App\Filament\Dashboard\Resources\PartnerOrders\PartnerOrderResource;
use App\Filament\Dashboard\Resources\Referrals\ReferralResource;
use App\Filament\Dashboard\Resources\Subscriptions\SubscriptionResource;
use App\Filament\Dashboard\Resources\Transactions\TransactionResource;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use Filament\Facades\Filament;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\FeatureTest;

/**
 * Covers every item a tenant user actually sees in the customer dashboard
 * sidebar: that it is registered in the expected group, and that its target
 * page really loads.
 *
 * The page-load half is the part that earns its keep. A Filament resource can
 * register a navigation item perfectly while its list page fatals on a null
 * relation -- exactly the failure mode found on the public pricing page, where
 * an unguarded `$plan->meter->name` 500'd a page whose nav entry was fine.
 * Asserting registration alone would not have caught it.
 */
class DashboardMenuItemsTest extends FeatureTest
{
    /**
     * Every permission the dashboard's canAccess() gates consult, so this user
     * sees the full menu rather than a permission-trimmed subset.
     */
    private function fullyPermittedUserAndTenant(): array
    {
        // Both switches default off outside dev, and each independently removes
        // menu items: canAccess() on the Billing resources reads
        // customer_dashboard.show_*, and the two Referral resources read
        // app.referral.enabled. Stated here rather than inherited, because
        // FeatureTest shares one database across the whole suite run.
        config()->set('app.customer_dashboard.show_orders', true);
        config()->set('app.customer_dashboard.show_subscriptions', true);
        config()->set('app.customer_dashboard.show_transactions', true);
        config()->set('app.referral.enabled', true);

        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, [
            TenancyPermissionConstants::PERMISSION_VIEW_ORDERS,
            TenancyPermissionConstants::PERMISSION_VIEW_SUBSCRIPTIONS,
            TenancyPermissionConstants::PERMISSION_VIEW_TRANSACTIONS,
            TenancyPermissionConstants::PERMISSION_MANAGE_TEAM,
            TenancyPermissionConstants::PERMISSION_INVITE_MEMBERS,
            TenancyPermissionConstants::PERMISSION_VIEW_ROLES,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        return [$user, $tenant];
    }

    /**
     * @return array<string, array<int, string>> group label => item labels
     */
    private function renderedNavigation(): array
    {
        $navigation = [];

        foreach (Filament::getNavigation() as $group) {
            $label = $group->getLabel() ?: '';

            foreach ($group->getItems() as $item) {
                $navigation[$label][] = $item->getLabel();
            }
        }

        return $navigation;
    }

    public function test_the_sidebar_shows_every_expected_group_and_item(): void
    {
        $this->fullyPermittedUserAndTenant();

        $navigation = $this->renderedNavigation();

        $this->assertSame(['Dashboard'], $navigation[''] ?? []);
        $this->assertSame(['Run an audit', 'Audit history'], $navigation['Audits'] ?? []);
        $this->assertSame(['Orders', 'Subscriptions', 'Payments'], $navigation['Billing'] ?? []);
        $this->assertArrayNotHasKey('Referrals', $navigation);
        $this->assertArrayNotHasKey('Partner', $navigation);
        $this->assertSame(['Users', 'Invitations'], $navigation['Team Management'] ?? []);
    }

    /**
     * The menu must not offer a link that errors when followed.
     *
     * @return array<string, array{0: class-string}>
     */
    public static function menuTargetProvider(): array
    {
        return [
            'Dashboard' => [Dashboard::class],
            'Run an audit' => [AuditReports::class],
            'Audit history' => [AuditRequestResource::class],
            'Orders' => [OrderResource::class],
            'Subscriptions' => [SubscriptionResource::class],
            'Payments' => [TransactionResource::class],
            'Users' => [Users::class],
            'Invitations' => [InvitationResource::class],
        ];
    }

    #[DataProvider('menuTargetProvider')]
    public function test_each_menu_item_page_loads(string $target): void
    {
        [, $tenant] = $this->fullyPermittedUserAndTenant();

        $this->get($target::getUrl(tenant: $tenant))->assertSuccessful();
    }

    /**
     * The counterpart to the item list: a user holding none of the tenancy
     * permissions must not be offered Billing or Team Management at all.
     * Without this, the group assertions above could pass while the gates were
     * dead, since every gate is consulted only when a permission is missing.
     */
    public function test_an_unpermitted_user_is_not_offered_the_gated_groups(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, []);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        $navigation = $this->renderedNavigation();

        $this->assertArrayNotHasKey('Billing', $navigation);
        $this->assertArrayNotHasKey('Team Management', $navigation);
        $this->assertSame(['Dashboard'], $navigation[''] ?? []);
    }

    /**
     * Tenancy is the boundary that matters most here: a menu item resolved for
     * one tenant must not render another tenant's workspace.
     */
    public function test_a_user_cannot_load_a_menu_page_for_a_tenant_they_do_not_belong_to(): void
    {
        $this->withExceptionHandling();
        [, $ownTenant] = $this->fullyPermittedUserAndTenant();
        $foreignTenant = Tenant::factory()->create();

        $this->assertNotSame($ownTenant->id, $foreignTenant->id);

        // Filament resolves an unrelated tenant as a missing route binding, so
        // the boundary shows up as 404 rather than 403. Either way the page
        // must not render, which is what this asserts.
        $this->get(OrderResource::getUrl(tenant: $foreignTenant))->assertNotFound();
    }

    /** Same permissions as fullyPermittedUserAndTenant(), on a tenant with an active Partner plan. */
    private function partnerUserAndTenant(): array
    {
        [$user, $tenant] = $this->fullyPermittedUserAndTenant();

        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => Plan::factory()->create(['product_id' => $product->id])->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);

        foreach ([
            TenancyPermissionConstants::PERMISSION_MANAGE_PARTNER_ORDERS,
            TenancyPermissionConstants::PERMISSION_MANAGE_RESELLER_CATALOG,
        ] as $permission) {
            $user->tenants()->where('tenant_id', $tenant->id)->first()->pivot->givePermissionTo($permission);
        }

        return [$user, $tenant];
    }

    public function test_a_partner_sees_the_partner_group_and_no_rewards(): void
    {
        $this->partnerUserAndTenant();

        $navigation = $this->renderedNavigation();

        $this->assertSame(['Referrals', 'Orders', 'Pricing Settings'], $navigation['Partner'] ?? []);
        $this->assertSame(['Orders', 'Subscriptions', 'Payments'], $navigation['Billing'] ?? []);
    }

    public function test_the_referrals_page_loads_for_a_partner(): void
    {
        [, $tenant] = $this->partnerUserAndTenant();

        $this->get(ReferralResource::getUrl(tenant: $tenant))->assertSuccessful();
        $this->get(ReferralResource::getUrl(tenant: $tenant))->assertSee(__('Customers who sign up through this link buy at your prices.'));
    }

    public function test_each_partner_menu_item_page_loads(): void
    {
        [, $tenant] = $this->partnerUserAndTenant();

        $this->get(PartnerPricingSettings::getUrl(tenant: $tenant))->assertSuccessful();
        $this->get(PartnerOrderResource::getUrl(tenant: $tenant))->assertSuccessful();
    }
}
