<?php

namespace Tests\Feature\Services;

use App\Constants\AuditFunding;
use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Exceptions\AuditAwaitingCreditException;
use App\Exceptions\AuditNotAnalyzableException;
use App\Jobs\GenerateAuditReport;
use App\Mail\Audit\AuditCreditNeeded;
use App\Mail\Audit\AuditRepoAccessNeeded;
use App\Mail\Audit\AuditReportReady;
use App\Mail\Audit\AuditRequestFailed;
use App\Mail\Audit\NewAuditRequestAdminNotification;
use App\Models\AuditEmailLog;
use App\Models\AuditFunnelEvent;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Models\Tenant;
use App\Services\AuditReport\AuditEntitlementService;
use App\Services\AuditReport\AuditPipeline;
use App\Services\AuditReport\AuditReportService;
use App\Services\AuditReport\RepositoryCloner;
use App\Services\AuditRequestService;
use App\Services\GitProviders\GitRepoAccessResolver;
use Illuminate\Support\Facades\Mail;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\Severity;
use Sentry\State\Hub;
use Tests\Feature\FeatureTest;
use Tests\Support\RunsAuditPipelineWithFakes;

class AuditDeliveredReportCloseTest extends FeatureTest
{
    use RunsAuditPipelineWithFakes;

    private const ADMIN = 'admin@flexpick.net';

    /**
     * Routes Sentry to an in-memory client for this test, so the events the
     * code under test captures can be asserted on.
     *
     * @return \ArrayObject<int, Event>
     */
    private function captureSentryEvents(): \ArrayObject
    {
        $events = new \ArrayObject;
        $previous = SentrySdk::getCurrentHub();
        SentrySdk::setCurrentHub(new Hub(ClientBuilder::create([
            'dsn' => 'https://public@sentry.invalid/1',
            'before_send' => function (Event $event) use ($events): ?Event {
                $events[] = $event;

                return null;
            },
        ])->getClient()));
        $this->beforeApplicationDestroyed(fn () => SentrySdk::setCurrentHub($previous));

        return $events;
    }

    /**
     * @param  \ArrayObject<int, Event>  $events
     */
    private function assertUndeliveredAlert(\ArrayObject $events, AuditRequest $request): void
    {
        Mail::assertQueued(NewAuditRequestAdminNotification::class, fn ($mail) => $mail->hasTo(self::ADMIN) && $mail->auditRequest->is($request));
        $alerts = collect($events)
            ->filter(fn (Event $event) => str_contains((string) $event->getMessage(), 'never delivered'))
            ->values();
        $this->assertCount(1, $alerts);
        $this->assertSame(Severity::ERROR, (string) $alerts[0]->getLevel());
        $this->assertSame((string) $request->uuid, $alerts[0]->getTags()['audit_request']);
    }

