<?php

namespace Tests\Feature\Services;

use App\Constants\AuditRequestStatus;
use App\Exceptions\GitAccessTemporarilyUnavailableException;
use App\Jobs\GenerateAuditReport;
use App\Jobs\RouteVerifiedAuditRequest;
use App\Mail\Audit\AuditQuotaExhausted;
use App\Mail\Audit\AuditRepoAccessNeeded;
use App\Mail\Audit\AuditRequestFailed;
use App\Mail\Audit\AuditRequestReceived;
use App\Models\AuditRequest;
use App\Services\AuditReport\RepositoryCloner;
use App\Services\AuditRequestService;
use App\Services\GitProviders\GitRepoAccessResolver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Feature\FeatureTest;

class AuditRequestRoutingTest extends FeatureTest
{
    private string $fixtureRepo;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Queue::fake([GenerateAuditReport::class]);
        config(['audit.admin_email' => 'admin@flexpick.net']);

        $this->fixtureRepo = storage_path('framework/testing/fixture-repo');
        if (! File::isDirectory($this->fixtureRepo.'/.git')) {
            File::ensureDirectoryExists($this->fixtureRepo);
            File::put($this->fixtureRepo.'/README.md', "# Fixture\n");
            Process::path($this->fixtureRepo)->run('git init -q -b main')->throw();
            Process::path($this->fixtureRepo)->run('git -c user.email=t@t -c user.name=t add -A')->throw();
            Process::path($this->fixtureRepo)->run('git -c user.email=t@t -c user.name=t commit -qm fixture')->throw();
        }
    }

    private function route(AuditRequest $request): void
    {
        app(AuditRequestService::class)->routeVerified($request);
    }

    public function test_public_repo_with_free_quota_queues_and_consumes_run(): void
    {
        config(['audit.free_reports_limit' => 3]);
        $request = AuditRequest::factory()->verified()->create([
            'repo_url' => 'file://'.$this->fixtureRepo,
            'status' => AuditRequestStatus::PENDING_VERIFICATION->value,
        ]);

        $this->route($request);

        $request->refresh();
        $this->assertSame(AuditRequestStatus::QUEUED->value, $request->status);
        $this->assertTrue($request->free_run);
        Queue::assertPushed(GenerateAuditReport::class);
        Mail::assertQueued(AuditRequestReceived::class);
    }

    public function test_public_repo_awaits_payment_by_default_with_no_prior_free_runs(): void
    {
        $request = AuditRequest::factory()->verified()->create([
            'repo_url' => 'file://'.$this->fixtureRepo,
            'status' => AuditRequestStatus::PENDING_VERIFICATION->value,
        ]);

        $this->route($request);

        $request->refresh();
        $this->assertSame(AuditRequestStatus::AWAITING_PAYMENT->value, $request->status);
        $this->assertFalse($request->free_run);
        Queue::assertNotPushed(GenerateAuditReport::class);
        Mail::assertQueued(AuditQuotaExhausted::class);
    }

    public function test_public_repo_without_quota_awaits_payment(): void
    {
        AuditRequest::factory()->count(3)->freeRun()->create(['email' => 'maxed@example.com']);
        $request = AuditRequest::factory()->verified()->create([
            'email' => 'maxed@example.com',
            'repo_url' => 'file://'.$this->fixtureRepo,
            'status' => AuditRequestStatus::PENDING_VERIFICATION->value,
        ]);

        $this->route($request);

        $request->refresh();
        $this->assertSame(AuditRequestStatus::AWAITING_PAYMENT->value, $request->status);
        $this->assertFalse($request->free_run);
        Queue::assertNotPushed(GenerateAuditReport::class);
        Mail::assertQueued(AuditQuotaExhausted::class, fn ($mail) => $mail->hasTo('maxed@example.com'));
    }

    public function test_unreachable_repo_is_closed_rather_than_left_waiting(): void
    {
        $request = AuditRequest::factory()->verified()->create([
            'repo_url' => 'file:///nonexistent/private-repo',
            'status' => AuditRequestStatus::PENDING_VERIFICATION->value,
        ]);

        $this->route($request);

        $request->refresh();
        $this->assertSame(AuditRequestStatus::NOT_ANALYZABLE->value, $request->status);
        $this->assertFalse($request->free_run);
        Queue::assertNotPushed(GenerateAuditReport::class);
        Mail::assertQueued(AuditRepoAccessNeeded::class);
    }

    public function test_missing_repo_url_needs_followup(): void
    {
        $request = AuditRequest::factory()->verified()->create([
            'repo_url' => null,
            'status' => AuditRequestStatus::PENDING_VERIFICATION->value,
        ]);

        $this->route($request);

        $this->assertSame(AuditRequestStatus::NEEDS_FOLLOWUP->value, $request->refresh()->status);
        Mail::assertQueued(AuditRepoAccessNeeded::class);
    }

    private function cloneThatIsTransientlyUnavailable(): void
    {
        $mock = Mockery::mock(RepositoryCloner::class, [app(GitRepoAccessResolver::class)])->makePartial();
        $mock->shouldReceive('preflight')->andThrow(new GitAccessTemporarilyUnavailableException('refresh unavailable'));
        $this->instance(RepositoryCloner::class, $mock);
    }

    public function test_a_transient_git_failure_reaches_the_job_and_leaves_the_request_untouched(): void
    {
        $this->cloneThatIsTransientlyUnavailable();
        $request = AuditRequest::factory()->verified()->create([
            'repo_url' => 'https://gitlab.com/acme/private',
            'status' => AuditRequestStatus::PENDING_VERIFICATION->value,
        ]);

        try {
            (new RouteVerifiedAuditRequest($request))->handle(app(AuditRequestService::class));
            $this->fail('Expected GitAccessTemporarilyUnavailableException');
        } catch (GitAccessTemporarilyUnavailableException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(AuditRequestStatus::PENDING_VERIFICATION->value, $request->fresh()->status);
        $this->assertNull($request->fresh()->credit_refunded_at);
        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }

    public function test_the_routing_job_retries_and_outlasts_the_worst_case_git_wait(): void
    {
        $job = new RouteVerifiedAuditRequest(AuditRequest::factory()->verified()->create());

        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300], $job->backoff);
        // 25s refresh-lock wait + 15s refresh call + 30s ls-remote.
        $this->assertGreaterThan(70, $job->timeout);
    }

    public function test_exhausting_the_routing_job_marks_the_request_failed_without_refund(): void
    {
        $request = AuditRequest::factory()->verified()->create([
            'status' => AuditRequestStatus::PENDING_VERIFICATION->value,
        ]);

        (new RouteVerifiedAuditRequest($request))->failed(new GitAccessTemporarilyUnavailableException('Git token refresh is temporarily unavailable'));

        $request->refresh();
        $this->assertSame(AuditRequestStatus::FAILED->value, $request->status);
        $this->assertNull($request->credit_refunded_at);
        Mail::assertQueued(AuditRequestFailed::class);
    }
}
