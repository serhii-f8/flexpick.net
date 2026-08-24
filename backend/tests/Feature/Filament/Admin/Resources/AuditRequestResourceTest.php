<?php

namespace Tests\Feature\Filament\Admin\Resources;

use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Filament\Admin\Resources\AuditRequests\AuditRequestResource;
use App\Filament\Admin\Resources\AuditRequests\Pages\ListAuditRequests;
use App\Jobs\GenerateAuditReport;
use App\Models\AuditEmailLog;
use App\Models\AuditRequest;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class AuditRequestResourceTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        AuditEmailLog::query()->delete();
        AuditRequest::query()->delete();
    }

    public function test_admin_can_list_audit_requests(): void
    {
        $admin = $this->createAdminUser();
        AuditRequest::factory()->count(2)->create();

        $response = $this->actingAs($admin)->get(AuditRequestResource::getUrl('index', [], true, 'admin'));

        $response->assertStatus(200);
    }

    public function test_list_shows_the_tier_and_price_each_request_ran_at(): void
    {
        $admin = $this->createAdminUser();
        AuditRequest::factory()->create(['tier' => AuditTier::EXPERT->value]);
        AuditRequest::factory()->create(['tier' => AuditTier::DIAGNOSTIC->value]);

        // setUp() truncates audit_requests, so only these two rows are listed
        // and an absent label really is absent.
        $this->actingAs($admin)->get(AuditRequestResource::getUrl('index', [], true, 'admin'))
            ->assertStatus(200)
            ->assertSee(AuditTier::EXPERT->labelWithPrice())
            ->assertSee(AuditTier::DIAGNOSTIC->labelWithPrice())
            ->assertDontSee(AuditTier::DEEP_AI->labelWithPrice());
    }

    public function test_launch_action_queues_awaiting_access_request(): void
    {
        config(['audit.free_reports_limit' => 3]);
        Queue::fake([GenerateAuditReport::class]);
        $record = AuditRequest::factory()->verified()->create([
            'status' => AuditRequestStatus::AWAITING_ACCESS->value,
        ]);

        Livewire::actingAs($this->createAdminUser())
            ->test(ListAuditRequests::class)
            ->callTableAction('launch', $record);

        $record->refresh();
        $this->assertSame(AuditRequestStatus::QUEUED->value, $record->status);
        $this->assertTrue($record->free_run);
        Queue::assertPushed(GenerateAuditReport::class);
    }

    public function test_launch_action_comps_when_quota_exhausted(): void
    {
        Queue::fake([GenerateAuditReport::class]);
        AuditRequest::factory()->count(3)->freeRun()->create(['email' => 'maxed@example.com']);
        $record = AuditRequest::factory()->verified()->create([
            'email' => 'maxed@example.com',
            'status' => AuditRequestStatus::AWAITING_PAYMENT->value,
        ]);

        Livewire::actingAs($this->createAdminUser())
            ->test(ListAuditRequests::class)
            ->callTableAction('launch', $record);

        $record->refresh();
        $this->assertSame(AuditRequestStatus::QUEUED->value, $record->status);
        $this->assertFalse($record->free_run);
        Queue::assertPushed(GenerateAuditReport::class);
    }

    public function test_retry_action_is_visible_for_expert_review_status(): void
    {
        Queue::fake([GenerateAuditReport::class]);
        $record = AuditRequest::factory()->create([
            'repo_url' => 'https://example.com/repo.git',
            'status' => AuditRequestStatus::EXPERT_REVIEW->value,
        ]);

        Livewire::actingAs($this->createAdminUser())
            ->test(ListAuditRequests::class)
            ->callTableAction('retry', $record);

        $record->refresh();
        $this->assertSame(AuditRequestStatus::QUEUED->value, $record->status);
        Queue::assertPushed(GenerateAuditReport::class);
    }

    public function test_the_stuck_tab_shows_only_stuck_requests(): void
    {
        $this->freezeTime();
        config()->set('health.flexpick.oldest_queued_minutes', 30);
        config()->set('health.flexpick.oldest_analyzing_minutes', 30);

        $admin = $this->createAdminUser();

        $stuck = AuditRequest::factory()->create([
            'status' => AuditRequestStatus::QUEUED->value,
            'repo_url' => 'https://github.com/example/wedged',
            'created_at' => now()->subHours(3),
        ]);
        AuditRequest::factory()->create([
            'status' => AuditRequestStatus::SENT->value,
            'repo_url' => 'https://github.com/example/fine',
        ]);

        Livewire::actingAs($admin)
            ->test(ListAuditRequests::class)
            ->set('activeTab', 'stuck')
            ->assertCanSeeTableRecords([$stuck])
            ->assertCountTableRecords(1);
    }

    public function test_the_needs_action_tab_shows_only_operator_blocked_requests(): void
    {
        $admin = $this->createAdminUser();

        $blocked = AuditRequest::factory()->create(['status' => AuditRequestStatus::AWAITING_ACCESS->value]);
        AuditRequest::factory()->create(['status' => AuditRequestStatus::SENT->value]);

        Livewire::actingAs($admin)
            ->test(ListAuditRequests::class)
            ->set('activeTab', 'needs-action')
            ->assertCanSeeTableRecords([$blocked])
            ->assertCountTableRecords(1);
    }

    public function test_the_failed_tab_shows_only_failed_requests(): void
    {
        $admin = $this->createAdminUser();

        $failed = AuditRequest::factory()->create(['status' => AuditRequestStatus::FAILED->value]);
        AuditRequest::factory()->create(['status' => AuditRequestStatus::SENT->value]);

        Livewire::actingAs($admin)
            ->test(ListAuditRequests::class)
            ->set('activeTab', 'failed')
            ->assertCanSeeTableRecords([$failed])
            ->assertCountTableRecords(1);
    }

    public function test_the_all_tab_is_the_default_and_shows_everything(): void
    {
        $admin = $this->createAdminUser();

        AuditRequest::factory()->count(3)->create(['status' => AuditRequestStatus::SENT->value]);

        Livewire::actingAs($admin)
            ->test(ListAuditRequests::class)
            ->assertSet('activeTab', 'all')
            ->assertCountTableRecords(3);
    }

    public function test_the_table_shows_a_related_email_count(): void
    {
        $admin = $this->createAdminUser();

        $request = AuditRequest::factory()->create(['repo_url' => 'https://github.com/example/mailed']);
        AuditEmailLog::factory()->count(2)->create(['audit_request_id' => $request->id]);

        Livewire::actingAs($admin)
            ->test(ListAuditRequests::class)
            ->assertCanSeeTableRecords([$request])
            ->assertTableColumnStateSet('email_logs_count', 2, $request);
    }

    public function test_the_view_page_renders_the_pipeline_log_as_a_timeline(): void
    {
        $admin = $this->createAdminUser();

        $request = AuditRequest::factory()->create([
            'pipeline_log' => [
                ['step' => 'clone', 'message' => 'Cloned 1200 files', 'at' => now()->subMinutes(10)->toIso8601String()],
                ['step' => 'analyze_failed', 'message' => 'AI returned unparseable JSON', 'at' => now()->subMinutes(4)->toIso8601String()],
            ],
        ]);

        $this->actingAs($admin);

        $this->get(AuditRequestResource::getUrl('view', ['record' => $request], panel: 'admin'))
            ->assertSuccessful()
            ->assertSee(__('Timeline'))
            ->assertSee('clone')
            ->assertSee('Cloned 1200 files')
            ->assertSee('analyze_failed')
            ->assertSee('AI returned unparseable JSON');
    }

    public function test_a_half_written_pipeline_entry_renders_instead_of_throwing(): void
    {
        $admin = $this->createAdminUser();

        // The pipeline may die mid-write, so malformed entries are expected
        // input rather than corruption.
        $request = AuditRequest::factory()->create([
            'pipeline_log' => [
                ['message' => 'no step key'],
                ['step' => 'clone', 'message' => 'bad timestamp', 'at' => 'not-a-date'],
                'a bare string, not an array',
            ],
        ]);

        $this->actingAs($admin);

        $this->get(AuditRequestResource::getUrl('view', ['record' => $request], panel: 'admin'))
            ->assertSuccessful()
            ->assertSee('bad timestamp');
    }

    public function test_an_empty_pipeline_log_shows_the_placeholder(): void
    {
        $admin = $this->createAdminUser();

        $request = AuditRequest::factory()->create(['pipeline_log' => []]);

        $this->actingAs($admin);

        $this->get(AuditRequestResource::getUrl('view', ['record' => $request], panel: 'admin'))
            ->assertSuccessful()
            ->assertSee(__('No processing activity recorded yet.'));
    }

    public function test_the_view_page_lists_this_requests_emails(): void
    {
        $admin = $this->createAdminUser();

        $request = AuditRequest::factory()->create();
        AuditEmailLog::factory()->create([
            'audit_request_id' => $request->id,
            'recipient' => 'infolist-target@example.com',
        ]);

        $this->actingAs($admin);

        $this->get(AuditRequestResource::getUrl('view', ['record' => $request], panel: 'admin'))
            ->assertSuccessful()
            ->assertSee(__('Emails'))
            ->assertSee('infolist-target@example.com');
    }
}
