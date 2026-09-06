<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\TenancyPermissionConstants;
use App\Filament\Dashboard\Pages\AuditReports;
use App\Filament\Dashboard\Resources\AuditRequests\AuditRequestResource;
use App\Filament\Dashboard\Resources\Orders\OrderResource;
use App\Filament\Dashboard\Resources\Subscriptions\SubscriptionResource;
use App\Filament\Dashboard\Resources\Transactions\TransactionResource;
use Filament\Facades\Filament;
use Tests\Feature\FeatureTest;

class DashboardNavigationTest extends FeatureTest
{
    public function test_a_permitted_customer_sees_orders_subscriptions_and_transactions_in_the_nav(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, [
            TenancyPermissionConstants::PERMISSION_VIEW_ORDERS,
            TenancyPermissionConstants::PERMISSION_VIEW_SUBSCRIPTIONS,
            TenancyPermissionConstants::PERMISSION_VIEW_TRANSACTIONS,
        ]);

        $this->actingAs($user);
        Filament::setTenant($tenant);

        config()->set('app.customer_dashboard.show_orders', true);
        config()->set('app.customer_dashboard.show_subscriptions', true);
        config()->set('app.customer_dashboard.show_transactions', true);

        // shouldRegisterNavigation() proves the hardcoded override is gone.
        $this->assertTrue(OrderResource::shouldRegisterNavigation());
        $this->assertTrue(SubscriptionResource::shouldRegisterNavigation());
        $this->assertTrue(TransactionResource::shouldRegisterNavigation());

        // canAccess() is the second gate registerNavigationItems() consults;
        // both must pass for the sidebar item to appear, so assert both.
        $this->assertTrue(OrderResource::canAccess());
        $this->assertTrue(SubscriptionResource::canAccess());
        $this->assertTrue(TransactionResource::canAccess());
    }

    public function test_the_config_switch_still_hides_them(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, [
            TenancyPermissionConstants::PERMISSION_VIEW_ORDERS,
            TenancyPermissionConstants::PERMISSION_VIEW_SUBSCRIPTIONS,
            TenancyPermissionConstants::PERMISSION_VIEW_TRANSACTIONS,
        ]);

        $this->actingAs($user);
        Filament::setTenant($tenant);

        config()->set('app.customer_dashboard.show_orders', false);
        config()->set('app.customer_dashboard.show_subscriptions', false);
        config()->set('app.customer_dashboard.show_transactions', false);

        $this->assertFalse(OrderResource::canAccess());
        $this->assertFalse(SubscriptionResource::canAccess());
        $this->assertFalse(TransactionResource::canAccess());
    }

    public function test_a_customer_without_the_permissions_cannot_access_them(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant, []);

        $this->actingAs($user);
        Filament::setTenant($tenant);

        config()->set('app.customer_dashboard.show_orders', true);
        config()->set('app.customer_dashboard.show_subscriptions', true);
        config()->set('app.customer_dashboard.show_transactions', true);

        $this->assertFalse(OrderResource::canAccess());
        $this->assertFalse(SubscriptionResource::canAccess());
        $this->assertFalse(TransactionResource::canAccess());
    }

    public function test_the_audit_nav_items_use_task_names_instead_of_two_labels_that_both_mean_audits(): void
    {
        $this->assertSame('Run an audit', AuditReports::getNavigationLabel());
        $this->assertSame('Audit history', AuditRequestResource::getNavigationLabel());
    }

    public function test_billing_resources_sit_together_in_a_billing_group_below_audits(): void
    {
        $this->assertSame('Billing', OrderResource::getNavigationGroup());
        $this->assertSame('Billing', SubscriptionResource::getNavigationGroup());
        $this->assertSame('Billing', TransactionResource::getNavigationGroup());

        $groups = array_keys(Filament::getPanel('dashboard')->getNavigationGroups());

        $this->assertSame(['Audits', 'Billing', 'Team Management', 'Referrals'], $groups);
    }
}
