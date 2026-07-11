<?php

namespace Tests\Feature\Filament\Admin\Resources;

use App\Filament\Admin\Resources\AuditRequests\AuditRequestResource;
use App\Models\AuditRequest;
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
        \Illuminate\Support\Facades\Queue::fake([\App\Jobs\GenerateAuditReport::class]);
        $record = \App\Models\AuditRequest::factory()->verified()->create([
            'status' => \App\Constants\AuditRequestStatus::AWAITING_ACCESS->value,
        ]);

        \Livewire\Livewire::actingAs($this->createAdminUser())
            ->test(\App\Filament\Admin\Resources\AuditRequests\Pages\ListAuditRequests::class)
            ->callTableAction('launch', $record);

        $record->refresh();
        $this->assertSame(\App\Constants\AuditRequestStatus::QUEUED->value, $record->status);
        $this->assertTrue($record->free_run);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\GenerateAuditReport::class);
    }

    public function test_launch_action_comps_when_quota_exhausted(): void
    {
        \Illuminate\Support\Facades\Queue::fake([\App\Jobs\GenerateAuditReport::class]);
        \App\Models\AuditRequest::factory()->count(3)->freeRun()->create(['email' => 'maxed@example.com']);
        $record = \App\Models\AuditRequest::factory()->verified()->create([
            'email' => 'maxed@example.com',
            'status' => \App\Constants\AuditRequestStatus::AWAITING_PAYMENT->value,
        ]);

        \Livewire\Livewire::actingAs($this->createAdminUser())
            ->test(\App\Filament\Admin\Resources\AuditRequests\Pages\ListAuditRequests::class)
            ->callTableAction('launch', $record);

        $record->refresh();
        $this->assertSame(\App\Constants\AuditRequestStatus::QUEUED->value, $record->status);
        $this->assertFalse($record->free_run);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\GenerateAuditReport::class);
    }
}
