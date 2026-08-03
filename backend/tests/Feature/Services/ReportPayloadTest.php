<?php

namespace Tests\Feature\Services;

use App\Exceptions\AiAnalysisException;
use App\Services\AuditReport\ReportPayload;
use Tests\Feature\FeatureTest;

class ReportPayloadTest extends FeatureTest
{
    private function v1Payload(): array
    {
        return [
            'summary' => 'The codebase is serviceable but under-tested.',
            'scores' => [
                'structure' => 70, 'duplication' => 60, 'testing' => 40,
                'dependencies' => 80, 'security_hygiene' => 90, 'overall' => 68,
            ],
            'risks' => [
                ['title' => 'No tests', 'impact' => 'high', 'evidence' => 'Test ratio 4%.',
                    'recommendation' => 'Add characterization tests.'],
            ],
            'fix_first_plan' => [
                ['step' => 'Add tests to the checkout path', 'why' => 'Highest churn.', 'effort' => 'M'],
            ],
        ];
    }

    private function v2Payload(): array
    {
        return $this->v1Payload() + [
            'groups' => [
                [
                    'rule_family' => 'php.injection',
                    'directory' => 'app/Http',
                    'severity' => 'high',
                    'count' => 37,
                    'narrative' => [
                        'what' => 'SQL assembled by string interpolation.',
                        'affects' => 'Every controller reachable from the public API.',
                        'benefit' => 'Removes the most direct route to data exfiltration.',
                    ],
                ],
            ],
        ];
    }

    public function test_a_v2_payload_validates(): void
    {
        $this->assertIsArray(ReportPayload::validate($this->v2Payload(), 2));
    }

    public function test_a_v1_payload_still_validates_under_v1(): void
    {
        // Historical reports render on every view; dropping v1 validation
        // breaks every report already delivered (spec §7.4).
        $this->assertIsArray(ReportPayload::validate($this->v1Payload(), 1));
    }

    public function test_a_v1_payload_is_rejected_under_v2(): void
    {
        $this->expectException(AiAnalysisException::class);

        ReportPayload::validate($this->v1Payload(), 2);
    }

    public function test_version_defaults_to_the_current_contract(): void
    {
        $this->assertSame(2, ReportPayload::VERSION);
        $this->assertIsArray(ReportPayload::validate($this->v2Payload()));
    }

    public function test_v2_allows_partial_scores_for_not_measured_dimensions(): void
    {
        // A diagnostic run legitimately has no duplication or security score
        // (spec §7.2). Requiring all six would fail every free-tier report.
        $payload = $this->v2Payload();
        unset($payload['scores']['duplication'], $payload['scores']['security_hygiene']);

        $this->assertIsArray(ReportPayload::validate($payload, 2));
    }

    public function test_v2_still_requires_an_overall_score(): void
    {
        $payload = $this->v2Payload();
        unset($payload['scores']['overall']);

        $this->expectException(AiAnalysisException::class);

        ReportPayload::validate($payload, 2);
    }

    public function test_rejects_a_malformed_group(): void
    {
        $payload = $this->v2Payload();
        unset($payload['groups'][0]['narrative']['benefit']);

        $this->expectException(AiAnalysisException::class);

        ReportPayload::validate($payload, 2);
    }

    public function test_rejects_a_group_with_an_unknown_severity(): void
    {
        $payload = $this->v2Payload();
        $payload['groups'][0]['severity'] = 'catastrophic';

        $this->expectException(AiAnalysisException::class);

        ReportPayload::validate($payload, 2);
    }

    public function test_rejects_a_non_array_payload(): void
    {
        $this->expectException(AiAnalysisException::class);

        ReportPayload::validate('not an object');
    }

    public function test_rejects_a_malformed_risk_entry(): void
    {
        $payload = $this->v2Payload();
        $payload['risks'][0]['impact'] = 'apocalyptic';

        $this->expectException(AiAnalysisException::class);

        ReportPayload::validate($payload, 2);
    }
}
