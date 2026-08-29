<?php

namespace Tests\Feature\Console;

use App\Constants\AuditFunding;
use App\Constants\AuditTier;
use App\Jobs\GenerateAuditReport;
use App\Models\AuditRequest;
use App\Models\AuditSchedule;
use App\Models\AuditScheduleRun;
use App\Services\AuditReport\AuditEntitlementService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\FeatureTest;
use Tests\Support\CreatesAuditSubscriptions;

class RunScheduledAuditsTest extends FeatureTest
{
    use CreatesAuditSubscriptions;

    public function test_a_schedule_runs_at_its_own_tier(): void
    {
        Process::fake(['*' => Process::result(exitCode: 1)]);
        Queue::fake();
        [$user, $tenant] = $this->userWithAllowance(diagnostic: 5, deepAi: 2);

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
        [$user, $tenant] = $this->userWithAllowance(diagnostic: 5, deepAi: 0);

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
        [$user, $tenant] = $this->userWithAllowance(diagnostic: 5, deepAi: 2);

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
     *
     * Diagnostic is only lifetime-backed when the plan grants it no monthly
     * allowance, so this user holds Deep AI credits and no Diagnostic ones.
     * A plan that grants Diagnostic credits makes it an ordinary metered
     * tier, which is the case the schedule guard legitimately permits.
     */
    public function test_a_diagnostic_schedule_debits_the_free_quota_not_the_allowance(): void
    {
        Process::fake(['*' => Process::result(exitCode: 1)]);
        config(['audit.free_reports_limit' => 3]);
        Queue::fake();
        [$user, $tenant] = $this->userWithAllowance(diagnostic: 0, deepAi: 2);

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

    public function test_skips_when_the_repo_is_unchanged_since_the_last_run(): void
    {
        Process::fake(['*' => Process::result(output: "sha-same\tHEAD\n")]);
        Queue::fake();
        [$user, $tenant] = $this->userWithAllowance(diagnostic: 5, deepAi: 2);

        $schedule = AuditSchedule::create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'repo_url' => 'https://github.com/acme/app',
            'frequency' => 'weekly',
            'tier' => AuditTier::DEEP_AI->value,
            'last_run_at' => now()->subWeek(),
            'last_commit_sha' => 'sha-same',
        ]);

        $this->artisan('app:run-scheduled-audits')->assertSuccessful();

        $this->assertSame(0, AuditRequest::where('user_id', $user->id)->count());
        Queue::assertNothingPushed();
        $this->assertNotNull($schedule->refresh()->last_run_at); // unchanged, still the old value
        $this->assertDatabaseHas('audit_schedule_runs', [
            'audit_schedule_id' => $schedule->id,
            'status' => 'skipped',
            'reason' => 'no_changes',
        ]);
    }

