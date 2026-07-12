<?php

namespace Tests\Feature\Console;

use App\Constants\AuditRequestStatus;
use App\Jobs\GenerateAuditReport;
use App\Models\AuditRequest;
use App\Models\AuditSchedule;
use App\Services\AuditReport\AuditEntitlementService;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\FeatureTest;

class RunScheduledAuditsTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function allowRuns(int $remaining): void
    {
        $this->mock(AuditEntitlementService::class, function ($mock) use ($remaining) {
            $mock->shouldReceive('remainingDashboardRuns')->andReturn($remaining);
        });
    }

    public function test_due_weekly_schedule_dispatches_a_dashboard_audit(): void
    {
        $this->allowRuns(3);
        $schedule = AuditSchedule::factory()->create([
            'frequency' => 'weekly',
            'last_run_at' => now()->subDays(8),
        ]);

        $this->artisan('app:run-scheduled-audits')->assertSuccessful();

        $request = AuditRequest::where('user_id', $schedule->user_id)
            ->where('repo_url', $schedule->repo_url)->firstOrFail();
        $this->assertSame('dashboard', $request->source);
        $this->assertSame(AuditRequestStatus::QUEUED->value, $request->status);
        Queue::assertPushed(GenerateAuditReport::class);
        $this->assertTrue($schedule->refresh()->last_run_at->isCurrentDay());
    }

    public function test_not_yet_due_schedule_is_skipped(): void
    {
        $this->allowRuns(3);
        $schedule = AuditSchedule::factory()->create([
            'frequency' => 'weekly',
            'last_run_at' => now()->subDays(2),
        ]);

        $this->artisan('app:run-scheduled-audits')->assertSuccessful();

        $this->assertDatabaseMissing('audit_requests', ['user_id' => $schedule->user_id, 'repo_url' => $schedule->repo_url]);
    }

    public function test_exhausted_allowance_skips_without_failing(): void
    {
        $this->allowRuns(0);
        $schedule = AuditSchedule::factory()->create(['frequency' => 'monthly', 'last_run_at' => null]);

        $this->artisan('app:run-scheduled-audits')->assertSuccessful();

        $this->assertDatabaseMissing('audit_requests', ['user_id' => $schedule->user_id, 'repo_url' => $schedule->repo_url]);
        $this->assertNull($schedule->refresh()->last_run_at);
    }
}