    /**
     * @return array{0: AuditRequest, 1: Tenant}
     */
    private function deliveredRequest(AuditRequestStatus $status): array
    {
        $expert = $status === AuditRequestStatus::EXPERT_REVIEW;
        $tenant = $this->createTenant();
        $request = AuditRequest::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => $status->value,
            'tier' => ($expert ? AuditTier::EXPERT : AuditTier::DEEP_AI)->value,
            'funding' => AuditFunding::PURCHASE->value,
        ]);
        AuditReport::factory()->create(['audit_request_id' => $request->id]);

        if ($status === AuditRequestStatus::SENT) {
            AuditEmailLog::create([
                'audit_request_id' => $request->id, 'mailable' => 'AuditReportReady', 'recipient' => $request->email,
                'subject' => 's', 'body' => 'b', 'status' => AuditEmailLog::STATUS_SENT, 'sent_at' => now(),
            ]);
        }

        return [$request->refresh(), $tenant];
    }

    /**
     * @return array<string, array{0: AuditRequestStatus}>
     */
    public static function deliveredStatuses(): array
    {
        return [
            'report_ready' => [AuditRequestStatus::REPORT_READY],
            'expert_review' => [AuditRequestStatus::EXPERT_REVIEW],
        ];
    }

    #[DataProvider('deliveredStatuses')]
    public function test_a_failed_retry_never_demotes_or_refunds_on_not_analyzable(AuditRequestStatus $status): void
    {
        Mail::fake();
        [$request, $tenant] = $this->deliveredRequest($status);

        app(AuditRequestService::class)->closeNotAnalyzable($request, 'Repository is no longer reachable.');

        $request->refresh();
        $this->assertSame($status->value, $request->status);
        $this->assertNull($request->credit_refunded_at);
        $this->assertNotSame('Repository is no longer reachable.', $request->failure_reason);
        $this->assertSame(0, app(AuditEntitlementService::class)->purchasedCreditBalance($tenant, AuditTier::DEEP_AI));
        Mail::assertNothingQueued();
        Mail::assertNothingSent();
        $entry = collect($request->pipeline_log)->firstWhere('step', 'retry_failed');
        $this->assertNotNull($entry);
        $this->assertStringContainsString('Repository is no longer reachable.', $entry['message']);
        $this->assertNull(collect($request->pipeline_log)->firstWhere('step', 'refunded'));
    }

    #[DataProvider('deliveredStatuses')]
    public function test_a_failed_retry_never_demotes_or_refunds_on_awaiting_credit(AuditRequestStatus $status): void
    {
        Mail::fake();
        [$request, $tenant] = $this->deliveredRequest($status);

        app(AuditRequestService::class)->closeAwaitingCredit($request, 'Needs 2 runs; 1 available.', tooLarge: true);

        $request->refresh();
        $this->assertSame($status->value, $request->status);
        $this->assertNull($request->credit_refunded_at);
        $this->assertSame(0, app(AuditEntitlementService::class)->purchasedCreditBalance($tenant, AuditTier::DEEP_AI));
        Mail::assertNothingQueued();
        Mail::assertNothingSent();
        $entry = collect($request->pipeline_log)->firstWhere('step', 'retry_failed');
        $this->assertNotNull($entry);
        $this->assertStringContainsString('Needs 2 runs; 1 available.', $entry['message']);
    }

    public function test_refund_refuses_a_request_that_has_a_report(): void
    {
        [$request, $tenant] = $this->deliveredRequest(AuditRequestStatus::REPORT_READY);

        $this->assertFalse(app(AuditEntitlementService::class)->refund($request));

        $this->assertNull($request->refresh()->credit_refunded_at);
        $this->assertSame(0, app(AuditEntitlementService::class)->purchasedCreditBalance($tenant, AuditTier::DEEP_AI));
    }

    public function test_a_request_without_a_report_still_closes_and_refunds(): void
    {
        Mail::fake();
        $tenant = $this->createTenant();
        $make = fn () => AuditRequest::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => AuditRequestStatus::ANALYZING->value,
            'tier' => AuditTier::DEEP_AI->value,
            'funding' => AuditFunding::PURCHASE->value,
        ]);
        $service = app(AuditRequestService::class);

        $notAnalyzable = $make();
        $service->closeNotAnalyzable($notAnalyzable, 'No access.');
        $awaiting = $make();
        $service->closeAwaitingCredit($awaiting, 'Needs more.', tooLarge: false);

        $this->assertSame(AuditRequestStatus::NOT_ANALYZABLE->value, $notAnalyzable->refresh()->status);
        $this->assertNotNull($notAnalyzable->credit_refunded_at);
        $this->assertSame(AuditRequestStatus::AWAITING_CREDIT->value, $awaiting->refresh()->status);
        $this->assertNotNull($awaiting->credit_refunded_at);
        $this->assertSame(2, app(AuditEntitlementService::class)->purchasedCreditBalance($tenant, AuditTier::DEEP_AI));
        Mail::assertQueued(AuditRepoAccessNeeded::class);
        Mail::assertQueued(AuditCreditNeeded::class);
    }

    /**
     * @return array<string, array{0: AuditRequestStatus, 1: \Throwable}>
     */
    public static function realRetryCases(): array
    {
        $cases = [];
        foreach ([AuditRequestStatus::REPORT_READY, AuditRequestStatus::SENT, AuditRequestStatus::EXPERT_REVIEW] as $status) {
            $cases["{$status->value} not analyzable"] = [$status, new AuditNotAnalyzableException('No access.', true)];
            $cases["{$status->value} awaiting credit"] = [$status, new AuditAwaitingCreditException('Needs more.', false)];
        }

        return $cases;
    }

    #[DataProvider('realRetryCases')]
    public function test_the_real_retry_sequence_ends_in_the_delivered_status(AuditRequestStatus $status, \Throwable $failure): void
    {
        Mail::fake();
        [$request] = $this->deliveredRequest($status);

        // The admin "Retry pipeline" action: queued, then the pipeline runs.
        $request->update(['status' => AuditRequestStatus::QUEUED->value, 'failure_reason' => null]);
        $mock = Mockery::mock(RepositoryCloner::class, [app(GitRepoAccessResolver::class)])->makePartial();
        $mock->shouldReceive('preflight')->once()->andThrow($failure);
        $this->instance(RepositoryCloner::class, $mock);

        app(AuditPipeline::class)->run($request);

        $request->refresh();
        $this->assertSame($status->value, $request->status);
        $this->assertNull($request->credit_refunded_at);
        $this->assertNotNull(collect($request->pipeline_log)->firstWhere('step', 'retry_failed'));
        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }

    #[DataProvider('deliveredStatuses')]
    public function test_mark_failed_keeps_a_delivered_report(AuditRequestStatus $status): void
    {
        Mail::fake();
        [$request] = $this->deliveredRequest($status);
        $request->update(['status' => AuditRequestStatus::ANALYZING->value]);

        app(AuditRequestService::class)->markFailed($request, 'boom');

        $request->refresh();
        $this->assertSame($status->value, $request->status);
        $this->assertNotNull(collect($request->pipeline_log)->firstWhere('step', 'retry_failed'));
        $this->assertNull(collect($request->pipeline_log)->firstWhere('step', 'failed'));
        $this->assertSame(0, AuditFunnelEvent::where('audit_request_id', $request->id)->where('stage', 'failed')->count());
        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }

    /**
     * A first run whose report is saved but whose delivery keeps failing:
     * the retries are exhausted and failed() runs. The report stays
     * report_ready (never demoted, customer not told it failed), but nobody
     * ever received it, so the operator is alerted.
     */
    public function test_a_first_run_whose_report_never_went_out_is_kept_ready_and_alerts_the_operator(): void
    {
        Mail::fake();
        config(['audit.admin_email' => self::ADMIN]);
        $events = $this->captureSentryEvents();
        $this->setUpAuditPipelineFixture();
        $this->partialMock(AuditReportService::class, function ($mock): void {
            $mock->shouldReceive('send')->andThrow(new \RuntimeException('Mail transport down'));
        });
        $email = 'undelivered-'.uniqid().'@example.com';

        try {
            $this->runPipelineWithFakes(requestAttributes: ['email' => $email]);
            $this->fail('Expected the delivery failure to escape the pipeline');
        } catch (\RuntimeException $e) {
            $this->assertSame('Mail transport down', $e->getMessage());
        }

        $request = AuditRequest::where('email', $email)->sole();
        $this->assertNotNull($request->report);
        (new GenerateAuditReport($request))->failed($e);

        $request->refresh();
        $this->assertSame(AuditRequestStatus::REPORT_READY->value, $request->status);
        Mail::assertNotQueued(AuditRequestFailed::class);
        Mail::assertNotQueued(AuditReportReady::class);
        $this->assertUndeliveredAlert($events, $request);
    }

    /**
     * The same undelivered report reaching the close guards: a queue retry of
     * that first run fails preflight or sizing instead.
     */
    public function test_an_undelivered_report_reaching_a_close_guard_alerts_the_operator(): void
    {
        Mail::fake();
        config(['audit.admin_email' => self::ADMIN]);
        $events = $this->captureSentryEvents();
        [$notAnalyzable] = $this->deliveredRequest(AuditRequestStatus::REPORT_READY);
        $notAnalyzable->update(['status' => AuditRequestStatus::ANALYZING->value]);
        [$awaiting] = $this->deliveredRequest(AuditRequestStatus::REPORT_READY);
        $awaiting->update(['status' => AuditRequestStatus::ANALYZING->value]);

        app(AuditRequestService::class)->closeNotAnalyzable($notAnalyzable, 'No access.');
        app(AuditRequestService::class)->closeAwaitingCredit($awaiting, 'Needs more.', tooLarge: false);

        $this->assertSame(AuditRequestStatus::REPORT_READY->value, $notAnalyzable->refresh()->status);
        $this->assertSame(AuditRequestStatus::REPORT_READY->value, $awaiting->refresh()->status);
        Mail::assertNotQueued(AuditRepoAccessNeeded::class);
        Mail::assertNotQueued(AuditCreditNeeded::class);
        Mail::assertQueued(NewAuditRequestAdminNotification::class, 2);
        $this->assertCount(2, $events);
        $this->assertUndeliveredAlert(new \ArrayObject([$events[0]]), $notAnalyzable);
        $this->assertUndeliveredAlert(new \ArrayObject([$events[1]]), $awaiting);
    }

    /**
     * @return array<string, array{0: AuditRequestStatus}>
     */
    public static function silentlyKeptStatuses(): array
    {
        return [
            'sent' => [AuditRequestStatus::SENT],
            'expert_review' => [AuditRequestStatus::EXPERT_REVIEW],
        ];
    }

    #[DataProvider('silentlyKeptStatuses')]
    public function test_a_sent_or_expert_held_report_is_kept_without_an_alert(AuditRequestStatus $status): void
    {
        Mail::fake();
        config(['audit.admin_email' => self::ADMIN]);
        $events = $this->captureSentryEvents();
        $service = app(AuditRequestService::class);

        [$failed] = $this->deliveredRequest($status);
        $failed->update(['status' => AuditRequestStatus::ANALYZING->value]);
        $service->markFailed($failed, 'boom');
        [$closed] = $this->deliveredRequest($status);
        $closed->update(['status' => AuditRequestStatus::ANALYZING->value]);
        $service->closeNotAnalyzable($closed, 'No access.');

        $this->assertSame($status->value, $failed->refresh()->status);
        $this->assertSame($status->value, $closed->refresh()->status);
        Mail::assertNothingQueued();
        Mail::assertNothingSent();
        $this->assertCount(0, $events);
    }

    public function test_mark_failed_without_a_report_is_unchanged(): void
    {
        Mail::fake();
        $request = AuditRequest::factory()->create(['status' => AuditRequestStatus::ANALYZING->value]);

        app(AuditRequestService::class)->markFailed($request, 'boom');

        $this->assertSame(AuditRequestStatus::FAILED->value, $request->refresh()->status);
        $this->assertSame(1, AuditFunnelEvent::where('audit_request_id', $request->id)->where('stage', 'failed')->count());
        Mail::assertQueued(AuditRequestFailed::class);
    }
}
