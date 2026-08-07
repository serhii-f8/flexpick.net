<?php

namespace Tests\Feature\Services\AuditReport;

use App\Services\AuditReport\ReportPresenter;
use Tests\Feature\FeatureTest;

class ReportPresenterTest extends FeatureTest
{
    private ReportPresenter $presenter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->presenter = app(ReportPresenter::class);
    }

    public function test_files_are_ordered_by_their_worst_finding(): void
    {
        $payload = ['file_findings' => [
            ['path' => 'a.php', 'severity' => 'low', 'line' => 1, 'title' => 'A'],
            ['path' => 'b.php', 'severity' => 'critical', 'line' => 2, 'title' => 'B'],
            ['path' => 'c.php', 'severity' => 'medium', 'line' => 3, 'title' => 'C'],
        ]];

        $this->assertSame(
            ['b.php', 'c.php', 'a.php'],
            $this->presenter->findingsByFile($payload)->keys()->all()
        );
    }

    public function test_findings_within_a_file_sort_by_severity_then_line(): void
    {
        $payload = ['file_findings' => [
            ['path' => 'a.php', 'severity' => 'low', 'line' => 5, 'title' => 'low-5'],
            ['path' => 'a.php', 'severity' => 'high', 'line' => 90, 'title' => 'high-90'],
            ['path' => 'a.php', 'severity' => 'high', 'line' => 10, 'title' => 'high-10'],
        ]];

        $titles = $this->presenter->findingsByFile($payload)
            ->get('a.php')
            ->pluck('title')
            ->all();

        $this->assertSame(['high-10', 'high-90', 'low-5'], $titles);
    }

    public function test_unknown_severity_ranks_lowest_and_missing_line_is_tolerated(): void
    {
        $payload = ['file_findings' => [
            ['path' => 'a.php', 'severity' => 'not-a-severity', 'title' => 'unknown'],
            ['path' => 'a.php', 'severity' => 'info', 'line' => 3, 'title' => 'info-3'],
        ]];

        $titles = $this->presenter->findingsByFile($payload)
            ->get('a.php')
            ->pluck('title')
            ->all();

        $this->assertSame(['info-3', 'unknown'], $titles);
    }

    public function test_absent_file_findings_yields_an_empty_collection(): void
    {
        $this->assertTrue($this->presenter->findingsByFile([])->isEmpty());
    }

    public function test_deep_review_meta_is_returned_or_null(): void
    {
        $this->assertNull($this->presenter->deepReviewMeta([]));
        $this->assertSame(
            ['files_reviewed' => 3],
            $this->presenter->deepReviewMeta(['deep_review' => ['files_reviewed' => 3]])
        );
    }
}
