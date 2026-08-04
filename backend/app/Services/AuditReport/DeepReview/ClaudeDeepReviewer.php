<?php

namespace App\Services\AuditReport\DeepReview;

use Anthropic\Client;
use App\Exceptions\AiAnalysisException;
use App\Services\AuditReport\Findings\FindingGroup;

class ClaudeDeepReviewer implements DeepReviewer
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
You are a senior software auditor performing a deep review of the riskiest files in a client's
repository. You are given the file contents, the deterministic metrics for the repository, and
the ranked problem groups its static analyzers produced.

Review the SOURCE. Report findings bound to specific files, covering business logic,
authorization, architectural risk, and security. Every finding must cite concrete evidence from
the code you were shown — never speculate about code you have not seen, and never report a
finding against a file that was not provided to you.

Prefer findings that span modules: a controller trusting a value another file never validates is
worth more than a single-file style issue, and the scanners have already covered what a linter
can find. Where the problem groups flag something in a file you can see, confirm or refute it
against the actual source rather than restating it.

Size effort honestly: S is under an hour, M is up to a day, L is more. Rank by severity.
PROMPT;

    public function __construct(private DeepReviewPromptComposer $composer) {}

    public function review(
        array $metrics,
        array $groups,
        RiskFileSelection $selection,
        DeepReviewProfile $profile,
    ): DeepReviewResult {
        if ($selection->files === []) {
            return new DeepReviewResult(findings: [], inputTokens: 0, outputTokens: 0);
        }

        try {
            return $this->call($metrics, $groups, $selection, $profile);
        } catch (AiAnalysisException $e) {
            // Schema and contract failures fail identically on retry; retrying
            // only doubles the token spend.
            throw $e;
        } catch (\Throwable $e) {
            // One bounded retry for transport-level failures only.
            usleep(2_000_000);

            return $this->call($metrics, $groups, $selection, $profile);
        }
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @param  list<FindingGroup>  $groups
     */
    private function call(
        array $metrics,
        array $groups,
        RiskFileSelection $selection,
        DeepReviewProfile $profile,
    ): DeepReviewResult {
        $client = new Client(apiKey: (string) config('services.anthropic.api_key'));

        $message = $client->messages->create(
            model: (string) config('services.anthropic.model'),
            maxTokens: $profile->maxTokens,
            thinking: ['type' => 'adaptive'],
            system: self::SYSTEM_PROMPT,
            messages: [[
                'role' => 'user',
                'content' => $this->composer->compose($metrics, $groups, $selection),
            ]],
            outputConfig: ['format' => ['type' => 'json_schema', 'schema' => $this->schema()]],
        );

        if ($message->stopReason !== 'end_turn') {
            throw new AiAnalysisException('Deep review stopped early: '.$message->stopReason);
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $decoded = json_decode($block->text, true);

                if (! is_array($decoded) || ! is_array($decoded['file_findings'] ?? null)) {
                    throw new AiAnalysisException('Deep review returned no file_findings');
                }

                return new DeepReviewResult(
                    findings: array_values($decoded['file_findings']),
                    inputTokens: (int) ($message->usage->inputTokens ?? 0),
                    outputTokens: (int) ($message->usage->outputTokens ?? 0),
                );
            }
        }

        throw new AiAnalysisException('Deep review returned no text content');
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'file_findings' => [
                    'type' => 'array',
                    // Bounds the output so the response cannot hit max_tokens
                    // and arrive as truncated, unparseable JSON.
                    'maxItems' => (int) config('audit.deep_review.max_findings'),
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => ['type' => 'string'],
                            'line' => ['type' => ['integer', 'null']],
                            'title' => ['type' => 'string'],
                            'severity' => ['type' => 'string', 'enum' => ['critical', 'high', 'medium', 'low', 'info']],
                            'category' => ['type' => 'string', 'enum' => ['business_logic', 'authorization', 'architecture', 'security']],
                            'evidence' => ['type' => 'string'],
                            'recommendation' => ['type' => 'string'],
                            'effort' => ['type' => 'string', 'enum' => ['S', 'M', 'L']],
                            'related_paths' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                        'required' => ['path', 'title', 'severity', 'category', 'evidence', 'recommendation', 'effort', 'related_paths'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['file_findings'],
            'additionalProperties' => false,
        ];
    }
}
