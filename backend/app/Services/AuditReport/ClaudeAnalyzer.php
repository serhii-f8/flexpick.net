<?php

namespace App\Services\AuditReport;

use Anthropic\Client;
use App\Exceptions\AiAnalysisException;

class ClaudeAnalyzer implements AiAnalyzer
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
You are a senior software auditor producing a codebase health report for a prospective client.
You are given repository metrics measured by static analysis, plus excerpts of the largest files.
Ground every score, risk, and recommendation in the provided metrics and excerpts — never invent
facts about code you have not seen. Frame findings as assessment based on automated analysis,
not guarantees. Scores are 0-100 (higher is healthier). Rank risks by impact. The fix-first plan
must be concrete and ordered by leverage.
PROMPT;

    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'summary' => ['type' => 'string'],
            'scores' => [
                'type' => 'object',
                'properties' => [
                    'structure' => ['type' => 'integer'],
                    'duplication' => ['type' => 'integer'],
                    'testing' => ['type' => 'integer'],
                    'dependencies' => ['type' => 'integer'],
                    'security_hygiene' => ['type' => 'integer'],
                    'overall' => ['type' => 'integer'],
                ],
                'required' => ['structure', 'duplication', 'testing', 'dependencies', 'security_hygiene', 'overall'],
                'additionalProperties' => false,
            ],
            'risks' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'impact' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                        'evidence' => ['type' => 'string'],
                        'recommendation' => ['type' => 'string'],
                    ],
                    'required' => ['title', 'impact', 'evidence', 'recommendation'],
                    'additionalProperties' => false,
                ],
            ],
            'fix_first_plan' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'step' => ['type' => 'string'],
                        'why' => ['type' => 'string'],
                        'effort' => ['type' => 'string', 'enum' => ['S', 'M', 'L']],
                    ],
                    'required' => ['step', 'why', 'effort'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => ['summary', 'scores', 'risks', 'fix_first_plan'],
        'additionalProperties' => false,
    ];

    public function analyze(array $metrics, array $excerpts): array
    {
        $client = new Client(apiKey: (string) config('services.anthropic.api_key'));

        $message = $client->messages->create(
            model: (string) config('services.anthropic.model'),
            maxTokens: 16000,
            thinking: ['type' => 'adaptive'],
            system: self::SYSTEM_PROMPT,
            messages: [['role' => 'user', 'content' => $this->buildPrompt($metrics, $excerpts)]],
            outputConfig: ['format' => ['type' => 'json_schema', 'schema' => self::SCHEMA]],
        );

        if ($message->stopReason !== 'end_turn') {
            throw new AiAnalysisException('Analysis stopped early: '.$message->stopReason);
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                return ReportPayload::validate(json_decode($block->text, true));
            }
        }

        throw new AiAnalysisException('Analysis returned no text content');
    }

    private function buildPrompt(array $metrics, array $excerpts): string
    {
        $excerptText = '';
        foreach ($excerpts as $excerpt) {
            $excerptText .= "\n===== {$excerpt['path']} =====\n{$excerpt['content']}\n";
        }

        return "Repository metrics (JSON):\n"
            .json_encode($metrics, JSON_PRETTY_PRINT)
            ."\n\nFile excerpts (largest files, truncated):\n"
            .$excerptText
            ."\n\nProduce the codebase health report.";
    }
}
