<?php

namespace Tests\Unit;

use App\Exceptions\AiAnalysisException;
use App\Services\AuditReport\ReportPayload;
use PHPUnit\Framework\TestCase;

class ReportPayloadTest extends TestCase
{
    private function valid(): array
    {
        return [
            'summary' => 'ok',
            'scores' => ['structure' => 1, 'duplication' => 2, 'testing' => 3, 'dependencies' => 4, 'security_hygiene' => 5, 'overall' => 3],
            'risks' => [['title' => 't', 'impact' => 'high', 'evidence' => 'e', 'recommendation' => 'r']],
            'fix_first_plan' => [['step' => 's', 'why' => 'w', 'effort' => 'S']],
            'groups' => [],
        ];
    }

    public function test_accepts_valid_payload(): void
    {
        $this->assertSame($this->valid(), ReportPayload::validate($this->valid()));
    }

    public function test_rejects_missing_scores(): void
    {
        $payload = $this->valid();
        unset($payload['scores']['overall']);

        $this->expectException(AiAnalysisException::class);
        ReportPayload::validate($payload);
    }

    public function test_rejects_bad_impact(): void
    {
        $payload = $this->valid();
        $payload['risks'][0]['impact'] = 'catastrophic';

        $this->expectException(AiAnalysisException::class);
        ReportPayload::validate($payload);
    }

    public function test_accepts_valid_payload_with_expert_review(): void
    {
        $payload = $this->valid();
        $payload['expert_review'] = [
            'expert_summary' => 'Reviewed and solid.',
            'review_notes' => 'Nothing further to add.',
            'reviewed_by' => 'Jane Reviewer',
            'reviewed_at' => '2026-08-05T12:00:00+00:00',
        ];

        $validated = ReportPayload::validate($payload, 4);

        $this->assertSame($payload['expert_review'], $validated['expert_review']);
    }

    public function test_expert_review_is_optional_in_v4(): void
    {
        $this->assertSame($this->valid(), ReportPayload::validate($this->valid(), 4));
    }

    public function test_rejects_expert_review_missing_a_field(): void
    {
        $payload = $this->valid();
        $payload['expert_review'] = [
            'expert_summary' => 'ok',
            'review_notes' => 'ok',
            'reviewed_by' => 'Jane',
            // reviewed_at missing
        ];

        $this->expectException(AiAnalysisException::class);
        ReportPayload::validate($payload, 4);
    }

    public function test_rejects_expert_review_with_non_string_field(): void
    {
        $payload = $this->valid();
        $payload['expert_review'] = [
            'expert_summary' => 'ok',
            'review_notes' => 'ok',
            'reviewed_by' => 'Jane',
            'reviewed_at' => 12345, // not a string
        ];

        $this->expectException(AiAnalysisException::class);
        ReportPayload::validate($payload, 4);
    }

    public function test_default_version_is_now_four(): void
    {
        $this->assertSame(4, ReportPayload::VERSION);
    }
}
