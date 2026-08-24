<?php

namespace Tests\Feature\Models;

use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Models\AuditEmailLog;
use App\Models\AuditRequest;
use Tests\Feature\FeatureTest;

class AuditRequestScopesTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();

        config()->set('health.flexpick.oldest_queued_minutes', 30);
        config()->set('health.flexpick.oldest_analyzing_minutes', 30);
        config()->set('audit.expert_review_sla_hours', 24);
    }

    public function test_stuck_finds_a_queued_request_past_the_threshold(): void
    {
        $stuck = AuditRequest::factory()->create([
            'status' => AuditRequestStatus::QUEUED->value,
            'created_at' => now()->subMinutes(31),
        ]);

        $this->assertTrue(AuditRequest::query()->stuck()->pluck('id')->contains($stuck->id));
    }

    public function test_stuck_excludes_a_request_exactly_at_the_threshold(): void
    {
        $atThreshold = AuditRequest::factory()->create([
            'status' => AuditRequestStatus::QUEUED->value,
            'created_at' => now()->subMinutes(30),
        ]);

        $this->assertFalse(AuditRequest::query()->stuck()->pluck('id')->contains($atThreshold->id));
    }

    public function test_stuck_ages_an_analyzing_request_off_updated_at_when_the_start_was_never_stamped(): void
    {
        $neverStamped = AuditRequest::factory()->create([
            'status' => AuditRequestStatus::ANALYZING->value,
            'analysis_started_at' => null,
            'created_at' => now()->subMinutes(90),
            'updated_at' => now()->subMinutes(90),
        ]);

        // A pipeline that died before stamping its start must still age into
        // the bucket rather than hiding there forever.
        $this->assertTrue(AuditRequest::query()->stuck()->pluck('id')->contains($neverStamped->id));
    }

    public function test_stuck_prefers_analysis_started_at_over_updated_at(): void
    {
        $recent = AuditRequest::factory()->create([
            'status' => AuditRequestStatus::ANALYZING->value,
            'analysis_started_at' => now()->subMinutes(5),
            'created_at' => now()->subMinutes(90),
            'updated_at' => now()->subMinutes(90),
        ]);

        $this->assertFalse(AuditRequest::query()->stuck()->pluck('id')->contains($recent->id));
    }

    public function test_stuck_ignores_terminal_statuses(): void
    {
        $terminal = AuditRequest::factory()->create([
            'status' => AuditRequestStatus::SENT->value,
            'created_at' => now()->subDays(5),
        ]);

        $this->assertFalse(AuditRequest::query()->stuck()->pluck('id')->contains($terminal->id));
    }

    public function test_needs_manual_action_covers_exactly_the_three_operator_statuses(): void
    {
        $needsFollowup = AuditRequest::factory()->create(['status' => AuditRequestStatus::NEEDS_FOLLOWUP->value]);
        $awaitingAccess = AuditRequest::factory()->create(['status' => AuditRequestStatus::AWAITING_ACCESS->value]);
        $awaitingPayment = AuditRequest::factory()->create(['status' => AuditRequestStatus::AWAITING_PAYMENT->value]);
        $sent = AuditRequest::factory()->create(['status' => AuditRequestStatus::SENT->value]);

        $testIds = [$needsFollowup->id, $awaitingAccess->id, $awaitingPayment->id, $sent->id];
        $count = AuditRequest::query()->needsManualAction()->whereIn('id', $testIds)->count();

        $this->assertSame(3, $count);
    }

    public function test_breaching_expert_review_sla_finds_an_overdue_expert_review(): void
    {
        $overdue = AuditRequest::factory()->create([
            'tier' => AuditTier::EXPERT->value,
            'status' => AuditRequestStatus::EXPERT_REVIEW->value,
            'analysis_completed_at' => now()->subHours(25),
        ]);

        $this->assertTrue(AuditRequest::query()->breachingExpertReviewSla()->pluck('id')->contains($overdue->id));
    }

    public function test_breaching_expert_review_sla_excludes_a_review_within_the_window(): void
    {
        $withinWindow = AuditRequest::factory()->create([
            'tier' => AuditTier::EXPERT->value,
            'status' => AuditRequestStatus::EXPERT_REVIEW->value,
            'analysis_completed_at' => now()->subHours(23),
        ]);

        $this->assertFalse(AuditRequest::query()->breachingExpertReviewSla()->pluck('id')->contains($withinWindow->id));
    }

    public function test_breaching_expert_review_sla_ignores_non_expert_tiers(): void
    {
        $diagnostic = AuditRequest::factory()->create([
            'tier' => AuditTier::DIAGNOSTIC->value,
            'status' => AuditRequestStatus::EXPERT_REVIEW->value,
            'analysis_completed_at' => now()->subHours(48),
        ]);

        $this->assertFalse(AuditRequest::query()->breachingExpertReviewSla()->pluck('id')->contains($diagnostic->id));
    }

    public function test_email_logs_relation_returns_this_requests_messages(): void
    {
        $request = AuditRequest::factory()->create();
        AuditEmailLog::factory()->create(['audit_request_id' => $request->id]);
        AuditEmailLog::factory()->create(['audit_request_id' => null]);

        $this->assertCount(1, $request->emailLogs);
    }
}
