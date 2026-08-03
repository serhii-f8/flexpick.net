<?php

namespace Tests\Feature\Services;

use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Exceptions\AiAnalysisException;
use App\Jobs\GenerateAuditReport;
use App\Mail\Audit\AuditRepoAccessNeeded;
use App\Mail\Audit\AuditReportReady;
use App\Mail\Audit\AuditRequestFailed;
use App\Models\AuditFindingGroup;
use App\Models\AuditRequest;
use App\Services\AuditReport\AiAnalyzer;
use App\Services\AuditReport\AuditPipeline;
use App\Services\AuditReport\Findings\FindingGroup;
use App\Services\AuditReport\Findings\Severity;
use App\Services\AuditReport\ReportPayload;
use App\Services\AuditReport\Scanners\ScannerOutcome;
use App\Services\AuditReport\Scanners\ScannerRun;
use App\Services\AuditReport\Scanners\ScannerSuiteResult;
use App\Services\AuditReport\ScoreCalculator;
use App\Services\AuditRequestService;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\FeatureTest;
use Tests\Support\FakeAiAnalyzer;
use Tests\Support\RunsAuditPipelineWithFakes;

class AuditPipelineTest extends FeatureTest
{
    use RunsAuditPipelineWithFakes;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setUpAuditPipelineFixture();
    }

    public function test_happy_path_produces_and_sends_report(): void
    {
        $this->app->instance(AiAnalyzer::class, new FakeAiAnalyzer);
        $request = AuditRequest::factory()->create([
            'repo_url' => 'file://'.$this->fixtureRepo,
            'status' => AuditRequestStatus::QUEUED->value,
        ]);

        (new GenerateAuditReport($request))->handle(app(AuditPipeline::class));

        $request->refresh();
        $this->assertSame(AuditRequestStatus::SENT->value, $request->status);
        $this->assertNotNull($request->report);
        $this->assertNotNull($request->metrics);
        $this->assertNull($request->report->pdf_path);
        $this->assertNull($request->report->unlocked_at);
        Mail::assertQueued(AuditReportReady::class, fn ($mail) => $mail->hasTo($request->email));
        $this->assertDirectoryDoesNotExist(config('audit.workdir').'/'.$request->uuid); // workdir cleaned

        // Recompute from what the run actually persisted (scanner provenance
        // and finding groups) so this stays a faithful end-to-end check
        // rather than duplicating ScoreCalculatorTest's formula coverage.
        $runs = array_map(
            fn (array $r): ScannerRun => new ScannerRun(
                $r['name'], $r['version'], $r['wall_ms'], $r['finding_count'],
                ScannerOutcome::from($r['outcome']), $r['reason'] ?? null,
            ),
            $request->scanner_runs,
        );
        $groups = $request->findingGroups->map(fn (AuditFindingGroup $g): FindingGroup => new FindingGroup(
            $g->rule_family, $g->directory, Severity::from($g->severity), $g->count, $g->score,
            $g->examples, $g->tools, $g->dimension,
        ))->all();

        $expected = app(ScoreCalculator::class)->calculate($request->metrics, $groups, new ScannerSuiteResult([], $runs));
        // assertEquals, not assertSame: MySQL's JSON column type reorders object
        // keys (by length, then lexicographically) on round-trip, so key order
        // is not preserved through storage even though values are unchanged.
        $this->assertEquals($expected->toPayloadScores(), $request->report->payload['scores']);
    }

    public function test_inaccessible_repo_goes_to_followup(): void
    {
        $this->app->instance(AiAnalyzer::class, new FakeAiAnalyzer);
        $request = AuditRequest::factory()->create([
            'repo_url' => 'file:///nonexistent/nope',
            'status' => AuditRequestStatus::QUEUED->value,
        ]);

        (new GenerateAuditReport($request))->handle(app(AuditPipeline::class));

        $this->assertSame(AuditRequestStatus::NEEDS_FOLLOWUP->value, $request->fresh()->status);
        Mail::assertQueued(AuditRepoAccessNeeded::class);
    }

    public function test_ai_failure_marks_failed_and_notifies(): void
    {
        $this->app->instance(AiAnalyzer::class, new FakeAiAnalyzer(throws: new AiAnalysisException('boom')));
        $request = AuditRequest::factory()->create([
            'repo_url' => 'file://'.$this->fixtureRepo,
            'status' => AuditRequestStatus::QUEUED->value,
        ]);

        $job = new GenerateAuditReport($request);
        try {
            $job->handle(app(AuditPipeline::class));
            $this->fail('Expected AiAnalysisException');
        } catch (AiAnalysisException) {
            $job->failed(new AiAnalysisException('boom')); // what the queue worker would do
        }

        $this->assertSame(AuditRequestStatus::FAILED->value, $request->fresh()->status);
        $this->assertSame('boom', $request->fresh()->failure_reason);
        Mail::assertQueued(AuditRequestFailed::class);
        $this->assertDirectoryDoesNotExist(config('audit.workdir').'/'.$request->uuid); // cleanup ran in finally
    }

    public function test_report_job_retries_transient_failures_before_giving_up(): void
    {
        $job = new GenerateAuditReport(AuditRequest::factory()->create());

        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300], $job->backoff);
    }

    public function test_pipeline_records_log_and_timing(): void
    {
        $this->app->instance(AiAnalyzer::class, new FakeAiAnalyzer);
        $request = AuditRequest::factory()->create([
            'repo_url' => 'file://'.$this->fixtureRepo,
            'status' => AuditRequestStatus::QUEUED->value,
        ]);

        (new GenerateAuditReport($request))->handle(app(AuditPipeline::class));

        $request->refresh();
        $this->assertNotNull($request->analysis_started_at);
        $this->assertNotNull($request->analysis_completed_at);

        $steps = array_column($request->pipeline_log, 'step');
        $this->assertSame(['started', 'cloned', 'metrics', 'analyzed', 'report'], $steps);
    }

    public function test_failed_run_appends_failure_log_entry(): void
    {
        $this->app->instance(AiAnalyzer::class, new FakeAiAnalyzer(throws: new AiAnalysisException('boom')));
        $request = AuditRequest::factory()->create([
            'repo_url' => 'file://'.$this->fixtureRepo,
            'status' => AuditRequestStatus::QUEUED->value,
        ]);

        try {
            (new GenerateAuditReport($request))->handle(app(AuditPipeline::class));
        } catch (AiAnalysisException) {
            // the job's failed() handler runs markFailed in production; simulate it
            app(AuditRequestService::class)->markFailed($request, 'boom');
        }

        $request->refresh();
        $log = $request->pipeline_log;
        $last = end($log);
        $this->assertSame('failed', $last['step']);
        $this->assertStringContainsString('boom', $last['message']);
        $this->assertNull($request->analysis_completed_at);
    }

    public function test_persists_finding_groups_for_the_run(): void
    {
        $request = $this->runPipelineWithFakes(groups: [
            new FindingGroup('php.injection', 'app/Http', Severity::HIGH, 37, 1480,
                [['path' => 'app/Http/A.php', 'line' => 42]], ['semgrep'], 'security_hygiene'),
        ]);

        $this->assertDatabaseHas('audit_finding_groups', [
            'audit_request_id' => $request->id,
            'rule_family' => 'php.injection',
            'count' => 37,
        ]);
    }

    public function test_records_scanner_provenance_on_the_request(): void
    {
        $request = $this->runPipelineWithFakes();

        $runs = $request->fresh()->scanner_runs;

        $this->assertIsArray($runs);
        $this->assertSame('scc', $runs[0]['name']);
        $this->assertArrayHasKey('outcome', $runs[0]);
        $this->assertArrayHasKey('wall_ms', $runs[0]);
    }

    public function test_a_failed_scanner_does_not_fail_the_run(): void
    {
        // F5.12.2 end to end: the report is still produced and sent.
        $request = $this->runPipelineWithFakes(failingScanners: ['semgrep']);

        $this->assertNotNull($request->fresh()->report);
        $this->assertNotNull($request->fresh()->analysis_completed_at);
    }

    public function test_records_the_scoring_and_payload_versions_on_the_report(): void
    {
        $report = $this->runPipelineWithFakes()->fresh()->report;

        $this->assertSame(ScoreCalculator::VERSION, $report->scoring_version);
        $this->assertSame(ReportPayload::VERSION, $report->payload_schema_version);
    }

    public function test_narration_is_capped_to_the_tier_budget(): void
    {
        config()->set('audit.tiers.diagnostic.narrated_groups', 2);

        $groups = [];
        for ($i = 0; $i < 10; $i++) {
            $groups[] = new FindingGroup("family.{$i}", 'app', Severity::HIGH, 1, 40, [], ['semgrep'], 'security_hygiene');
        }

        // The analyzer receives at most the tier's narrated_groups; the full set
        // is still persisted. Grouping is the prompt-size cost control (F5.12.2).
        $request = $this->runPipelineWithFakes(tier: AuditTier::DIAGNOSTIC, groups: $groups);

        $this->assertSame(10, $request->findingGroups()->count());
        $this->assertCount(2, $this->lastAnalyzerGroups);
    }

    public function test_scc_failure_falls_back_to_a_walked_inventory(): void
    {
        // Spec §10: later stages must retain a basis when scc is unavailable.
        $request = $this->runPipelineWithFakes(failingScanners: ['scc']);

        $this->assertNotNull($request->fresh()->report);
        $this->assertGreaterThan(0, $request->fresh()->metrics['files_total']);
    }
}
