<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\AuditRequestStatus;
use App\Constants\SubscriptionStatus;
use App\Filament\Dashboard\Pages\AuditReports;
use App\Jobs\GenerateAuditReport;
use App\Models\AuditRequest;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class AuditReportsPageTest extends FeatureTest
{
    public function test_launch_audit_creates_verified_dashboard_request_and_dispatches(): void
    {
        Queue::fake([GenerateAuditReport::class]);
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $this->createActiveSubscriptionFor($tenant, $user, ['audit_analyses_per_month' => 5]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::actingAs($user)
            ->test(AuditReports::class)
            ->call('launchAudit', 'https://github.com/acme/my-app');

        $request = AuditRequest::firstOrFail();
        $this->assertSame('dashboard', $request->source);
        $this->assertSame($user->id, $request->user_id);
        $this->assertSame(AuditRequestStatus::QUEUED->value, $request->status);
        $this->assertNotNull($request->email_verified_at);
        $this->assertFalse($request->free_run);
        Queue::assertPushed(GenerateAuditReport::class);
    }

    public function test_launch_audit_refuses_without_remaining_runs(): void
    {
        Queue::fake([GenerateAuditReport::class]);
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user); // no subscription

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::actingAs($user)
            ->test(AuditReports::class)
            ->call('launchAudit', 'https://github.com/acme/my-app');

        $this->assertSame(0, AuditRequest::where('user_id', $user->id)->count());
        Queue::assertNotPushed(GenerateAuditReport::class);
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
