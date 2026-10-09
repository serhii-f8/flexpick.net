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

    public function test_the_output_schema_requires_the_business_report_sections(): void
    {
        $schema = $this->schema();

        $this->assertContains('client_summary', $schema['required']);

        $summary = $schema['properties']['client_summary'];
        $this->assertSame(['overview', 'verdict', 'areas', 'findings', 'roadmap', 'questions'], $summary['required']);
        $this->assertFalse($summary['additionalProperties']);

        $finding = $summary['properties']['findings']['items'];
        $this->assertSame(['what', 'consequence', 'gain', 'urgency', 'business_area'], $finding['required']);
        $this->assertSame(ReportPayload::URGENCIES, $finding['properties']['urgency']['enum']);
        $this->assertSame(ReportPayload::BUSINESS_AREAS, $finding['properties']['business_area']['enum']);
        $this->assertFalse($finding['additionalProperties']);

        $area = $summary['properties']['areas']['items'];
        $this->assertSame(['area', 'meaning', 'status'], $area['required']);
        $this->assertNotContains('overall', $area['properties']['area']['enum']);

        $step = $summary['properties']['roadmap']['items'];
        $this->assertSame(['step', 'outcome', 'effort'], $step['required']);
        $this->assertSame(['S', 'M', 'L'], $step['properties']['effort']['enum']);

        $this->assertSame('string', $summary['properties']['questions']['items']['type']);
    }

    public function test_the_instructions_ask_for_every_business_section(): void
    {
        $prompt = $this->systemPrompt();

        foreach (['verdict', 'areas', 'urgency', 'business_area', 'roadmap', 'questions'] as $field) {
            $this->assertStringContainsString($field, $prompt);
        }
        // Areas must follow computed_scores, never describe an unmeasured one.
        $this->assertStringContainsString('computed_scores', $prompt);
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
                'verdict' => 'Sound, with gaps.',
                'areas' => [],
                'findings' => [['what' => 'w', 'consequence' => 'c', 'gain' => 'g', 'urgency' => 'soon', 'business_area' => 'costs']],
                'roadmap' => [['step' => 's', 'outcome' => 'o', 'effort' => 'S']],
                'questions' => ['q?'],
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
