<?php

namespace Tests\Feature\Services;

use App\Models\AuditFunnelEvent;
use App\Models\AuditRequest;
use App\Services\AuditReport\AuditFunnelRecorder;
use App\Services\AuditReport\AuditFunnelStats;
use App\Services\AuditRequestService;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\FeatureTest;

class AuditFunnelTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_recorder_stores_stage_request_and_meta(): void
    {
        $request = AuditRequest::factory()->create();

        app(AuditFunnelRecorder::class)->record(AuditFunnelRecorder::STAGE_QUEUED, $request, ['source' => 'web']);

        $event = AuditFunnelEvent::where('audit_request_id', $request->id)->firstOrFail();
        $this->assertSame('queued', $event->stage);
        $this->assertSame(['source' => 'web'], $event->meta);
        $this->assertNotNull($event->created_at);
    }

    public function test_submit_records_submitted_stage(): void
    {
        app(AuditRequestService::class)->submit(['name' => 'Ada', 'email' => 'funnel-submit@example.com']);

        $request = AuditRequest::where('email', 'funnel-submit@example.com')->firstOrFail();
        $this->assertSame(1, AuditFunnelEvent::where('audit_request_id', $request->id)
            ->where('stage', AuditFunnelRecorder::STAGE_SUBMITTED)->count());
    }

    public function test_stats_zero_fills_all_stages_and_respects_window(): void
    {
        // FeatureTest shares its "seeded once" flag across every test class in the run
        // (the static property isn't redeclared per subclass), so the DB is never reset
        // between classes. Other suites (e.g. HandleAuditUnlockOrderTest) may have already
        // recorded unlock_paid events, so we diff against a baseline instead of asserting
        // an absolute global count.
        $before = app(AuditFunnelStats::class)->counts(30);

        $request = AuditRequest::factory()->create();
        AuditFunnelEvent::create(['audit_request_id' => $request->id, 'stage' => 'submitted']);
        AuditFunnelEvent::create(['audit_request_id' => $request->id, 'stage' => 'verified']);
        AuditFunnelEvent::create(['audit_request_id' => $request->id, 'stage' => 'submitted'])
            ->forceFill(['created_at' => now()->subDays(40)])->save();

        $counts = app(AuditFunnelStats::class)->counts(30);

        $this->assertSame(array_values(AuditFunnelRecorder::STAGES), array_keys($counts));
        $this->assertSame($before['submitted'] + 1, $counts['submitted']);
        $this->assertSame($before['verified'] + 1, $counts['verified']);
        $this->assertSame($before['unlock_paid'], $counts['unlock_paid']);
    }
}