    public function test_runs_and_records_the_new_sha_when_the_repo_changed(): void
    {
        Process::fake(['*' => Process::result(output: "sha-new\tHEAD\n")]);
        Queue::fake();
        [$user, $tenant] = $this->userWithAllowance(diagnostic: 5, deepAi: 2);

        $schedule = AuditSchedule::create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'repo_url' => 'https://github.com/acme/app',
            'frequency' => 'weekly',
            'tier' => AuditTier::DEEP_AI->value,
            'last_run_at' => now()->subWeek(),
            'last_commit_sha' => 'sha-old',
        ]);

        $this->artisan('app:run-scheduled-audits')->assertSuccessful();

        $request = AuditRequest::where('user_id', $user->id)->latest('id')->firstOrFail();
        Queue::assertPushed(GenerateAuditReport::class);
        $this->assertSame('sha-new', $schedule->refresh()->last_commit_sha);
        $this->assertDatabaseHas('audit_schedule_runs', [
            'audit_schedule_id' => $schedule->id,
            'status' => 'completed',
            'audit_request_id' => $request->id,
            'commit_sha' => 'sha-new',
        ]);
    }

    public function test_a_failed_change_check_fails_open_and_runs_anyway(): void
    {
        Process::fake(['*' => Process::result(exitCode: 1)]);
        Queue::fake();
        [$user, $tenant] = $this->userWithAllowance(diagnostic: 5, deepAi: 2);

        AuditSchedule::create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'repo_url' => 'https://github.com/acme/app',
            'frequency' => 'weekly',
            'tier' => AuditTier::DEEP_AI->value,
            'last_run_at' => now()->subWeek(),
            'last_commit_sha' => 'sha-old',
        ]);

        $this->artisan('app:run-scheduled-audits')->assertSuccessful();

        Queue::assertPushed(GenerateAuditReport::class);
    }

    /**
     * The scheduler fires daily. A monthly schedule whose cadence has elapsed
     * stays due every single day (last_run_at <= now()->subMonth() keeps
     * matching), and a no_changes skip deliberately does not advance
     * last_run_at -- so an unchanged repo used to insert one audit_schedule_runs
     * row per day, forever. That is unbounded table growth, and it paints ~30
     * duplicate gray dots across the calendar this table exists to feed.
     * One row per (schedule, calendar day) is the invariant.
     */
    public function test_a_repeated_run_on_the_same_day_records_only_one_history_row(): void
    {
        Process::fake(['*' => Process::result(output: "sha-same\tHEAD\n")]);
        Queue::fake();
        [$user, $tenant] = $this->userWithAllowance(diagnostic: 5, deepAi: 2);

        $schedule = AuditSchedule::create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'repo_url' => 'https://github.com/acme/monthly-unchanged',
            'frequency' => 'monthly',
            'tier' => AuditTier::DEEP_AI->value,
            'last_run_at' => now()->subMonths(2),
            'last_commit_sha' => 'sha-same',
        ]);

        $this->artisan('app:run-scheduled-audits')->assertSuccessful();
        $this->artisan('app:run-scheduled-audits')->assertSuccessful();

        $this->assertSame(1, AuditScheduleRun::where('audit_schedule_id', $schedule->id)->count());
        $this->assertDatabaseHas('audit_schedule_runs', [
            'audit_schedule_id' => $schedule->id,
            'scheduled_for' => now()->toDateString(),
            'status' => 'skipped',
            'reason' => 'no_changes',
        ]);
    }

    public function test_weekly_schedule_with_day_of_week_only_runs_on_that_weekday(): void
    {
        Process::fake(['*' => Process::result(exitCode: 1)]); // irrelevant here; fails open either way
        Queue::fake();
        $today = Carbon::now();
        [$user, $tenant] = $this->userWithAllowance(diagnostic: 5, deepAi: 2);

        AuditSchedule::create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'repo_url' => 'https://github.com/acme/today',
            'frequency' => 'weekly',
            'tier' => AuditTier::DEEP_AI->value,
            'day_of_week' => $today->dayOfWeek,
            'last_run_at' => now()->subWeek(),
        ]);
        AuditSchedule::create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'repo_url' => 'https://github.com/acme/tomorrow',
            'frequency' => 'weekly',
            'tier' => AuditTier::DEEP_AI->value,
            'day_of_week' => ($today->dayOfWeek + 1) % 7,
            'last_run_at' => now()->subWeek(),
        ]);

        $this->artisan('app:run-scheduled-audits')->assertSuccessful();

        $this->assertSame(1, AuditRequest::where('user_id', $user->id)->count());
        $this->assertDatabaseHas('audit_requests', ['user_id' => $user->id, 'repo_url' => 'https://github.com/acme/today']);
    }

    public function test_monthly_schedule_with_day_of_month_only_runs_on_that_day(): void
    {
        Process::fake(['*' => Process::result(exitCode: 1)]); // irrelevant here; fails open either way
        Queue::fake();
        $today = Carbon::now();
        [$user, $tenant] = $this->userWithAllowance(diagnostic: 5, deepAi: 2);

        AuditSchedule::create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'repo_url' => 'https://github.com/acme/today',
            'frequency' => 'monthly',
            'tier' => AuditTier::DEEP_AI->value,
            'day_of_month' => $today->day,
            'last_run_at' => now()->subMonth(),
        ]);
        AuditSchedule::create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'repo_url' => 'https://github.com/acme/tomorrow',
            'frequency' => 'monthly',
            'tier' => AuditTier::DEEP_AI->value,
            'day_of_month' => ($today->day % $today->daysInMonth) + 1,
            'last_run_at' => now()->subMonth(),
        ]);

        $this->artisan('app:run-scheduled-audits')->assertSuccessful();

        $this->assertSame(1, AuditRequest::where('user_id', $user->id)->count());
        $this->assertDatabaseHas('audit_requests', ['user_id' => $user->id, 'repo_url' => 'https://github.com/acme/today']);
    }

    public function test_monthly_schedule_with_day_of_month_clamps_to_the_months_actual_length(): void
    {
        // Fixtures are created BEFORE freezing time: a User row created
        // under a frozen past "now" persists with that backdated
        // created_at, which pollutes any later test in the same run that
        // does an unscoped global aggregate over all Users (FeatureTest
        // does not roll back between tests) -- MetricServiceTest is exactly
        // such a test.
        Process::fake(['*' => Process::result(exitCode: 1)]);
        Queue::fake();
        [$user, $tenant] = $this->userWithAllowance(diagnostic: 5, deepAi: 2);

        Carbon::setTestNow(Carbon::parse('2026-02-28')); // 2026 is not a leap year; Feb has 28 days

        AuditSchedule::create([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'repo_url' => 'https://github.com/acme/end-of-month',
            'frequency' => 'monthly',
            'tier' => AuditTier::DEEP_AI->value,
            'day_of_month' => 31,
            'last_run_at' => Carbon::parse('2026-01-31'),
        ]);

        $this->artisan('app:run-scheduled-audits')->assertSuccessful();

        $this->assertSame(1, AuditRequest::where('user_id', $user->id)->count());

        Carbon::setTestNow();
    }
}
