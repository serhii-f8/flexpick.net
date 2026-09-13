<?php

namespace Tests\Feature\Models;

use App\Models\AuditRequest;
use App\Models\Tenant;
use App\Models\TenantParameter;
use App\Services\AuditReport\AuditEntitlementService;
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

    public function test_has_audit_access_rules(): void
    {
        config(['audit.free_reports_limit' => 3]);
        $entitlements = app(AuditEntitlementService::class);

        // Free-run quota alone → access. This is what lets a directly
        // registered user reach the dashboard audit UI at all.
        $bare = Tenant::factory()->create();
        $this->assertTrue($entitlements->hasAuditAccess($bare));

        // Has an audit → access regardless of quota
        $withAudit = Tenant::factory()->create();
        AuditRequest::factory()->create(['tenant_id' => $withAudit->id]);
        $this->assertTrue($entitlements->hasAuditAccess($withAudit));

        // With the free quota removed, the remaining arms govern on their own.
        config(['audit.free_reports_limit' => 0]);

        // No audits, no free runs, no allowance → still access, because every
        // tier is priced and a workspace that can buy a run can reach the UI.
        $this->assertTrue($entitlements->hasAuditAccess($bare));

        // Empty the catalog and there is genuinely nothing left to grant it.
        config(['pricing.tiers' => []]);
        $this->assertFalse($entitlements->hasAuditAccess($bare));

        // An existing audit still grants access without any quota
        $this->assertTrue($entitlements->hasAuditAccess($withAudit));
    }
}
