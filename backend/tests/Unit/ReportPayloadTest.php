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

    private function clientSummary(array $findingOverrides = []): array
    {
        return [
            'overview' => 'Your app works today, but a few weak spots make changes slow and risky.',
            'findings' => [
                array_merge([
                    'what' => 'Almost nothing is covered by automated tests.',
                    'consequence' => 'Every change can quietly break something customers rely on.',
                    'gain' => 'Ship updates faster, with far fewer surprises.',
                ], $findingOverrides),
            ],
        ];
    }

    public function test_accepts_a_plain_language_client_summary(): void
    {
        $payload = $this->valid() + ['client_summary' => $this->clientSummary()];

        $this->assertSame($payload, ReportPayload::validate($payload, 5));
    }

    public function test_client_summary_is_optional_in_v5(): void
    {
        // Reports stored before the section existed keep rendering.
        $this->assertSame($this->valid(), ReportPayload::validate($this->valid(), 5));
    }

    public function test_client_summary_is_ignored_by_v4(): void
    {
        $payload = $this->valid() + ['client_summary' => 'not even an object'];

        $this->assertSame($payload, ReportPayload::validate($payload, 4));
    }

    public function test_rejects_a_client_summary_without_an_overview(): void
    {
        $summary = $this->clientSummary();
        unset($summary['overview']);

        $this->expectException(AiAnalysisException::class);
        $this->expectExceptionMessage('client_summary');
        ReportPayload::validate($this->valid() + ['client_summary' => $summary], 5);
    }

    public function test_rejects_a_client_summary_finding_missing_a_field(): void
    {
        foreach (['what', 'consequence', 'gain'] as $field) {
            try {
                ReportPayload::validate($this->valid() + ['client_summary' => $this->clientSummary([$field => null])], 5);
                $this->fail("Expected rejection for a finding without {$field}");
            } catch (AiAnalysisException $e) {
                $this->assertStringContainsString('client_summary', $e->getMessage());
            }
        }
    }

    public function test_default_version_is_now_six(): void
    {
        $this->assertSame(6, ReportPayload::VERSION);
    }

    private function v6Summary(): array
    {
        return [
            'overview' => 'Plain overview.',
            'verdict' => 'Solid, but two risks need attention.',
            'areas' => [
                ['area' => 'testing', 'meaning' => 'Automatic checks.', 'status' => 'Barely any.'],
            ],
            'findings' => [[
                'what' => 'w', 'consequence' => 'c', 'gain' => 'g',
                'urgency' => 'now', 'business_area' => 'customers',
            ]],
            'roadmap' => [['step' => 'Add checks', 'outcome' => 'Fewer surprises', 'effort' => 'M']],
            'questions' => ['Which parts are tested?'],
        ];
    }

    public function test_accepts_a_v6_client_summary(): void
    {
        $payload = $this->valid() + ['client_summary' => $this->v6Summary()];

        $this->assertSame($payload, ReportPayload::validate($payload));
    }

    public function test_a_v5_client_summary_still_validates_under_v6(): void
    {
        $payload = $this->valid() + ['client_summary' => [
            'overview' => 'o',
            'findings' => [['what' => 'w', 'consequence' => 'c', 'gain' => 'g']],
        ]];

        $this->assertSame($payload, ReportPayload::validate($payload, 6));
    }

    public function test_rejects_an_unknown_urgency(): void
    {
        $summary = $this->v6Summary();
        $summary['findings'][0]['urgency'] = 'yesterday';

        $this->expectException(AiAnalysisException::class);
        ReportPayload::validate($this->valid() + ['client_summary' => $summary]);
    }

    public function test_rejects_an_unknown_business_area(): void
    {
        $summary = $this->v6Summary();
        $summary['findings'][0]['business_area'] = 'vibes';

        $this->expectException(AiAnalysisException::class);
        ReportPayload::validate($this->valid() + ['client_summary' => $summary]);
    }

    public function test_rejects_a_malformed_roadmap_step(): void
    {
        $summary = $this->v6Summary();
        $summary['roadmap'][0]['effort'] = 'XL';

        $this->expectException(AiAnalysisException::class);
        ReportPayload::validate($this->valid() + ['client_summary' => $summary]);
    }

    public function test_rejects_a_non_string_question(): void
    {
        $summary = $this->v6Summary();
        $summary['questions'] = [42];

        $this->expectException(AiAnalysisException::class);
        ReportPayload::validate($this->valid() + ['client_summary' => $summary]);
    }

    public function test_rejects_an_area_that_is_not_a_score_dimension(): void
    {
        $summary = $this->v6Summary();
        $summary['areas'][0]['area'] = 'overall';

        $this->expectException(AiAnalysisException::class);
        ReportPayload::validate($this->valid() + ['client_summary' => $summary]);
    }

    public function test_drops_an_area_for_a_dimension_that_was_not_measured(): void
    {
        $payload = $this->valid();
        unset($payload['scores']['testing']);
        $payload['client_summary'] = $this->v6Summary();

        $validated = ReportPayload::validate($payload);

        $this->assertSame([], $validated['client_summary']['areas']);
    }
}
