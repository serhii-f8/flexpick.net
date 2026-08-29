<?php

namespace Tests\Unit\Models;

use App\Models\AuditRequest;
use App\Models\AuditSchedule;
use App\Models\AuditScheduleRun;
use Illuminate\Support\Carbon;
use Tests\Feature\FeatureTest;

class AuditScheduleRunTest extends FeatureTest
{
    public function test_a_run_row_belongs_to_its_schedule_and_optionally_a_request(): void
    {
        $schedule = AuditSchedule::factory()->create();
        $request = AuditRequest::factory()->create();

        $run = AuditScheduleRun::create([
            'audit_schedule_id' => $schedule->id,
            'scheduled_for' => '2026-09-01',
            'status' => 'completed',
            'reason' => null,
            'audit_request_id' => $request->id,
            'commit_sha' => 'sha123',
        ]);

        $this->assertTrue($run->scheduled_for->equalTo(Carbon::parse('2026-09-01')));
        $this->assertSame($schedule->id, $run->auditSchedule->id);
        $this->assertSame($request->id, $run->auditRequest->id);
        $this->assertCount(1, $schedule->refresh()->scheduleRuns);
    }

    public function test_a_skipped_run_row_has_no_audit_request(): void
    {
        $schedule = AuditSchedule::factory()->create();

        $run = AuditScheduleRun::create([
            'audit_schedule_id' => $schedule->id,
            'scheduled_for' => '2026-09-08',
            'status' => 'skipped',
            'reason' => 'no_changes',
        ]);

        $this->assertNull($run->audit_request_id);
        $this->assertSame('no_changes', $run->reason);
    }
}
