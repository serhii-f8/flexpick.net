<?php

namespace Tests\Feature\Services\DeepReview;

use App\Services\AuditReport\DeepReview\DeepReviewPromptComposer;
use App\Services\AuditReport\DeepReview\RiskFileSelection;
use App\Services\AuditReport\DeepReview\SelectedFile;
use App\Services\AuditReport\Findings\FindingGroup;
use App\Services\AuditReport\Findings\Severity;
use ReflectionMethod;
use ReflectionParameter;
use Tests\Feature\FeatureTest;

class DeepReviewPromptComposerTest extends FeatureTest
{
    /** @param list<SelectedFile> $files */
    private function selection(array $files): RiskFileSelection
    {
        return new RiskFileSelection(
            files: $files,
            candidatesConsidered: count($files),
            selectedBeforeBudget: count($files),
            truncated: false,
            belowFloor: false,
            estimatedInputTokens: 100,
            fileBytesUsed: 1000,
            selectionVersion: 1,
        );
    }

    private function file(string $path, string $content): SelectedFile
    {
        return new SelectedFile(
            path: $path,
            rank: 1,
            score: 0.9,
            signals: [
                'churn' => ['raw' => 5.0, 'normalized' => 0.5],
                'findings' => ['raw' => 2.0, 'normalized' => 1.0],
                'sensitive' => ['raw' => 1.0, 'normalized' => 1.0],
            ],
            content: $content,
            estimatedTokens: 50,
        );
    }

    public function test_composes_metrics_files_and_groups(): void
    {
        $selection = $this->selection([
            $this->file('app/Auth/Guard.php', "<?php\nclass Guard {}\n"),
        ]);

        $prompt = app(DeepReviewPromptComposer::class)->compose(
            ['files_total' => 42],
            [new FindingGroup('php.injection', 'app/Http', Severity::HIGH, 3, 100, [], ['semgrep'], 'security_hygiene')],
            $selection,
        );

        $this->assertStringContainsString('Repository metrics (JSON):', $prompt);
        $this->assertStringContainsString('"files_total": 42', $prompt);
        $this->assertStringContainsString('app/Auth/Guard.php', $prompt);
        $this->assertStringContainsString('class Guard {}', $prompt);
        $this->assertStringContainsString('php.injection', $prompt);
        $this->assertStringContainsString('app/Http', $prompt);
    }

    public function test_files_are_rendered_in_rank_order_with_their_selection_reason(): void
    {
        $selection = $this->selection([
            $this->file('app/Auth/Guard.php', 'guard-body'),
        ]);

        $prompt = app(DeepReviewPromptComposer::class)->compose([], [], $selection);

        $this->assertStringContainsString('rank 1: app/Auth/Guard.php', $prompt);
        $this->assertStringContainsString('churn, findings, sensitive', $prompt);
        $this->assertStringContainsString('guard-body', $prompt);
    }

    public function test_an_empty_group_list_and_selection_still_composes(): void
    {
        $prompt = app(DeepReviewPromptComposer::class)->compose(['files_total' => 0], [], $this->selection([]));

        $this->assertStringContainsString('no problem groups were produced for this run', $prompt);
        $this->assertStringContainsString('The 0 riskiest files', $prompt);
    }

    public function test_the_signature_carries_no_tier_one_narrative_parameter(): void
    {
        // Design constraint (spec D6): this prompt is deterministic-context
        // only. The tier-1 narrative/summary is deliberately not accepted at
        // all, so there is no admin_context-style argument that a caller
        // could even attempt to pass through and anchor the model on.
        $method = new ReflectionMethod(DeepReviewPromptComposer::class, 'compose');

        $this->assertSame(
            ['metrics', 'groups', 'selection'],
            array_map(fn (ReflectionParameter $p): string => $p->getName(), $method->getParameters()),
        );
    }
}
