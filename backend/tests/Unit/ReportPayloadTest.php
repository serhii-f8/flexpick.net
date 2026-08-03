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
}
