<?php

namespace Tests\Feature\Filament\Admin\Page;

use App\Filament\Admin\Pages\AuditFunnel;
use App\Models\AuditFunnelEvent;
use App\Models\AuditRequest;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class AuditFunnelPageTest extends FeatureTest
{
    public function test_admin_can_view_funnel_counts(): void
    {
        $admin = $this->createAdminUser();
        $request = AuditRequest::factory()->create();
        AuditFunnelEvent::create(['audit_request_id' => $request->id, 'stage' => 'submitted']);

        $this->actingAs($admin);

        Livewire::test(AuditFunnel::class)
            ->assertOk()
            ->assertSee(__('Audit Funnel'))
            ->assertSee('submitted');
    }
}
