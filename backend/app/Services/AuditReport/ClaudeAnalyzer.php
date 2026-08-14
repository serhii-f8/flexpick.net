<?php

namespace App\Services\AuditReport;

use App\Exceptions\AiAnalysisException;
use App\Services\AuditReport\Tiers\TierProfile;

class ClaudeAnalyzer implements AiAnalyzer
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
You are a senior software auditor producing a codebase health report for a prospective client.
You are given repository metrics measured by static analysis, the ranked problem groups those
analyzers produced, and excerpts of the largest files. Ground every score, risk, and
recommendation in the provided material — never invent facts about code you have not seen.

Narrate each problem group: what it is, what it affects, and what fixing it buys the client.
Never enumerate individual findings; one lint error must never become one report item.

The metrics include computed_scores measured deterministically; treat them as authoritative —
output them verbatim as your scores. A dimension absent from computed_scores was NOT measured
on this run: omit it from your scores entirely rather than estimating it, and do not describe
it as healthy. Scores are 0-100, higher is healthier. Rank risks by impact. The fix-first plan
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
                'required' => ['overall'],
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
            'groups' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'rule_family' => ['type' => 'string'],
                        'directory' => ['type' => 'string'],
                        'severity' => ['type' => 'string', 'enum' => ['critical', 'high', 'medium', 'low', 'info']],
                        'count' => ['type' => 'integer'],
                        'narrative' => [
                            'type' => 'object',
                            'properties' => [
                                'what' => ['type' => 'string'],
                                'affects' => ['type' => 'string'],
                                'benefit' => ['type' => 'string'],
                            ],
                            'required' => ['what', 'affects', 'benefit'],
                            'additionalProperties' => false,
                        ],
                    ],
                    'required' => ['rule_family', 'directory', 'severity', 'count', 'narrative'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => ['summary', 'scores', 'risks', 'fix_first_plan', 'groups'],
        'additionalProperties' => false,
    ];

    public function __construct(
        private PromptComposer $promptComposer,
        private AnthropicClientFactory $clients,
    ) {}

    public function analyze(
        array $metrics,
        array $groups,
        array $excerpts,
        TierProfile $tier,
        ?string $adminContext = null,
    ): AnalysisResult {
        $message = $this->clients->make()->messages->create(
            model: (string) config('services.anthropic.model'),
            // Per-tier budget, never hardcoded (F5.12.1).
            maxTokens: $tier->aiMaxTokens,
            thinking: ['type' => 'adaptive'],
            system: self::SYSTEM_PROMPT,
            messages: [[
                'role' => 'user',
                'content' => $this->promptComposer->compose($metrics, $groups, $excerpts, $adminContext),
            ]],
            outputConfig: ['format' => ['type' => 'json_schema', 'schema' => self::SCHEMA]],
        );

        if ($message->stopReason !== 'end_turn') {
            throw new AiAnalysisException('Analysis stopped early: '.$message->stopReason);
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                return new AnalysisResult(
                    payload: ReportPayload::validate(json_decode($block->text, true)),
                    inputTokens: (int) ($message->usage->inputTokens ?? 0),
                    outputTokens: (int) ($message->usage->outputTokens ?? 0),
                );
            }
        }

        throw new AiAnalysisException('Analysis returned no text content');
    }
}
