<?php

namespace Tests\Unit\Services\AuditReport;

use App\Services\AuditReport\ClaudeAnalyzer;
use App\Services\AuditReport\ReportPayload;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;

/**
 * The analyzer talks to the real API, so its contract is pinned here: the
 * output schema it asks the model for must be able to produce every section
 * ReportPayload expects, and the instructions must ask for them.
 */
class ClaudeAnalyzerContractTest extends TestCase
{
    private function schema(): array
    {
        return (new ReflectionClassConstant(ClaudeAnalyzer::class, 'SCHEMA'))->getValue();
    }

    private function systemPrompt(): string
    {
        return (new ReflectionClassConstant(ClaudeAnalyzer::class, 'SYSTEM_PROMPT'))->getValue();
    }

    public function test_the_output_schema_requires_a_plain_language_client_summary(): void
    {
        $schema = $this->schema();

        $this->assertContains('client_summary', $schema['required']);

        $summary = $schema['properties']['client_summary'];
        $this->assertSame(['overview', 'findings'], $summary['required']);
        $this->assertSame('string', $summary['properties']['overview']['type']);

        $finding = $summary['properties']['findings']['items'];
        $this->assertSame(['what', 'consequence', 'gain'], $finding['required']);
        $this->assertFalse($finding['additionalProperties']);
    }

    public function test_a_payload_shaped_by_the_schema_satisfies_the_payload_contract(): void
    {
        $payload = [
            'summary' => 'ok',
            'scores' => ['overall' => 50],
            'risks' => [],
            'fix_first_plan' => [],
            'groups' => [],
            'client_summary' => [
                'overview' => 'Plain words.',
                'findings' => [['what' => 'w', 'consequence' => 'c', 'gain' => 'g']],
            ],
        ];

        $this->assertSame($payload, ReportPayload::validate($payload));
    }

    public function test_the_instructions_ask_for_a_non_technical_summary(): void
    {
        $prompt = $this->systemPrompt();

        $this->assertStringContainsString('client_summary', $prompt);
        $this->assertStringContainsString('non-technical', $prompt);
    }
}
