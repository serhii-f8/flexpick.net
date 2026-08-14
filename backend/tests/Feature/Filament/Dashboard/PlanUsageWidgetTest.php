<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\SubscriptionStatus;
use App\Filament\Dashboard\Widgets\PlanUsageWidget;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;
use Tests\Support\CreatesAuditSubscriptions;

class PlanUsageWidgetTest extends FeatureTest
{
    use CreatesAuditSubscriptions;

    public function test_shows_plan_name_and_allowance_for_subscribed_tenant(): void
    {
        $user = User::factory()->create();
        $tenant = $this->tenantFor($user);
        $product = Product::factory()->create(['metadata' => ['audit_analyses_per_month' => 5]]);
        $plan = Plan::factory()->create(['product_id' => $product->id, 'name' => 'Studio']);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::test(PlanUsageWidget::class)
            ->assertSee('Studio')
            ->assertSee(__('Automated Health Report'));
    }

    public function test_shows_free_runs_for_user_without_subscription(): void
    {
        $user = User::factory()->create();
        $tenant = $this->tenantFor($user);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::test(PlanUsageWidget::class)
            ->assertSee(__('Free audits'))
            ->assertDontSee(__('Automated Health Report'));
    }

    public function test_hidden_without_any_entitlement(): void
    {
        config(['audit.free_reports_limit' => 0]);
        $user = User::factory()->create();
        $tenant = $this->tenantFor($user);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        $this->assertFalse(PlanUsageWidget::canView());
    }

    public function test_a_bar_is_shown_for_every_tier_with_an_allowance(): void
    {
        [$user, $tenant] = $this->userWithAllowance(analyses: 20, deepAi: 1, expert: 1);
        $this->actAsTenantUser($user, $tenant);

        Livewire::test(PlanUsageWidget::class)
            ->assertSee('Automated Health Report')
            ->assertSee('Deep AI Code Review')
            ->assertSee('Expert Audit');
    }

    public function test_a_tier_with_no_allowance_is_not_advertised(): void
    {
        [$user, $tenant] = $this->userWithAllowance(analyses: 20, deepAi: 0, expert: 0);
        $this->actAsTenantUser($user, $tenant);

        Livewire::test(PlanUsageWidget::class)
            ->assertSee('Automated Health Report')
            ->assertDontSee('Deep AI Code Review')
            ->assertDontSee('Expert Audit');
    }

    /**
     * The lost arm from the widget's original showUpgrade expression: a free
     * user (no subscription) with free runs remaining used to see Upgrade,
     * and must again -- it is the conversion surface for exactly this
     * segment, and the "Free audits" bar (rendered because $bars is empty
     * otherwise) would offer no call to action without it.
     */
    public function test_free_user_with_runs_remaining_sees_upgrade(): void
    {
        $user = User::factory()->create();
        $tenant = $this->tenantFor($user);
        $this->actAsTenantUser($user, $tenant);

        Livewire::test(PlanUsageWidget::class)
            ->assertSee(__('Upgrade'));
    }

    public function test_subscribed_user_with_allowance_remaining_does_not_see_upgrade(): void
    {
        [$user, $tenant] = $this->userWithAllowance(analyses: 5);
        $this->actAsTenantUser($user, $tenant);

        Livewire::test(PlanUsageWidget::class)
            ->assertDontSee(__('Upgrade'));
    }

    private function tenantFor(User $user): Tenant
    {
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user);

        return $tenant;
    }
}
