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

class PlanUsageWidgetTest extends FeatureTest
{
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
            ->assertSee(__('Analyses this month'));
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
            ->assertDontSee(__('Analyses this month'));
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

    private function tenantFor(User $user): Tenant
    {
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user);

        return $tenant;
    }
}
