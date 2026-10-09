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

The client_summary is written for a non-technical reader -- the business owner who paid for
the audit, not their engineer. Plain everyday language in every field: no tool names, file
paths, rule ids, jargon or code.
- verdict: one headline sentence on where the codebase stands and what matters most.
- overview: two or three sentences on the overall state of the codebase.
- areas: one entry for each dimension present in computed_scores, and none for a dimension
  absent from it. meaning says in one sentence what that area is and why an owner should care;
  status says how this codebase is doing on it.
- findings: three to five, the ones that matter most to the business, each with what is wrong
  in plain terms, what problems it may cause them (lost customers, outages, slow or costly
  changes, security or legal exposure), and what they gain by fixing it. urgency is now (risk
  of harm today), soon (will hurt within months) or later (worth fixing when convenient).
  business_area is the one the finding most affects: customers, costs, security or speed (how
  fast the team can ship changes).
- roadmap: the fix-first plan, same order, each step restated as what will be done, the
  business outcome it produces, and its effort (S, M or L, matching the plan).
- questions: three to five questions the owner can ask their development team about these
  findings.
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
            'client_summary' => [
                'type' => 'object',
                'properties' => [
                    'overview' => ['type' => 'string'],
                    'verdict' => ['type' => 'string'],
                    'areas' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'area' => ['type' => 'string', 'enum' => ['structure', 'duplication', 'testing', 'dependencies', 'security_hygiene']],
                                'meaning' => ['type' => 'string'],
                                'status' => ['type' => 'string'],
                            ],
                            'required' => ['area', 'meaning', 'status'],
                            'additionalProperties' => false,
                        ],
                    ],
                    'findings' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'what' => ['type' => 'string'],
                                'consequence' => ['type' => 'string'],
                                'gain' => ['type' => 'string'],
                                'urgency' => ['type' => 'string', 'enum' => ReportPayload::URGENCIES],
                                'business_area' => ['type' => 'string', 'enum' => ReportPayload::BUSINESS_AREAS],
                            ],
                            'required' => ['what', 'consequence', 'gain', 'urgency', 'business_area'],
                            'additionalProperties' => false,
                        ],
                    ],
                    'roadmap' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'step' => ['type' => 'string'],
                                'outcome' => ['type' => 'string'],
                                'effort' => ['type' => 'string', 'enum' => ['S', 'M', 'L']],
                            ],
                            'required' => ['step', 'outcome', 'effort'],
                            'additionalProperties' => false,
                        ],
                    ],
                    'questions' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required' => ['overview', 'verdict', 'areas', 'findings', 'roadmap', 'questions'],
                'additionalProperties' => false,
            ],
        ],
        'required' => ['summary', 'scores', 'risks', 'fix_first_plan', 'groups', 'client_summary'],
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
