<?php

namespace Tests\Feature\Console;

use App\Constants\AuditFunding;
use App\Constants\AuditTier;
use App\Jobs\GenerateAuditReport;
use App\Models\AuditRequest;
use App\Models\AuditSchedule;
use App\Services\AuditReport\AuditEntitlementService;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\FeatureTest;
use Tests\Support\CreatesAuditSubscriptions;

class RunScheduledAuditsTest extends FeatureTest
{
    use CreatesAuditSubscriptions;

    public function test_a_schedule_runs_at_its_own_tier(): void
    {
        Queue::fake();
        [$user, $tenant] = $this->userWithAllowance(analyses: 5, deepAi: 2);

        AuditSchedule::create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'repo_url' => 'https://github.com/acme/app',
            'frequency' => 'weekly',
            'tier' => AuditTier::DEEP_AI->value,
        ]);

        $this->artisan('app:run-scheduled-audits')->assertSuccessful();

        $request = AuditRequest::where('user_id', $user->id)->latest('id')->firstOrFail();

        $this->assertSame(AuditTier::DEEP_AI, $request->tier);
        $this->assertSame(AuditFunding::ALLOWANCE, $request->funding);
        Queue::assertPushed(GenerateAuditReport::class);
    }

    public function test_an_exhausted_tier_is_skipped_not_downgraded(): void
    {
        Queue::fake();
        [$user, $tenant] = $this->userWithAllowance(analyses: 5, deepAi: 0);

        $schedule = AuditSchedule::create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'repo_url' => 'https://github.com/acme/app',
            'frequency' => 'weekly',
            'tier' => AuditTier::DEEP_AI->value,
        ]);

        $this->artisan('app:run-scheduled-audits')->assertSuccessful();

        // FeatureTest does not roll back between tests, so this must stay
        // scoped to the user under test rather than a global count.
        $this->assertSame(0, AuditRequest::where('user_id', $user->id)->count());
        Queue::assertNothingPushed();

        // The invariant this task exists to guarantee: a skip must never
        // advance last_run_at as though the schedule had actually run --
        // that would silently push it a full period into the future and
        // the customer would just quietly stop receiving audits.
        $this->assertNull($schedule->refresh()->last_run_at);
    }

    public function test_not_yet_due_schedule_is_skipped(): void
    {
        Queue::fake();
        [$user, $tenant] = $this->userWithAllowance(analyses: 5, deepAi: 2);

        AuditSchedule::create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'repo_url' => 'https://github.com/acme/app',
            'frequency' => 'weekly',
            'tier' => AuditTier::DEEP_AI->value,
            'last_run_at' => now()->subDays(2),
        ]);

        $this->artisan('app:run-scheduled-audits')->assertSuccessful();

        $this->assertSame(0, AuditRequest::where('user_id', $user->id)->count());
        Queue::assertNothingPushed();
    }

    /**
     * A diagnostic-tier schedule row is guarded against at creation time
     * (AuditReports::setSchedule() refuses a lifetime tier), but this proves
     * the command's own half of the fix independently: if such a row exists
     * regardless -- a legacy row, or a future caller that skips the dashboard
     * guard -- it must debit the lifetime free quota, not the monthly
     * allowance, exactly as launchAudit() would for the same tier.
     */
    public function test_a_diagnostic_schedule_debits_the_free_quota_not_the_allowance(): void
    {
        config(['audit.free_reports_limit' => 3]);
        Queue::fake();
        [$user, $tenant] = $this->userWithAllowance(analyses: 5, deepAi: 2);

        AuditSchedule::create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'repo_url' => 'https://github.com/acme/app',
            'frequency' => 'weekly',
            'tier' => AuditTier::DIAGNOSTIC->value,
        ]);

        $this->artisan('app:run-scheduled-audits')->assertSuccessful();

        $request = AuditRequest::where('user_id', $user->id)->latest('id')->firstOrFail();

        $this->assertSame(AuditTier::DIAGNOSTIC, $request->tier);
        $this->assertSame(AuditFunding::FREE, $request->funding);
        $this->assertTrue($request->free_run);
        $this->assertSame(1, app(AuditEntitlementService::class)->freeRunsUsed($user->email));
        Queue::assertPushed(GenerateAuditReport::class);
    }
}
