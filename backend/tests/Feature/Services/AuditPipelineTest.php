<?php

namespace Tests\Feature\Services;

use App\Constants\AuditRequestStatus;
use App\Exceptions\AiAnalysisException;
use App\Jobs\GenerateAuditReport;
use App\Mail\Audit\AuditRepoAccessNeeded;
use App\Mail\Audit\AuditReportReady;
use App\Mail\Audit\AuditRequestFailed;
use App\Models\AuditRequest;
use App\Services\AuditReport\AiAnalyzer;
use App\Services\AuditReport\AuditPipeline;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTest;
use Tests\Support\FakeAiAnalyzer;

class AuditPipelineTest extends FeatureTest
{
    private string $fixtureRepo;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->fixtureRepo = storage_path('framework/testing/fixture-repo');

        if (! File::isDirectory($this->fixtureRepo.'/.git')) {
            File::ensureDirectoryExists($this->fixtureRepo);
            File::put($this->fixtureRepo.'/README.md', "# Fixture\n");
            File::put($this->fixtureRepo.'/index.php', "<?php\necho 'hi';\n");
            Process::path($this->fixtureRepo)->run('git init -q -b main')->throw();
            Process::path($this->fixtureRepo)->run('git -c user.email=t@t -c user.name=t add -A')->throw();
            Process::path($this->fixtureRepo)->run('git -c user.email=t@t -c user.name=t commit -qm fixture')->throw();
        }
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
        Storage::disk('local')->assertExists($request->report->pdf_path);
        Mail::assertQueued(AuditReportReady::class, fn ($mail) => $mail->hasTo($request->email));
        $this->assertDirectoryDoesNotExist(config('audit.workdir').'/'.$request->uuid); // workdir cleaned
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
}
