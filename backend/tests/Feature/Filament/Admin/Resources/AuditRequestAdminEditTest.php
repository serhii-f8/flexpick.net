<?php

namespace Tests\Feature\Filament\Admin\Resources;

use App\Constants\AuditRequestStatus;
use App\Filament\Admin\Resources\AuditRequests\AuditRequestResource;
use App\Filament\Admin\Resources\AuditRequests\Pages\EditAuditRequest;
use App\Filament\Admin\Resources\AuditRequests\Pages\ViewAuditRequest;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class AuditRequestAdminEditTest extends FeatureTest
{
    public function test_admin_can_edit_status_input_data_and_context(): void
    {
        $admin = $this->createAdminUser();
        $audit = AuditRequest::factory()->create(['status' => AuditRequestStatus::NEEDS_FOLLOWUP->value]);

        $this->actingAs($admin);

        Livewire::actingAs($admin)
            ->test(EditAuditRequest::class, ['record' => $audit->uuid])
            ->fillForm([
                'status' => AuditRequestStatus::HANDLED->value,
                'repo_url' => 'https://github.com/acme/corrected',
                'admin_context' => 'Client says the payment module matters most.',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $audit->fresh();
        $this->assertSame(AuditRequestStatus::HANDLED->value, $fresh->status);
        $this->assertSame('https://github.com/acme/corrected', $fresh->repo_url);
        $this->assertSame('Client says the payment module matters most.', $fresh->admin_context);
    }

    public function test_view_page_shows_prompt_preview_and_pipeline_log(): void
    {
        $admin = $this->createAdminUser();
        $audit = AuditRequest::factory()->create([
            'metrics' => ['files' => 7],
            'admin_context' => 'Look at auth.',
        ]);
        $audit->appendPipelineLog('cloned', 'Repository cloned');

        $this->actingAs($admin);

        $this->get(AuditRequestResource::getUrl('view', ['record' => $audit->uuid], panel: 'admin'))
            ->assertSuccessful()
            ->assertSee('Look at auth.')
            ->assertSee('Repository cloned');
    }

    public function test_results_override_rejects_invalid_payload_and_saves_valid_one(): void
    {
        $admin = $this->createAdminUser();
        $audit = AuditRequest::factory()->create();
        $report = AuditReport::factory()->create(['audit_request_id' => $audit->id]);

        $this->actingAs($admin);

        // invalid JSON rejected
        Livewire::actingAs($admin)
            ->test(ViewAuditRequest::class, ['record' => $audit->uuid])
            ->callAction('editResults', data: ['payload' => 'not json'])
            ->assertHasActionErrors(['payload']);

        // missing scores.overall rejected
        Livewire::actingAs($admin)
            ->test(ViewAuditRequest::class, ['record' => $audit->uuid])
            ->callAction('editResults', data: ['payload' => json_encode(['summary' => 'x'])])
            ->assertHasActionErrors(['payload']);

        // valid payload saved
        $valid = $report->payload;
        $valid['summary'] = 'Corrected by admin.';

        Livewire::actingAs($admin)
            ->test(ViewAuditRequest::class, ['record' => $audit->uuid])
            ->callAction('editResults', data: ['payload' => json_encode($valid)])
            ->assertHasNoActionErrors();

        $this->assertSame('Corrected by admin.', $report->fresh()->payload['summary']);
    }
}
