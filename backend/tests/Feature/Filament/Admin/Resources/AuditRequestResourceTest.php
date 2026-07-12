<?php

namespace Tests\Feature\Filament\Admin\Resources;

use App\Constants\AuditRequestStatus;
use App\Filament\Admin\Resources\AuditRequests\AuditRequestResource;
use App\Filament\Admin\Resources\AuditRequests\Pages\ListAuditRequests;
use App\Jobs\GenerateAuditReport;
use App\Models\AuditRequest;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class AuditRequestResourceTest extends FeatureTest
{
    public function test_admin_can_list_audit_requests(): void
    {
        $admin = $this->createAdminUser();
        AuditRequest::factory()->count(2)->create();

        $response = $this->actingAs($admin)->get(AuditRequestResource::getUrl('index', [], true, 'admin'));

        $response->assertStatus(200);
    }

    public function test_launch_action_queues_awaiting_access_request(): void
    {
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
}
