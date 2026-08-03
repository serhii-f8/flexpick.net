<?php

namespace Tests\Feature\Health;

use App\Constants\AuditRequestStatus;
use App\Health\Checks\OldestPendingAuditCheck;
use App\Models\AuditRequest;
use Carbon\Carbon;
use Spatie\Health\Checks\Result;
use Tests\Feature\FeatureTest;

class OldestPendingAuditCheckTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        // FeatureTest does not roll back. Other tests leave audit rows behind
        // and this check reads the whole table, so start from a clean slate.
        AuditRequest::query()->forceDelete();

        Carbon::setTestNow('2026-08-02 12:00:00');
        config()->set('health.flexpick.oldest_queued_minutes', 30);
        config()->set('health.flexpick.oldest_analyzing_minutes', 30);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function runCheck(): Result
    {
        return (new OldestPendingAuditCheck)->run();
    }

    public function test_ok_when_there_are_no_requests(): void
    {
        $this->assertSame('ok', $this->runCheck()->status->value);
    }

    public function test_ok_when_queued_request_is_within_the_window(): void
    {
        $request = AuditRequest::factory()->create(['status' => AuditRequestStatus::QUEUED->value]);
        $request->forceFill(['updated_at' => now()->subMinutes(29)])->saveQuietly();

        $this->assertSame('ok', $this->runCheck()->status->value);
    }

    public function test_fails_when_queued_request_exceeds_the_window(): void
    {
        $request = AuditRequest::factory()->create(['status' => AuditRequestStatus::QUEUED->value]);
        $request->forceFill(['updated_at' => now()->subMinutes(31)])->saveQuietly();

        $result = $this->runCheck();

        $this->assertSame('failed', $result->status->value);
        $this->assertSame(31, $result->meta['queued_age_minutes']);
    }

    public function test_fails_when_analyzing_request_is_stranded(): void
    {
        AuditRequest::factory()->create([
            'status' => AuditRequestStatus::ANALYZING->value,
            'analysis_started_at' => now()->subMinutes(45),
        ]);

        $result = $this->runCheck();

        $this->assertSame('failed', $result->status->value);
        $this->assertSame(45, $result->meta['analyzing_age_minutes']);
    }

    /**
     * Without this, the check fires forever on historical data — every audit
     * ever completed is "old".
     */
    public function test_ignores_old_requests_in_terminal_states(): void
    {
        foreach ([
            AuditRequestStatus::SENT,
            AuditRequestStatus::HANDLED,
            AuditRequestStatus::FAILED,
            AuditRequestStatus::REPORT_READY,
        ] as $status) {
            $request = AuditRequest::factory()->create([
                'status' => $status->value,
                'analysis_started_at' => now()->subDays(30),
            ]);
            $request->forceFill(['updated_at' => now()->subDays(30)])->saveQuietly();
        }

        $this->assertSame('ok', $this->runCheck()->status->value);
    }
}
