<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\AuditRequestStatus;
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
    public function test_subscriber_sees_allowance_usage_and_status_counts(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $this->createActiveSubscriptionFor($tenant, $user, ['audit_analyses_per_month' => 5]);

        // 2 dashboard runs this month; statuses: 1 in progress, 1 completed
        AuditRequest::factory()->dashboardSource()->create(['user_id' => $user->id, 'status' => AuditRequestStatus::ANALYZING->value]);
        AuditRequest::factory()->dashboardSource()->create(['user_id' => $user->id, 'status' => AuditRequestStatus::SENT->value]);
        AuditRequest::factory()->create(['user_id' => $user->id, 'status' => AuditRequestStatus::FAILED->value, 'source' => 'web']);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::actingAs($user)
            ->test(AuditStatsWidget::class)
            ->assertSee('3 / 5')   // remaining of allowance (2 dashboard runs used)
            ->assertSee('40')      // 40% used
            ->assertSee(__('In progress'))
            ->assertSee(__('Completed'))
            ->assertSee(__('Failed'));
    }

    public function test_free_user_sees_free_quota(): void
    {
        $user = User::factory()->create(['email' => 'free-widget@example.com']);
        $tenant = $this->createTenantFor($user);

        AuditRequest::factory()->freeRun()->create(['email' => 'free-widget@example.com', 'user_id' => $user->id, 'status' => AuditRequestStatus::SENT->value]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::actingAs($user)
            ->test(AuditStatsWidget::class)
            ->assertSee(__('Free audits remaining'))
            ->assertSee('2 / 3');
    }

    public function test_hidden_for_user_without_audits_or_allowance(): void
    {
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
