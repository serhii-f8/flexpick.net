<?php

namespace Tests\Feature\Services\DeepReview;

use App\Constants\AuditTier;
use App\Services\AuditReport\DeepReview\DeepReviewProfile;
use App\Services\AuditReport\DeepReview\RiskFileSelector;
use App\Services\AuditReport\Scanners\RepoContext;
use App\Services\AuditReport\Scanners\SccInventory;
use App\Services\AuditReport\Tiers\TierProfileResolver;
use Illuminate\Support\Facades\File;
use Tests\Feature\FeatureTest;

class RiskFileBudgetTest extends FeatureTest
{
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('audit.deep_review.overhead_tokens', 0);
        config()->set('audit.deep_review.chars_per_token', 1.0);
        config()->set('audit.deep_review.safety_margin', 1.0);

        $this->repo = storage_path('framework/testing/budget-repo');
        File::deleteDirectory($this->repo);
        File::ensureDirectoryExists($this->repo.'/app');

        // 30 files of 1000 bytes each. With the config above, 1 byte = 1 token.
        for ($i = 0; $i < 30; $i++) {
            File::put($this->repo.sprintf('/app/File%02d.php', $i), str_repeat('x', 1000));
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->repo);

        parent::tearDown();
    }

    private function context(): RepoContext
    {
        $files = [];

        for ($i = 0; $i < 30; $i++) {
            $files[] = ['path' => sprintf('app/File%02d.php', $i), 'loc' => 30 - $i, 'complexity' => 1];
        }

        $context = new RepoContext(
            $this->repo,
            app(TierProfileResolver::class)->for(AuditTier::DEEP_AI),
            new SccInventory(files: $files, languages: [], totalLoc: 465, totalComplexity: 30),
        );

        // Descending churn so ranking follows the file numbering.
        $context->withChurn(array_combine(
            array_column($files, 'path'),
            range(30, 1),
        ));

        return $context;
    }

    private function select(DeepReviewProfile $profile)
    {
        return app(RiskFileSelector::class)->select($this->context(), [], $profile);
    }

    public function test_the_budget_truncates_from_the_bottom_of_the_ranking(): void
    {
        // Budget fits 10 files of 1000 tokens; 25 would otherwise be selected.
        $selection = $this->select(new DeepReviewProfile(
            minFiles: 5, maxFiles: 25, fileBytes: 1000,
            minFileBytes: 500, inputTokenBudget: 10000, maxTokens: 16000,
        ));

        $this->assertCount(10, $selection->files);
        $this->assertTrue($selection->truncated);
        $this->assertSame(25, $selection->selectedBeforeBudget);
        $this->assertSame('app/File00.php', $selection->files[0]->path);
        $this->assertSame('app/File09.php', $selection->files[9]->path);
        $this->assertLessThanOrEqual(10000, $selection->estimatedInputTokens);
    }

    public function test_no_truncation_when_everything_fits(): void
    {
        $selection = $this->select(new DeepReviewProfile(
            minFiles: 5, maxFiles: 20, fileBytes: 1000,
            minFileBytes: 500, inputTokenBudget: 100000, maxTokens: 16000,
        ));

        $this->assertCount(20, $selection->files);
        $this->assertFalse($selection->truncated);
        $this->assertFalse($selection->belowFloor);
    }

    public function test_per_file_bytes_shrink_rather_than_dropping_below_the_floor(): void
    {
        // 20 files must fit in 10000 tokens: impossible at 1000 bytes each,
        // achievable at 500.
        $selection = $this->select(new DeepReviewProfile(
            minFiles: 20, maxFiles: 25, fileBytes: 1000,
            minFileBytes: 500, inputTokenBudget: 10000, maxTokens: 16000,
        ));

        $this->assertCount(20, $selection->files);
        $this->assertFalse($selection->belowFloor);
        $this->assertSame(500, $selection->fileBytesUsed);
        $this->assertSame(500, strlen($selection->files[0]->content));
    }

    public function test_below_floor_is_recorded_when_even_the_minimum_cannot_fit(): void
    {
        // 20 files at 500 bytes needs 10000 tokens; the budget allows 3000.
        $selection = $this->select(new DeepReviewProfile(
            minFiles: 20, maxFiles: 25, fileBytes: 1000,
            minFileBytes: 500, inputTokenBudget: 3000, maxTokens: 16000,
        ));

        $this->assertTrue($selection->belowFloor);
        $this->assertLessThan(20, count($selection->files));
        $this->assertGreaterThan(0, count($selection->files));
    }

    public function test_overhead_is_reserved_out_of_the_budget(): void
    {
        config()->set('audit.deep_review.overhead_tokens', 5000);

        $selection = $this->select(new DeepReviewProfile(
            minFiles: 1, maxFiles: 25, fileBytes: 1000,
            minFileBytes: 500, inputTokenBudget: 10000, maxTokens: 16000,
        ));

        // 10000 budget - 5000 overhead = 5000 for files = 5 files.
        $this->assertCount(5, $selection->files);
    }
}
