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
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class AuditAdminWidgetsTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        AuditRequest::query()->delete();
    }

    private function subscribedUser(string $planName): User
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $tenant->users()->attach($user);

        $product = Product::factory()->create([
            'name' => 'Audit Growth',
            'metadata' => ['audit_analyses_per_month' => 20],
        ]);
        $plan = Plan::factory()->create(['product_id' => $product->id, 'name' => $planName]);

        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addMonth(),
        ]);

        return $user;
    }

    public function test_by_plan_chart_labels_each_plan_and_the_free_bucket(): void
    {
        $admin = $this->createAdminUser();
        $user = $this->subscribedUser('Audit Growth Monthly');

        AuditRequest::factory()->count(2)->create(['user_id' => $user->id]);
        AuditRequest::factory()->create(); // no subscription → free

        $component = Livewire::actingAs($admin)
            ->test(AuditsByPlanWidget::class, ['pageFilters' => [
                'start_date' => now()->startOfMonth()->toDateString(),
                'end_date' => now()->toDateString(),
                'period' => 'month',
            ]]);

        // Test the chart data directly via reflection to avoid HTML escaping layers
        $widget = $component->instance();
        $reflection = new \ReflectionMethod($widget, 'getData');
        $reflection->setAccessible(true);
        /** @var array{labels: array<string>, datasets: array} $data */
        $data = $reflection->invoke($widget);

        $this->assertContains('Audit Growth Monthly', $data['labels']);
        $this->assertContains(__('Free / no plan'), $data['labels']);
    }

    public function test_by_plan_chart_respects_the_dashboard_date_filter(): void
    {
        // Unlike the ops block, this is a metric rather than an alarm, so the
        // page's date range must apply. Pinned so the asymmetry stays deliberate.
        $this->assertContains(
            InteractsWithPageFilters::class,
            class_uses_recursive(AuditsByPlanWidget::class),
        );

        $admin = $this->createAdminUser();
        $user = $this->subscribedUser('Audit Scale Monthly');

        AuditRequest::factory()->create([
            'user_id' => $user->id,
            'created_at' => now()->subMonths(6),
        ]);

        Livewire::actingAs($admin)
            ->test(AuditsByPlanWidget::class, ['pageFilters' => [
                'start_date' => now()->startOfMonth()->toDateString(),
                'end_date' => now()->toDateString(),
                'period' => 'month',
            ]])
            ->assertDontSee('Audit Scale Monthly');
    }

    public function test_by_plan_chart_renders_with_no_audits_at_all(): void
    {
        $admin = $this->createAdminUser();

        Livewire::actingAs($admin)
            ->test(AuditsByPlanWidget::class, ['pageFilters' => [
                'start_date' => now()->startOfMonth()->toDateString(),
                'end_date' => now()->toDateString(),
                'period' => 'month',
            ]])
            ->assertSuccessful()
            ->assertSee(__('No audits in the selected period.'));
    }
}
