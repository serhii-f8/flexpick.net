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
}
