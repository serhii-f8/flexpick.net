<?php

namespace Tests\Feature\Services;

use App\Models\AuditSchedule;
use App\Models\TenantGitConnection;
use App\Services\AuditReport\ScheduledAuditChangeChecker;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\Feature\FeatureTest;

class ScheduledAuditChangeCheckerTest extends FeatureTest
{
    public function test_same_sha_as_last_run_means_no_run_needed(): void
    {
        Process::fake(['*' => Process::result(output: "sha123\tHEAD\n")]);
        $schedule = AuditSchedule::factory()->make(['last_commit_sha' => 'sha123']);
        TenantGitConnection::factory()->for($schedule->tenant)->create(['provider' => 'github']);

        $result = app(ScheduledAuditChangeChecker::class)->check($schedule);

        $this->assertFalse($result->shouldRun);
        $this->assertSame('sha123', $result->sha);
    }

    public function test_different_sha_means_a_run_is_needed(): void
    {
        Process::fake(['*' => Process::result(output: "sha456\tHEAD\n")]);
        $schedule = AuditSchedule::factory()->make(['last_commit_sha' => 'sha123']);
        TenantGitConnection::factory()->for($schedule->tenant)->create(['provider' => 'github']);

        $result = app(ScheduledAuditChangeChecker::class)->check($schedule);

        $this->assertTrue($result->shouldRun);
        $this->assertSame('sha456', $result->sha);
    }

    public function test_no_prior_sha_always_runs(): void
    {
        Process::fake(['*' => Process::result(output: "sha456\tHEAD\n")]);
        $schedule = AuditSchedule::factory()->make(['last_commit_sha' => null]);
        TenantGitConnection::factory()->for($schedule->tenant)->create(['provider' => 'github']);

        $result = app(ScheduledAuditChangeChecker::class)->check($schedule);

        $this->assertTrue($result->shouldRun);
        $this->assertSame('sha456', $result->sha);
    }

    public function test_ls_remote_failure_fails_open(): void
    {
        Process::fake(['*' => Process::result(exitCode: 1)]);
        $schedule = AuditSchedule::factory()->make(['last_commit_sha' => 'sha123']);
        // Connected, so the null below can only come from the faked ls-remote
        // failure -- not from some no-connection short-circuit.
        TenantGitConnection::factory()->for($schedule->tenant)->create(['provider' => 'github', 'access_token' => 'ghp_schedule_token']);

        $result = app(ScheduledAuditChangeChecker::class)->check($schedule);

        $this->assertTrue($result->shouldRun);
        $this->assertNull($result->sha);
        Process::assertRan(fn (PendingProcess $process) => ($process->command[3] ?? null) === 'ls-remote'
            && ! str_contains(implode(' ', (array) $process->command), 'ghp_schedule_token')
            && str_contains($process->environment['GIT_CONFIG_VALUE_0'] ?? '', base64_encode('x-access-token:ghp_schedule_token')));
    }
}
