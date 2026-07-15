<?php

namespace Tests\Feature\Filament\Admin;

use App\Constants\AuditRequestStatus;
use App\Constants\SubscriptionStatus;
use App\Filament\Admin\Widgets\AuditAdminStatsWidget;
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
    public function test_stats_widget_counts_statuses_and_average_processing_time(): void
    {
        $admin = $this->createAdminUser();

        AuditRequest::factory()->create(['status' => AuditRequestStatus::QUEUED->value]);
        AuditRequest::factory()->create(['status' => AuditRequestStatus::ANALYZING->value]);
        AuditRequest::factory()->create([
            'status' => AuditRequestStatus::SENT->value,
            'analysis_started_at' => now()->subMinutes(10),
            'analysis_completed_at' => now()->subMinutes(6),
        ]);
        AuditRequest::factory()->create(['status' => AuditRequestStatus::FAILED->value]);
        AuditRequest::factory()->create(['status' => AuditRequestStatus::AWAITING_ACCESS->value]);

        Livewire::actingAs($admin)
            ->test(AuditAdminStatsWidget::class)
            ->assertSee(__('Total audits'))
            ->assertSee(__('Analyzing'))
            ->assertSee(__('Needs manual action'))
            ->assertSee('4m'); // average processing time
    }

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
