<?php

namespace Tests\Feature\Models;

use App\Models\AuditEmailLog;
use Tests\Feature\FeatureTest;

class AuditEmailLogScopesTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();

        config()->set('health.flexpick.mail_failure.window_hours', 24);
    }

    public function test_failed_within_counts_failed_and_bounced_inside_the_default_window(): void
    {
        $failed = AuditEmailLog::factory()->create(['status' => AuditEmailLog::STATUS_FAILED, 'sent_at' => now()->subHours(2)]);
        $bounced = AuditEmailLog::factory()->create(['status' => AuditEmailLog::STATUS_BOUNCED, 'sent_at' => now()->subHours(2)]);
        $delivered = AuditEmailLog::factory()->create(['status' => AuditEmailLog::STATUS_DELIVERED, 'sent_at' => now()->subHours(2)]);

        $testIds = [$failed->id, $bounced->id, $delivered->id];
        $count = AuditEmailLog::query()->failedWithin()->whereIn('id', $testIds)->count();

        $this->assertSame(2, $count);
    }

    public function test_failed_within_excludes_a_failure_older_than_the_window(): void
    {
        $oldFailure = AuditEmailLog::factory()->create(['status' => AuditEmailLog::STATUS_FAILED, 'sent_at' => now()->subHours(25)]);

        $this->assertFalse(AuditEmailLog::query()->failedWithin()->pluck('id')->contains($oldFailure->id));
    }

    public function test_failed_within_accepts_an_explicit_window(): void
    {
        $old = AuditEmailLog::factory()->create(['status' => AuditEmailLog::STATUS_FAILED, 'sent_at' => now()->subHours(25)]);

        $this->assertTrue(AuditEmailLog::query()->failedWithin(168)->pluck('id')->contains($old->id));
    }

    public function test_a_pending_message_with_no_sent_at_is_in_neither_scope(): void
    {
        // Not attempted is not the same as failed. Counting a queued message as
        // a delivery failure would make the rate lie in both directions.
        $pending = AuditEmailLog::factory()->create([
            'status' => AuditEmailLog::STATUS_PENDING,
            'sent_at' => null,
        ]);

        $this->assertFalse(AuditEmailLog::query()->failedWithin()->pluck('id')->contains($pending->id));
        $this->assertFalse(AuditEmailLog::query()->attemptedWithin()->pluck('id')->contains($pending->id));
    }

    public function test_attempted_within_counts_every_status_that_was_actually_sent(): void
    {
        $delivered = AuditEmailLog::factory()->create(['status' => AuditEmailLog::STATUS_DELIVERED, 'sent_at' => now()->subHours(1)]);
        $failed = AuditEmailLog::factory()->create(['status' => AuditEmailLog::STATUS_FAILED, 'sent_at' => now()->subHours(1)]);
        $sent = AuditEmailLog::factory()->create(['status' => AuditEmailLog::STATUS_SENT, 'sent_at' => now()->subHours(30)]);

        $testIds = [$delivered->id, $failed->id, $sent->id];
        $count = AuditEmailLog::query()->attemptedWithin()->whereIn('id', $testIds)->count();

        $this->assertSame(2, $count);
    }
}
