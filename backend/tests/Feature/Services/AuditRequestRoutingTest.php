<?php

namespace Tests\Feature\Services;

use App\Constants\AuditRequestStatus;
use App\Jobs\GenerateAuditReport;
use App\Mail\Audit\AuditQuotaExhausted;
use App\Mail\Audit\AuditRepoAccessNeeded;
use App\Mail\Audit\AuditRequestReceived;
use App\Models\AuditRequest;
use App\Services\AuditRequestService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
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

    public function test_unreachable_repo_awaits_access(): void
    {
        $request = AuditRequest::factory()->verified()->create([
            'repo_url' => 'file:///nonexistent/private-repo',
            'status' => AuditRequestStatus::PENDING_VERIFICATION->value,
        ]);

        $this->route($request);

        $request->refresh();
        $this->assertSame(AuditRequestStatus::AWAITING_ACCESS->value, $request->status);
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
}
