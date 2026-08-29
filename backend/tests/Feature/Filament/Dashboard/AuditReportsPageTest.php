<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\AuditFunding;
use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Constants\SubscriptionStatus;
use App\Filament\Dashboard\Pages\AuditReports;
use App\Jobs\GenerateAuditReport;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Models\AuditSchedule;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditReport\AuditEntitlementService;
use App\Services\GitHub\GitHubApiClient;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
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
        $this->createActiveSubscriptionFor($tenant, $user, ['audit_diagnostic_credits' => 5]);

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
     * Without a subscription the run falls back to the free-run quota. Once
     * that quota is spent, the Diagnostic tier is priced in the catalog, so
     * quotaFor(...)->purchasable() is true and launchAudit() redirects to
     * checkout instead of refusing outright.
     */
    public function test_launch_audit_redirects_to_purchase_when_free_runs_are_exhausted(): void
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
            ->call('launchAudit', 'https://github.com/acme/my-app')
            ->assertRedirect(route('buy.product', ['productSlug' => 'audit-diagnostic']));

        $request = AuditRequest::where('user_id', $user->id)->sole();
        $this->assertSame(AuditRequestStatus::AWAITING_PAYMENT->value, $request->status);
        $this->assertSame(AuditFunding::PURCHASE, $request->funding);
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

        $this->createActiveSubscriptionFor($tenant, $user, ['audit_diagnostic_credits' => 5]);

        // Remove the free-run quota AND empty the tier catalog so the
        // subscription is the only remaining route to access -- otherwise
        // this passes on free runs, or on any tier simply being purchasable,
        // and stops proving anything about subscriptions.
        config(['audit.free_reports_limit' => 0, 'pricing.tiers' => []]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        $this->assertTrue(AuditReports::shouldRegisterNavigation());
    }

    /**
     * setSchedule() defaults to Diagnostic, and its guard refuses any tier
     * backed by the lifetime free-run quota -- a schedule is a subscriber
     * feature. So this user needs a plan that grants Diagnostic credits,
     * which is also the only case where the blade renders the control.
     */
    public function test_set_schedule_creates_and_removes_audit_schedules(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $this->createActiveSubscriptionFor($tenant, $user, ['audit_diagnostic_credits' => 5]);

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

    public function test_launch_audit_persists_the_chosen_branch(): void
    {
        Queue::fake([GenerateAuditReport::class]);
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $this->createActiveSubscriptionFor($tenant, $user, ['audit_diagnostic_credits' => 5]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::actingAs($user)
            ->test(AuditReports::class)
            ->call('launchAudit', 'https://github.com/acme/my-app', null, 'release/2.0');

        $this->assertSame('release/2.0', AuditRequest::where('user_id', $user->id)->firstOrFail()->branch);
    }

    public function test_launch_audit_leaves_branch_null_when_not_supplied(): void
    {
        Queue::fake([GenerateAuditReport::class]);
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $this->createActiveSubscriptionFor($tenant, $user, ['audit_diagnostic_credits' => 5]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::actingAs($user)
            ->test(AuditReports::class)
            ->call('launchAudit', 'https://github.com/acme/my-app');

        $this->assertNull(AuditRequest::where('user_id', $user->id)->firstOrFail()->branch);
    }

    /**
     * The Re-run button on a repo card names its own repo. The branch picked
     * in the launch form at the top of the page belongs to whatever repo the
     * user was about to submit there -- a different repo entirely. Auditing
     * repo B against repo A's branch is wrong when the branch exists on both,
     * and a clone failure (after a credit is spent) when it does not.
     */
    public function test_launch_audit_for_another_repo_ignores_the_launch_forms_branch(): void
    {
        Queue::fake([GenerateAuditReport::class]);
        // Setting repoUrl fires updatedRepoUrl() -> loadBranches(), which
        // would otherwise reach the real GitHub API.
        Http::fake(['api.github.com/*' => Http::response([['name' => 'feature-x']])]);
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $this->createActiveSubscriptionFor($tenant, $user, ['audit_diagnostic_credits' => 5]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::actingAs($user)
            ->test(AuditReports::class)
            ->set('repoUrl', 'https://github.com/acme/about-to-submit')
            ->set('branch', 'feature-x')
            ->call('launchAudit', 'https://github.com/acme/unrelated-repo', AuditTier::DIAGNOSTIC->value);

        $request = AuditRequest::where('user_id', $user->id)
            ->where('repo_url', 'https://github.com/acme/unrelated-repo')
            ->firstOrFail();

        $this->assertNotSame('feature-x', $request->branch);
        $this->assertNull($request->branch);
    }

    public function test_a_new_weekly_schedule_defaults_day_of_week_to_today(): void
    {
        // Fixtures are created BEFORE freezing time: a User row created
        // under a frozen past "now" persists with that backdated
        // created_at, which pollutes any later test in the same run that
        // does an unscoped global aggregate over all Users (FeatureTest
        // does not roll back between tests) -- MetricServiceTest is exactly
        // such a test. Freezing only around the day-of-week logic below
        // keeps this test's own assertion deterministic without leaking a
        // backdated fixture into the rest of the suite.
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $this->createActiveSubscriptionFor($tenant, $user, ['audit_diagnostic_credits' => 5]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Carbon::setTestNow(Carbon::parse('2026-08-10'));

        Livewire::actingAs($user)
            ->test(AuditReports::class)
            ->call('setSchedule', 'https://github.com/acme/app', 'weekly');

        $this->assertSame(
            Carbon::now()->dayOfWeek,
            AuditSchedule::where('user_id', $user->id)->firstOrFail()->day_of_week,
        );

        Carbon::setTestNow();
    }

    public function test_set_schedule_day_updates_an_existing_weekly_schedule(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $this->createActiveSubscriptionFor($tenant, $user, ['audit_diagnostic_credits' => 5]);
        $schedule = AuditSchedule::create([
            'user_id' => $user->id, 'tenant_id' => $tenant->id, 'repo_url' => 'https://github.com/acme/app',
            'frequency' => 'weekly', 'tier' => AuditTier::DIAGNOSTIC->value, 'day_of_week' => 1,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::actingAs($user)
            ->test(AuditReports::class)
            ->call('setScheduleDay', 'https://github.com/acme/app', 4);

        $this->assertSame(4, $schedule->refresh()->day_of_week);
    }

    public function test_set_schedule_branch_updates_an_existing_schedule(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $this->createActiveSubscriptionFor($tenant, $user, ['audit_diagnostic_credits' => 5]);
        $schedule = AuditSchedule::create([
            'user_id' => $user->id, 'tenant_id' => $tenant->id, 'repo_url' => 'https://github.com/acme/app',
            'frequency' => 'weekly', 'tier' => AuditTier::DIAGNOSTIC->value,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::actingAs($user)
            ->test(AuditReports::class)
            ->call('setScheduleBranch', 'https://github.com/acme/app', 'develop');

        $this->assertSame('develop', $schedule->refresh()->branch);

        Livewire::actingAs($user)
            ->test(AuditReports::class)
            ->call('setScheduleBranch', 'https://github.com/acme/app', '');

        $this->assertNull($schedule->refresh()->branch);
    }

    public function test_a_new_monthly_schedule_defaults_day_of_month_to_today(): void
    {
        // Fixtures are created BEFORE freezing time -- see the comment on
        // test_a_new_weekly_schedule_defaults_day_of_week_to_today above.
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $this->createActiveSubscriptionFor($tenant, $user, ['audit_diagnostic_credits' => 5]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Carbon::setTestNow(Carbon::parse('2026-08-10'));

        Livewire::actingAs($user)
            ->test(AuditReports::class)
            ->call('setSchedule', 'https://github.com/acme/app', 'monthly');

        $this->assertSame(
            Carbon::now()->day,
            AuditSchedule::where('user_id', $user->id)->firstOrFail()->day_of_month,
        );

        Carbon::setTestNow();
    }

    public function test_set_schedule_month_day_updates_an_existing_monthly_schedule(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $this->createActiveSubscriptionFor($tenant, $user, ['audit_diagnostic_credits' => 5]);
        $schedule = AuditSchedule::create([
            'user_id' => $user->id, 'tenant_id' => $tenant->id, 'repo_url' => 'https://github.com/acme/app',
            'frequency' => 'monthly', 'tier' => AuditTier::DIAGNOSTIC->value, 'day_of_month' => 1,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::actingAs($user)
            ->test(AuditReports::class)
            ->call('setScheduleMonthDay', 'https://github.com/acme/app', 15);

        $this->assertSame(15, $schedule->refresh()->day_of_month);
    }

    public function test_set_schedule_month_day_clamps_to_a_valid_range(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $this->createActiveSubscriptionFor($tenant, $user, ['audit_diagnostic_credits' => 5]);
        $schedule = AuditSchedule::create([
            'user_id' => $user->id, 'tenant_id' => $tenant->id, 'repo_url' => 'https://github.com/acme/app',
            'frequency' => 'monthly', 'tier' => AuditTier::DIAGNOSTIC->value, 'day_of_month' => 1,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::actingAs($user)
            ->test(AuditReports::class)
            ->call('setScheduleMonthDay', 'https://github.com/acme/app', 99);

        $this->assertSame(31, $schedule->refresh()->day_of_month);

        Livewire::actingAs($user)
            ->test(AuditReports::class)
            ->call('setScheduleMonthDay', 'https://github.com/acme/app', 0);

        $this->assertSame(1, $schedule->refresh()->day_of_month);
    }

    /**
     * The lookup is entitled by the user's own audit history: this repo has an
     * AuditRequest of theirs, which is exactly the state that renders the
     * per-repo branch <select> whose x-init calls this method.
     */
    public function test_load_branches_populates_branches_by_repo_from_the_github_client(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $this->createActiveSubscriptionFor($tenant, $user, ['audit_diagnostic_credits' => 5]);
        AuditRequest::factory()->create(['user_id' => $user->id, 'repo_url' => 'https://github.com/acme/app']);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        $this->mock(GitHubApiClient::class, function ($mock) {
            $mock->shouldReceive('listBranches')->once()->with('https://github.com/acme/app')->andReturn(['main', 'develop']);
        });

        Livewire::actingAs($user)
            ->test(AuditReports::class)
            ->call('loadBranches', 'https://github.com/acme/app')
            ->assertSet('branchesByRepo', ['https://github.com/acme/app' => ['main', 'develop']]);
    }

    /**
     * loadBranches() is a public Livewire method backed by the shared
     * AUDIT_GITHUB_TOKEN PAT, which is a read-only collaborator on every
     * customer's private repos. Unrestricted, it is a free, instant,
     * repeatable, unlogged oracle for "does this repo exist and can the PAT
     * see it" against any owner/repo on GitHub -- strictly worse than probing
     * via launchAudit(), which at least costs a credit and leaves a row.
     */
    public function test_load_branches_refuses_a_repo_the_user_has_no_claim_to(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $this->createActiveSubscriptionFor($tenant, $user, ['audit_diagnostic_credits' => 5]);

        // Someone else's repo: not in this user's repoUrl input, and not on
        // any AuditRequest or AuditSchedule of theirs.
        $stranger = User::factory()->create();
        AuditRequest::factory()->create([
            'user_id' => $stranger->id,
            'repo_url' => 'https://github.com/victim/secret',
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        $this->mock(GitHubApiClient::class, function ($mock) {
            $mock->shouldReceive('listBranches')->never();
        });

        Livewire::actingAs($user)
            ->test(AuditReports::class)
            ->call('loadBranches', 'https://github.com/victim/secret')
            ->assertSet('branchesByRepo', []);
    }

    public function test_load_branches_allows_a_repo_the_user_has_scheduled(): void
    {
        $user = User::factory()->create();
        $tenant = $this->createTenantFor($user);
        $this->createActiveSubscriptionFor($tenant, $user, ['audit_diagnostic_credits' => 5]);
        AuditSchedule::create([
            'user_id' => $user->id, 'tenant_id' => $tenant->id, 'repo_url' => 'https://github.com/acme/scheduled',
            'frequency' => 'weekly', 'tier' => AuditTier::DIAGNOSTIC->value,
        ]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        $this->mock(GitHubApiClient::class, function ($mock) {
            $mock->shouldReceive('listBranches')->once()->andReturn(['main']);
        });

        Livewire::actingAs($user)
            ->test(AuditReports::class)
            ->call('loadBranches', 'https://github.com/acme/scheduled')
            ->assertSet('branchesByRepo', ['https://github.com/acme/scheduled' => ['main']]);
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
