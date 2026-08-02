<?php

namespace Tests\Feature\Health;

use App\Constants\AuditRequestStatus;
use App\Health\Checks\AuditPipelineFailureRateCheck;
use App\Health\Checks\MailFailureRateCheck;
use App\Models\AuditEmailLog;
use App\Models\AuditRequest;
use Carbon\Carbon;
use Tests\Feature\FeatureTest;

class FailureRateChecksTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        // FeatureTest does not roll back; these checks count whole tables.
        AuditEmailLog::query()->forceDelete();
        AuditRequest::query()->forceDelete();

        Carbon::setTestNow('2026-08-02 12:00:00');
        config()->set('health.flexpick.pipeline_failure', [
            'window_hours' => 24, 'min_samples' => 5, 'fail_percent' => 40,
        ]);
        config()->set('health.flexpick.mail_failure', [
            'window_hours' => 24, 'min_samples' => 5, 'fail_percent' => 25,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * A request the pipeline actually attempted: it belongs in the denominator
     * regardless of how it ended.
     */
    private function auditWith(string $status, int $hoursAgo = 1): void
    {
        $request = AuditRequest::factory()->create(['status' => $status]);
        $request->forceFill([
            'analysis_started_at' => now()->subHours($hoursAgo),
            'created_at' => now()->subHours($hoursAgo),
            'updated_at' => now()->subHours($hoursAgo),
        ])->saveQuietly();
    }

    /**
     * A request that never started a run — `new`, `pending_verification` and
     * friends. It is not part of any rate over runs.
     */
    private function auditThatNeverRan(string $status, int $hoursAgo = 1): void
    {
        $request = AuditRequest::factory()->create(['status' => $status]);
        $request->forceFill([
            'analysis_started_at' => null,
            'created_at' => now()->subHours($hoursAgo),
            'updated_at' => now()->subHours($hoursAgo),
        ])->saveQuietly();
    }

    private function emailWith(string $status, int $hoursAgo = 1): void
    {
        $log = AuditEmailLog::create([
            'mailable' => 'TestMailable',
            'recipient' => 'ops@example.com',
            'subject' => 'x',
            'body' => 'x',
            'status' => $status,
            'attempts' => 1,
        ]);
        $log->forceFill([
            'created_at' => now()->subHours($hoursAgo),
            'updated_at' => now()->subHours($hoursAgo),
        ])->saveQuietly();
    }

    public function test_pipeline_check_is_ok_with_no_runs(): void
    {
        $this->assertSame('ok', (new AuditPipelineFailureRateCheck)->run()->status->value);
    }

    public function test_pipeline_check_is_ok_below_the_sample_floor(): void
    {
        // 4 runs, all failed — 100%, but below the floor of 5.
        for ($i = 0; $i < 4; $i++) {
            $this->auditWith(AuditRequestStatus::FAILED->value);
        }

        $result = (new AuditPipelineFailureRateCheck)->run();

        $this->assertSame('ok', $result->status->value);
        $this->assertSame(4, $result->meta['total']);
    }

    public function test_pipeline_check_fails_above_the_threshold(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->auditWith(AuditRequestStatus::FAILED->value);
        }
        // Counts in the denominator (the pipeline ran) but not the numerator.
        $this->auditWith(AuditRequestStatus::NEEDS_FOLLOWUP->value);
        $this->auditWith(AuditRequestStatus::SENT->value);

        $result = (new AuditPipelineFailureRateCheck)->run();

        $this->assertSame('failed', $result->status->value);
        $this->assertSame(5, $result->meta['total']);
        $this->assertSame(3, $result->meta['failed']);
        $this->assertSame(60, $result->meta['percent']);
    }

    /**
     * `needs_followup` is user error — a private repository, an oversized one,
     * a missing URL — not a system failure. A window full of them must not
     * trip a High-band alert, because that pins /health at 503 and masks the
     * staleness dead-man's switch.
     */
    public function test_pipeline_check_ignores_needs_followup_runs(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->auditWith(AuditRequestStatus::NEEDS_FOLLOWUP->value);
        }

        $result = (new AuditPipelineFailureRateCheck)->run();

        $this->assertSame('ok', $result->status->value);
        $this->assertSame(8, $result->meta['total']);
        $this->assertSame(0, $result->meta['failed']);
        $this->assertSame(0, $result->meta['percent']);
    }

    /**
     * The denominator is attempted runs, not submissions: requests still
     * waiting on verification never ran and cannot dilute the rate.
     */
    public function test_pipeline_check_excludes_requests_that_never_started_a_run(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->auditWith(AuditRequestStatus::FAILED->value);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->auditWith(AuditRequestStatus::SENT->value);
        }
        for ($i = 0; $i < 20; $i++) {
            $this->auditThatNeverRan(AuditRequestStatus::NEW->value);
        }

        $result = (new AuditPipelineFailureRateCheck)->run();

        $this->assertSame(6, $result->meta['total']);
        $this->assertSame(50, $result->meta['percent']);
        $this->assertSame('failed', $result->status->value);
    }

    public function test_pipeline_check_is_ok_at_or_below_the_threshold(): void
    {
        // 2 failed of 5 = 40%, which is not *above* 40.
        $this->auditWith(AuditRequestStatus::FAILED->value);
        $this->auditWith(AuditRequestStatus::FAILED->value);
        for ($i = 0; $i < 3; $i++) {
            $this->auditWith(AuditRequestStatus::SENT->value);
        }

        $this->assertSame('ok', (new AuditPipelineFailureRateCheck)->run()->status->value);
    }

    public function test_pipeline_check_ignores_runs_outside_the_window(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->auditWith(AuditRequestStatus::FAILED->value, hoursAgo: 30);
        }

        $result = (new AuditPipelineFailureRateCheck)->run();

        $this->assertSame('ok', $result->status->value);
        $this->assertSame(0, $result->meta['total']);
    }

    public function test_mail_check_fails_above_the_threshold(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->emailWith(AuditEmailLog::STATUS_FAILED);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->emailWith(AuditEmailLog::STATUS_SENT);
        }

        $result = (new MailFailureRateCheck)->run();

        $this->assertSame('failed', $result->status->value);
        $this->assertSame(50, $result->meta['percent']);
    }

    public function test_mail_check_is_ok_below_the_sample_floor(): void
    {
        $this->emailWith(AuditEmailLog::STATUS_FAILED);

        $this->assertSame('ok', (new MailFailureRateCheck)->run()->status->value);
    }
}
