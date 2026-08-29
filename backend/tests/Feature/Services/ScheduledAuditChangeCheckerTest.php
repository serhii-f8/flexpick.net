<?php

namespace Tests\Feature\Services;

use App\Models\AuditSchedule;
use App\Services\AuditReport\ScheduledAuditChangeChecker;
use Illuminate\Support\Facades\Process;
use Tests\Feature\FeatureTest;

class ScheduledAuditChangeCheckerTest extends FeatureTest
{
    public function test_same_sha_as_last_run_means_no_run_needed(): void
    {
        Process::fake(['*' => Process::result(output: "sha123\tHEAD\n")]);
        $schedule = AuditSchedule::factory()->make(['last_commit_sha' => 'sha123']);

        $result = app(ScheduledAuditChangeChecker::class)->check($schedule);

        $this->assertFalse($result->shouldRun);
        $this->assertSame('sha123', $result->sha);
    }

    public function test_different_sha_means_a_run_is_needed(): void
    {
        Process::fake(['*' => Process::result(output: "sha456\tHEAD\n")]);
        $schedule = AuditSchedule::factory()->make(['last_commit_sha' => 'sha123']);

        $result = app(ScheduledAuditChangeChecker::class)->check($schedule);

        $this->assertTrue($result->shouldRun);
        $this->assertSame('sha456', $result->sha);
    }

    public function test_no_prior_sha_always_runs(): void
    {
        Process::fake(['*' => Process::result(output: "sha456\tHEAD\n")]);
        $schedule = AuditSchedule::factory()->make(['last_commit_sha' => null]);

        $result = app(ScheduledAuditChangeChecker::class)->check($schedule);

        $this->assertTrue($result->shouldRun);
        $this->assertSame('sha456', $result->sha);
    }

    public function test_ls_remote_failure_fails_open(): void
    {
        Process::fake(['*' => Process::result(exitCode: 1)]);
        $schedule = AuditSchedule::factory()->make(['last_commit_sha' => 'sha123']);

        $result = app(ScheduledAuditChangeChecker::class)->check($schedule);

        $this->assertTrue($result->shouldRun);
        $this->assertNull($result->sha);
    }
}
