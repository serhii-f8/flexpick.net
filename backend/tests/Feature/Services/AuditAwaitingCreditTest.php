<?php

namespace Tests\Feature\Services;

use App\Constants\AuditFunding;
use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Constants\AwaitingCreditReason;
use App\Exceptions\AuditNotAnalyzableException;
use App\Filament\Admin\Resources\AuditRequests\Pages\ListAuditRequests;
use App\Filament\Dashboard\Resources\AuditRequests\AuditRequestResource;
use App\Mail\Audit\AuditCreditNeeded;
use App\Mail\Audit\AuditRepoAccessNeeded;
use App\Mapper\AuditRequestStatusMapper;
use App\Models\AuditRequest;
use App\Services\AuditMail\AuditMailer;
use App\Services\AuditReport\AuditEntitlementService;
use App\Services\AuditReport\AuditPipeline;
use App\Services\AuditReport\RepositoryCloner;
use App\Services\AuditRequestService;
use App\Services\GitProviders\GitRepoAccessResolver;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Mockery;
use Tests\Feature\FeatureTest;

class AuditAwaitingCreditTest extends FeatureTest
{
    public function test_awaiting_credit_is_a_terminal_status(): void
    {
        $this->assertSame(AuditRequest::TRIAGE_TERMINAL, AuditRequest::statusTriage()[AuditRequestStatus::AWAITING_CREDIT->value]);
    }

    public function test_awaiting_credit_has_a_label_and_the_closed_color(): void
    {
        $mapper = app(AuditRequestStatusMapper::class);

        $this->assertSame('Needs more credit', $mapper->mapForDisplay('awaiting_credit'));
        $this->assertSame('danger', $mapper->mapColor('awaiting_credit'));
    }

    public function test_the_dashboard_tells_the_customer_to_buy_credit_and_run_again(): void
    {
        $request = AuditRequest::factory()->create(['status' => AuditRequestStatus::AWAITING_CREDIT->value]);

        $hint = AuditRequestResource::statusDescription($request);

        $this->assertStringContainsString("weren't charged", $hint);
        $this->assertStringContainsString('run a new audit', $hint);
        $this->assertSame('failed', AuditRequestResource::timelineViewData($request)['steps'][2]['state']);
    }

    public function test_sizing_columns_default_to_unsized_with_no_extras(): void
    {
        $request = AuditRequest::factory()->create()->fresh();

        $this->assertNull($request->run_count);
        $this->assertSame(0, $request->extra_metered_runs);
        $this->assertSame(0, $request->extra_purchased_runs);
        $this->assertFalse($request->from_schedule);
    }

