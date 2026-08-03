<?php

namespace Tests\Feature\Services;

use App\Mail\Audit\AuditReportUnlocked;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditReport\AuditReportService;
use App\Services\AuditReport\ScoreCalculator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTest;

class AuditReportUnlockTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
    }

    private function payload(): array
    {
        return AuditReport::factory()->raw()['payload'];
    }

    public function test_web_source_report_is_born_locked_without_pdf(): void
    {
        $request = AuditRequest::factory()->verified()->create();

        $report = app(AuditReportService::class)->create($request, $this->payload(), ScoreCalculator::VERSION);

        $this->assertNull($report->unlocked_at);
        $this->assertNull($report->pdf_path);
    }

    public function test_dashboard_source_report_is_born_unlocked_with_pdf(): void
    {
        $request = AuditRequest::factory()->verified()->dashboardSource()->create();

        $report = app(AuditReportService::class)->create($request, $this->payload(), ScoreCalculator::VERSION);

        $this->assertNotNull($report->unlocked_at);
        $this->assertNotNull($report->pdf_path);
        Storage::disk('local')->assertExists($report->pdf_path);
    }

    public function test_unlock_generates_pdf_and_sends_mail_once(): void
    {
        $request = AuditRequest::factory()->verified()->create();
        $report = app(AuditReportService::class)->create($request, $this->payload(), ScoreCalculator::VERSION);

        app(AuditReportService::class)->unlock($report);
        app(AuditReportService::class)->unlock($report); // idempotent

        $report->refresh();
        $this->assertNotNull($report->unlocked_at);
        Storage::disk('local')->assertExists($report->pdf_path);
        Mail::assertQueued(AuditReportUnlocked::class, 1);
    }

    public function test_locked_report_pdf_download_is_denied(): void
    {
        $this->withExceptionHandling();
        $report = AuditReport::factory()->locked()->create();
        $user = User::factory()->create();
        $report->update(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('reports.download', ['auditReport' => $report->uuid]))
            ->assertNotFound();
    }

    public function test_retrying_create_preserves_paid_unlock_and_order_id(): void
    {
        $request = AuditRequest::factory()->verified()->create();
        $report = app(AuditReportService::class)->create($request, $this->payload(), ScoreCalculator::VERSION);
        $order = Order::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
        $report->update(['unlocked_at' => now(), 'unlock_order_id' => $order->id]);

        $retried = app(AuditReportService::class)->create($request, $this->payload(), ScoreCalculator::VERSION);

        $this->assertNotNull($retried->unlocked_at);
        $this->assertSame($order->id, $retried->unlock_order_id);
        $this->assertNotNull($retried->pdf_path);
        Storage::disk('local')->assertExists($retried->pdf_path);
    }

    public function test_retrying_create_on_never_unlocked_web_report_is_still_born_locked(): void
    {
        $request = AuditRequest::factory()->verified()->create();
        app(AuditReportService::class)->create($request, $this->payload(), ScoreCalculator::VERSION);

        $retried = app(AuditReportService::class)->create($request, $this->payload(), ScoreCalculator::VERSION);

        $this->assertNull($retried->unlocked_at);
        $this->assertNull($retried->unlock_order_id);
        $this->assertNull($retried->pdf_path);
    }
}
