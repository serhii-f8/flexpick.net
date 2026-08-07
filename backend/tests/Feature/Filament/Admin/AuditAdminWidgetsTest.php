<?php

namespace Tests\Feature\Filament\Admin;

use App\Constants\SubscriptionStatus;
use App\Filament\Admin\Widgets\AuditsByPlanWidget;
use App\Models\AuditRequest;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class AuditAdminWidgetsTest extends FeatureTest
{
    public function test_by_plan_widget_groups_current_month_audits(): void
    {
        $admin = $this->createAdminUser();

        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user);
        $product = Product::factory()->create(['name' => 'Audit Growth', 'metadata' => ['audit_analyses_per_month' => 20]]);
        $plan = Plan::factory()->create(['product_id' => $product->id, 'name' => 'Audit Growth Monthly']);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addMonth(),
        ]);
        AuditRequest::factory()->count(2)->create(['user_id' => $user->id]);
        AuditRequest::factory()->create(); // no subscription → free

        Livewire::actingAs($admin)
            ->test(AuditsByPlanWidget::class)
            ->assertSee('Audit Growth Monthly')
            ->assertSee(__('Free / no plan'));
    }
}
