<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\AuditFunding;
use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Filament\Dashboard\Pages\AuditReports;
use App\Jobs\GenerateAuditReport;
use App\Models\AuditRequest;
use App\Models\AuditSchedule;
use App\Services\AuditReport\AuditEntitlementService;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;
use Tests\Support\CreatesAuditSubscriptions;

class AuditReportsTierSelectionTest extends FeatureTest
{
    use CreatesAuditSubscriptions;

    public function test_a_diagnostic_run_is_created_at_that_tier_and_spends_the_allowance(): void
    {
        Queue::fake();
        [$user, $tenant] = $this->userWithAllowance(diagnostic: 5);
        $this->actAsTenantUser($user, $tenant);

        Livewire::test(AuditReports::class)
            ->assertSet('tier', AuditTier::DIAGNOSTIC->value)
            ->set('repoUrl', 'https://github.com/acme/app')
            ->set('tier', AuditTier::DIAGNOSTIC->value)
            ->call('launchAudit');

        $request = AuditRequest::where('user_id', $user->id)->latest('id')->firstOrFail();

        $this->assertSame(AuditTier::DIAGNOSTIC, $request->tier);
        $this->assertSame(AuditFunding::ALLOWANCE, $request->funding);
        $this->assertSame('dashboard', $request->source);
        $this->assertSame(
            4,
            app(AuditEntitlementService::class)
                ->remainingRuns($user, $tenant, AuditTier::DIAGNOSTIC),
        );
        Queue::assertPushed(GenerateAuditReport::class);
    }

    /**
     * Diagnostic falls back to the per-email lifetime free-run quota only
     * when the plan grants it no monthly allowance -- so this user holds
     * Deep AI credits and no Diagnostic ones. With a Diagnostic allowance
     * present the tier is metered like any other, which the test above
     * covers.
     */
    public function test_a_diagnostic_run_spends_a_free_run_when_the_plan_grants_no_diagnostic_allowance(): void
    {
        config(['audit.free_reports_limit' => 3]);
        Queue::fake();
        [$user, $tenant] = $this->userWithAllowance(diagnostic: 0, deepAi: 2);
        $this->actAsTenantUser($user, $tenant);

        Livewire::test(AuditReports::class)
            ->set('repoUrl', 'https://github.com/acme/app')
            ->set('tier', AuditTier::DIAGNOSTIC->value)
            ->call('launchAudit');

        $request = AuditRequest::where('user_id', $user->id)->latest('id')->firstOrFail();

        $this->assertSame(AuditTier::DIAGNOSTIC, $request->tier);
        $this->assertSame(AuditFunding::FREE, $request->funding);
        $this->assertTrue($request->free_run);
        $this->assertSame(
            2,
            app(AuditEntitlementService::class)
                ->remainingRuns($user, $tenant, AuditTier::DEEP_AI),
        );
    }

    public function test_a_tier_with_no_quota_creates_nothing(): void
    {
        Queue::fake();
        // Zero expert credits, and Expert is purchasable -- so the guard must
        // route to checkout rather than queue anything. Setting the property
        // directly is exactly what a crafted request would do.
        [$user, $tenant] = $this->userWithAllowance(diagnostic: 5, expert: 0);
        $this->actAsTenantUser($user, $tenant);

        Livewire::test(AuditReports::class)
            ->set('repoUrl', 'https://github.com/acme/app')
            ->set('tier', AuditTier::EXPERT->value)
            ->call('launchAudit');

        // The guard routes to checkout, which deliberately creates an
        // AWAITING_PAYMENT row so the repo URL and tier survive payment.
        // What must NOT happen is a run entering the pipeline unpaid.
        Queue::assertNothingPushed();

        $this->assertSame(
            0,
            AuditRequest::where('user_id', $user->id)
                ->where('status', AuditRequestStatus::QUEUED->value)
                ->count(),
        );

        $intent = AuditRequest::where('user_id', $user->id)->sole();
        $this->assertSame(AuditRequestStatus::AWAITING_PAYMENT->value, $intent->status);
        $this->assertSame(AuditFunding::PURCHASE, $intent->funding);
    }

    public function test_an_unknown_tier_value_is_rejected(): void
    {
        Queue::fake();
        [$user, $tenant] = $this->userWithAllowance(diagnostic: 5);
        $this->actAsTenantUser($user, $tenant);

        Livewire::test(AuditReports::class)
            ->set('repoUrl', 'https://github.com/acme/app')
            ->set('tier', 'platinum')
            ->call('launchAudit');

        // FeatureTest does not roll back between tests, so earlier methods in
        // this class leave their own AuditRequest rows in the shared table --
        // scope to this test's user rather than asserting a global count.
        $this->assertSame(0, AuditRequest::where('user_id', $user->id)->count());
        Queue::assertNothingPushed();
    }

    /**
     * setSchedule() takes its tier from a client-controlled Livewire method
     * argument. The blade's tier <select> never offers a lifetime tier, but
     * that is a rendering hint, not a gate -- a crafted call must still be
     * refused. A schedule is a subscriber feature; the one-off free-run
     * quota must never back a recurring run.
     *
     * The guard keys on isLifetime, not on the tier, so this user must hold
     * no Diagnostic allowance: a plan that grants one makes Diagnostic an
     * ordinary metered tier and scheduling it is then legitimate.
     */
    public function test_set_schedule_refuses_a_diagnostic_tier_supplied_directly(): void
    {
        config(['audit.free_reports_limit' => 3]);
        [$user, $tenant] = $this->userWithAllowance(diagnostic: 0, deepAi: 2);
        $this->actAsTenantUser($user, $tenant);

        Livewire::test(AuditReports::class)
            ->call('setSchedule', 'https://github.com/acme/app', 'weekly', AuditTier::DIAGNOSTIC->value);

        $this->assertDatabaseMissing('audit_schedules', [
            'user_id' => $user->id,
            'repo_url' => 'https://github.com/acme/app',
        ]);
        $this->assertSame(0, AuditSchedule::where('user_id', $user->id)->count());
    }
}
