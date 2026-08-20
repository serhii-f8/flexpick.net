<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Constants\SubscriptionStatus;
use App\Filament\Dashboard\Pages\AuditReports;
use App\Jobs\GenerateAuditReport;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditReport\AuditEntitlementService;
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

        $request = AuditRequest::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('dashboard', $request->source);
        $this->assertSame($user->id, $request->user_id);
        $this->assertSame(AuditRequestStatus::QUEUED->value, $request->status);
        $this->assertNotNull($request->email_verified_at);
        $this->assertFalse($request->free_run);
        Queue::assertPushed(GenerateAuditReport::class);
    }

    /**
     * Without a subscription the run falls back to the free-run quota, so the
     * refusal only applies once that quota is spent too.
     */
    public function test_launch_audit_refuses_when_free_runs_are_exhausted(): void
    {
        Queue::fake([GenerateAuditReport::class]);
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user); // no subscription
        AuditRequest::factory()->count(3)->freeRun()->create(['email' => $user->email]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::actingAs($user)
            ->test(AuditReports::class)
            ->call('launchAudit', 'https://github.com/acme/my-app');

        $this->assertSame(0, AuditRequest::where('user_id', $user->id)->count());
        Queue::assertNotPushed(GenerateAuditReport::class);
    }

    public function test_launch_audit_consumes_a_free_run_without_subscription(): void
    {
        config(['audit.free_reports_limit' => 3]);
        Queue::fake([GenerateAuditReport::class]);
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user); // no subscription

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::actingAs($user)
            ->test(AuditReports::class)
            ->call('launchAudit', 'https://github.com/acme/my-app');

        $request = AuditRequest::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('dashboard', $request->source);
        $this->assertTrue($request->free_run, 'A dashboard run without a subscription must consume a free run.');
        $this->assertSame(1, app(AuditEntitlementService::class)->freeRunsUsed($user->email));
        Queue::assertPushed(GenerateAuditReport::class);
    }

    public function test_navigation_registers_for_user_with_only_free_runs(): void
    {
        config(['audit.free_reports_limit' => 3]);
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user); // no subscription, no reports

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        $this->assertTrue(AuditReports::shouldRegisterNavigation());
    }

    public function test_navigation_registers_for_subscribed_tenant_without_reports(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);

        $this->createActiveSubscriptionFor($tenant, $user, ['audit_analyses_per_month' => 5]);

        // Remove the free-run quota so the subscription is the only remaining
        // route to access -- otherwise this passes on free runs alone and
        // stops proving anything about subscriptions.
        config(['audit.free_reports_limit' => 0]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        $this->assertTrue(AuditReports::shouldRegisterNavigation());
    }

    public function test_set_schedule_creates_and_removes_audit_schedules(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::actingAs($user)
            ->test(AuditReports::class)
            ->call('setSchedule', 'https://github.com/acme/app/', 'weekly');

        $this->assertDatabaseHas('audit_schedules', [
            'user_id' => $user->id,
            'repo_url' => 'https://github.com/acme/app',
            'frequency' => 'weekly',
        ]);

        Livewire::actingAs($user)
            ->test(AuditReports::class)
            ->call('setSchedule', 'https://github.com/acme/app', 'off');

        $this->assertDatabaseMissing('audit_schedules', ['user_id' => $user->id]);
    }

    /**
     * A $999 expert-tier run held for review must not leave the customer
     * looking at an empty list -- it still shows in the list (Task 7 adds
     * a dedicated "in review" badge for it).
     */
    public function test_report_held_for_expert_review_is_still_listed(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);

        $heldRequest = AuditRequest::factory()->create([
            'user_id' => $user->id,
            'tier' => AuditTier::EXPERT->value,
            'status' => AuditRequestStatus::EXPERT_REVIEW->value,
            'repo_url' => 'https://github.com/acme/held-repo',
        ]);
        AuditReport::factory()->create(['audit_request_id' => $heldRequest->id, 'user_id' => $user->id]);

        $sentRequest = AuditRequest::factory()->create([
            'user_id' => $user->id,
            'status' => AuditRequestStatus::SENT->value,
            'repo_url' => 'https://github.com/acme/sent-repo',
        ]);
        AuditReport::factory()->create(['audit_request_id' => $sentRequest->id, 'user_id' => $user->id]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::test(AuditReports::class)
            ->assertSee('https://github.com/acme/sent-repo')
            ->assertSee('https://github.com/acme/held-repo');
    }

    public function test_repo_section_shows_current_score_and_delta(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);

        foreach ([[60, 7], [68, 0]] as [$score, $daysAgo]) {
            $request = AuditRequest::factory()->create([
                'user_id' => $user->id,
                'repo_url' => 'https://github.com/acme/app',
                'status' => AuditRequestStatus::SENT->value,
                'created_at' => now()->subDays($daysAgo),
            ]);
            AuditReport::factory()->create([
                'audit_request_id' => $request->id,
                'user_id' => $user->id,
                'payload' => ['scores' => ['overall' => $score]],
                'created_at' => now()->subDays($daysAgo),
            ]);
        }

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::test(AuditReports::class)
            ->assertSee('68')
            ->assertSee('+8');
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
