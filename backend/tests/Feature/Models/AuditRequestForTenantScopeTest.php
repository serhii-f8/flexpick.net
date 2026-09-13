<?php

namespace Tests\Feature\Models;

use App\Models\AuditRequest;
use App\Models\Tenant;
use App\Models\TenantParameter;
use Illuminate\Database\QueryException;
use Tests\Feature\FeatureTest;

class AuditRequestForTenantScopeTest extends FeatureTest
{
    public function test_for_tenant_matches_only_that_tenants_rows(): void
    {
        $tenant = Tenant::factory()->create();
        $other = Tenant::factory()->create();

        $mine = AuditRequest::factory()->create(['tenant_id' => $tenant->id]);
        $theirs = AuditRequest::factory()->create(['tenant_id' => $other->id]);
        $unclaimed = AuditRequest::factory()->create(['tenant_id' => null]);

        $ids = AuditRequest::forTenant($tenant)->pluck('id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id));
        $this->assertFalse($ids->contains($unclaimed->id));
        $this->assertTrue($mine->tenant->is($tenant));
        $this->assertTrue($tenant->auditRequests->contains($mine));
    }

    public function test_deleting_a_tenant_orphans_its_audits_rather_than_deleting_them(): void
    {
        $tenant = Tenant::factory()->create();
        $request = AuditRequest::factory()->create(['tenant_id' => $tenant->id]);

        $tenant->delete();

        $this->assertNull($request->fresh()->tenant_id);
    }

    public function test_tenant_parameters_are_unique_per_name(): void
    {
        $tenant = Tenant::factory()->create();

        TenantParameter::create(['tenant_id' => $tenant->id, 'name' => 'k', 'value' => '1']);

        $this->expectException(QueryException::class);
        TenantParameter::create(['tenant_id' => $tenant->id, 'name' => 'k', 'value' => '2']);
    }
}