    public function test_closing_refunds_the_first_run_once_and_emails_the_customer(): void
    {
        Mail::fake();
        $tenant = $this->createTenant();
        $request = AuditRequest::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => AuditRequestStatus::ANALYZING->value,
            'tier' => AuditTier::DEEP_AI->value,
            'funding' => AuditFunding::PURCHASE->value,
        ]);
        $service = app(AuditRequestService::class);

        $service->closeAwaitingCredit($request, 'Needs 2 runs; 1 available.', tooLarge: false);
        $service->closeAwaitingCredit($request->refresh(), 'Needs 2 runs; 1 available.', tooLarge: false);

        $request->refresh();
        $this->assertSame(AuditRequestStatus::AWAITING_CREDIT->value, $request->status);
        $this->assertSame('Needs 2 runs; 1 available.', $request->failure_reason);
        $this->assertNotNull($request->credit_refunded_at);
        $this->assertSame(1, app(AuditEntitlementService::class)->purchasedCreditBalance($tenant, AuditTier::DEEP_AI));
        Mail::assertQueued(AuditCreditNeeded::class, fn (AuditCreditNeeded $mail) => $mail->hasTo($request->email) && ! $mail->tooLarge);
        $this->assertCount(1, Mail::queued(AuditCreditNeeded::class, fn (AuditCreditNeeded $mail) => $mail->hasTo($request->email)));
    }

    public function test_a_mail_transport_failure_does_not_undo_the_close_or_reach_the_caller(): void
    {
        $tenant = $this->createTenant();
        $request = AuditRequest::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => AuditRequestStatus::ANALYZING->value,
            'tier' => AuditTier::DEEP_AI->value,
            'funding' => AuditFunding::PURCHASE->value,
        ]);

        $mailer = Mockery::mock(AuditMailer::class);
        $mailer->shouldReceive('send')
            ->andThrow(new \RuntimeException('smtp down'));
        $this->instance(AuditMailer::class, $mailer);

        app(AuditRequestService::class)->closeAwaitingCredit($request, 'Needs 2 runs; 1 available.', tooLarge: false);

        $request->refresh();
        $this->assertSame(AuditRequestStatus::AWAITING_CREDIT->value, $request->status);
        $this->assertNotNull($request->credit_refunded_at);
        $this->assertSame(1, app(AuditEntitlementService::class)->purchasedCreditBalance($tenant, AuditTier::DEEP_AI));
        $this->assertContains('mail_failed', array_column($request->pipeline_log ?? [], 'step'));
    }

    public function test_a_mail_transport_failure_in_close_not_analyzable_does_not_undo_the_close_or_reach_the_caller(): void
    {
        $tenant = $this->createTenant();
        $request = AuditRequest::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => AuditRequestStatus::ANALYZING->value,
            'tier' => AuditTier::DEEP_AI->value,
            'funding' => AuditFunding::PURCHASE->value,
        ]);

        $mailer = Mockery::mock(AuditMailer::class);
        $mailer->shouldReceive('send')
            ->andThrow(new \RuntimeException('smtp down'));
        $this->instance(AuditMailer::class, $mailer);

        app(AuditRequestService::class)->closeNotAnalyzable($request, 'Private repository without deploy key.');

        $request->refresh();
        $this->assertSame(AuditRequestStatus::NOT_ANALYZABLE->value, $request->status);
        $this->assertNotNull($request->credit_refunded_at);
        $this->assertSame(1, app(AuditEntitlementService::class)->purchasedCreditBalance($tenant, AuditTier::DEEP_AI));
        $this->assertContains('mail_failed', array_column($request->pipeline_log ?? [], 'step'));
    }

    /**
     * The dashboard hint is read long after the email: why the request is
     * awaiting credit has to be on the row.
     */
    public function test_closing_records_why_the_request_is_awaiting_credit(): void
    {
        Mail::fake();
        $service = app(AuditRequestService::class);
        $insufficient = AuditRequest::factory()->create(['status' => AuditRequestStatus::ANALYZING->value]);
        $tooLarge = AuditRequest::factory()->create(['status' => AuditRequestStatus::ANALYZING->value]);

        $service->closeAwaitingCredit($insufficient, 'Needs 2 runs; 1 available.', tooLarge: false);
        $service->closeAwaitingCredit($tooLarge, 'Above the largest size band.', tooLarge: true);

        $this->assertSame(AwaitingCreditReason::INSUFFICIENT, $insufficient->fresh()->awaiting_credit_reason);
        $this->assertSame(AwaitingCreditReason::TOO_LARGE, $tooLarge->fresh()->awaiting_credit_reason);
    }

    /**
     * The reconnect flag travels from the exception to the row (for the
     * dashboard hint) and to the email.
     */
    public function test_close_not_analyzable_records_and_mails_the_reconnect_variant(): void
    {
        Mail::fake();
        $service = app(AuditRequestService::class);
        $reconnect = AuditRequest::factory()->create(['status' => AuditRequestStatus::ANALYZING->value]);
        $connect = AuditRequest::factory()->create(['status' => AuditRequestStatus::ANALYZING->value]);

        $service->closeNotAnalyzable($reconnect, 'Repository could not be reached with the connected account.', accessDenied: true, reconnect: true);
        $service->closeNotAnalyzable($connect, 'Repository is not publicly accessible.', accessDenied: true);

        $this->assertTrue($reconnect->fresh()->git_reconnect_required);
        $this->assertFalse($connect->fresh()->git_reconnect_required);
        Mail::assertQueued(AuditRepoAccessNeeded::class, fn (AuditRepoAccessNeeded $mail) => $mail->hasTo($reconnect->email) && $mail->reconnect);
        Mail::assertQueued(AuditRepoAccessNeeded::class, fn (AuditRepoAccessNeeded $mail) => $mail->hasTo($connect->email) && ! $mail->reconnect);
    }

    /**
     * End to end through the pipeline: a clone refused after preflight, with
     * a connection on file, closes with the reconnect variant.
     */
    public function test_the_pipeline_carries_the_reconnect_flag_from_the_cloner(): void
    {
        Mail::fake();
        $request = AuditRequest::factory()->create([
            'repo_url' => 'https://github.com/acme/private',
            'status' => AuditRequestStatus::QUEUED->value,
            'tenant_id' => $this->createTenant()->id,
        ]);
        $mock = Mockery::mock(RepositoryCloner::class, [app(GitRepoAccessResolver::class)])->makePartial();
        $mock->shouldReceive('preflight')->andReturnNull();
        $mock->shouldReceive('clone')->andThrow(AuditNotAnalyzableException::reconnectNeeded('Repository could not be cloned with the connected account: https://github.com/acme/private'));
        $this->instance(RepositoryCloner::class, $mock);

        app(AuditPipeline::class)->run($request);

        $request->refresh();
        $this->assertSame(AuditRequestStatus::NOT_ANALYZABLE->value, $request->status);
        $this->assertTrue($request->git_reconnect_required);
        Mail::assertQueued(AuditRepoAccessNeeded::class, fn (AuditRepoAccessNeeded $mail) => $mail->hasTo($request->email) && $mail->reconnect && $mail->accessProblem);
    }

    public function test_the_insufficient_credit_email_sends_them_to_buy_and_run_again(): void
    {
        $tenant = $this->createTenant();
        $request = AuditRequest::factory()->create([
            'tenant_id' => $tenant->id,
            'repo_url' => 'https://github.com/acme/big-monolith',
            'status' => AuditRequestStatus::AWAITING_CREDIT->value,
            'failure_reason' => 'This repository has about 150,000 lines of code, so a Deep AI Code Review audit takes 2 runs.',
        ]);

        $mail = new AuditCreditNeeded($request, tooLarge: false);

        $mail->assertSeeInHtml('about 150,000 lines of code');
        $mail->assertSeeInHtml("You haven't been charged");
        $mail->assertSeeInHtml('Buy credit and run again');
        $mail->assertSeeInHtml('repo=https%3A%2F%2Fgithub.com%2Facme%2Fbig-monolith');
        $mail->assertDontSeeInHtml('contact us');
    }

    public function test_the_too_large_email_asks_them_to_contact_us_instead_of_buying(): void
    {
        $request = AuditRequest::factory()->create([
            'status' => AuditRequestStatus::AWAITING_CREDIT->value,
            'failure_reason' => 'This repository has about 412,000 lines of code, above the 300,000-line limit for self-serve audits.',
        ]);

        $mail = new AuditCreditNeeded($request, tooLarge: true);

        $mail->assertSeeInHtml('300,000-line limit');
        $mail->assertSeeInHtml('reply to this email');
        $mail->assertDontSeeInHtml('Buy credit and run again');
    }

    public function test_admin_retry_and_launch_are_hidden_for_awaiting_credit(): void
    {
        $record = AuditRequest::factory()->create(['status' => AuditRequestStatus::AWAITING_CREDIT->value]);

        Livewire::actingAs($this->createAdminUser())
            ->test(ListAuditRequests::class)
            ->assertTableActionHidden('retry', $record)
            ->assertTableActionHidden('launch', $record);
    }
}
