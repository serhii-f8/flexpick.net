<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Constants\SubscriptionStatus;
use App\Filament\Dashboard\Widgets\AuditStatsWidget;
use App\Models\AuditRequest;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class AuditStatsWidgetTest extends FeatureTest
{
    public function test_subscriber_sees_status_counts(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $this->createActiveSubscriptionFor($tenant, $user, ['audit_analyses_per_month' => 5]);

        // statuses: 1 in progress, 1 completed, 1 failed
        AuditRequest::factory()->dashboardSource()->create(['user_id' => $user->id, 'tier' => AuditTier::AUTOMATED->value, 'status' => AuditRequestStatus::ANALYZING->value]);
        AuditRequest::factory()->dashboardSource()->create(['user_id' => $user->id, 'tier' => AuditTier::AUTOMATED->value, 'status' => AuditRequestStatus::SENT->value]);
        AuditRequest::factory()->create(['user_id' => $user->id, 'status' => AuditRequestStatus::FAILED->value, 'source' => 'web']);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::actingAs($user)
            ->test(AuditStatsWidget::class)
            ->assertSee(__('In progress'))
            ->assertSee(__('Completed'))
            ->assertSee(__('Failed'));
    }

    public function test_zero_count_buckets_are_not_rendered(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        AuditRequest::factory()->create([
            'user_id' => $user->id,
            'status' => AuditRequestStatus::ANALYZING->value,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::test(AuditStatsWidget::class)
            ->assertSee(__('In progress'))
            ->assertDontSee(__('Failed'));
    }

    public function test_quota_stats_are_no_longer_rendered_here(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::test(AuditStatsWidget::class)
            ->assertDontSee(__('Free audits remaining'));
    }

    public function test_visible_for_fresh_user_with_only_free_runs(): void
    {
        config(['audit.free_reports_limit' => 3]);
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        $this->assertTrue(AuditStatsWidget::canView());
    }

    /**
     * The production default is zero free runs, so a fresh signup clears no
     * quota arm -- but every tier is priced, and being able to buy one is
     * itself access.
     */
    public function test_visible_for_a_fresh_signup_at_the_production_default(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        $this->assertTrue(AuditStatsWidget::canView());
    }

    public function test_hidden_without_audits_allowance_free_runs_or_a_buyable_tier(): void
    {
        // An empty catalog is what makes this a real negative now: with one,
        // any authenticated user can always reach a purchase.
        config(['audit.free_reports_limit' => 0, 'pricing.tiers' => []]);
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        $this->assertFalse(AuditStatsWidget::canView());
    }

    private function createTenantFor(User $user): Tenant
    {
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user);

        return $tenant;
    }

    private function createActiveSubscriptionFor(Tenant $tenant, User $user, array $productMetadata): Subscription
    {
        $product = Product::factory()->create(['metadata' => $productMetadata]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);

        return Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);
    }
}
