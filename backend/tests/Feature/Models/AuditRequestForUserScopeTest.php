<?php

namespace Tests\Feature\Models;

use App\Models\AuditRequest;
use App\Models\Tenant;
use App\Services\AuditReport\AuditEntitlementService;
use Tests\Feature\FeatureTest;

class AuditRequestForUserScopeTest extends FeatureTest
{
    public function test_for_user_matches_user_id_and_email_but_not_others(): void
    {
        $user = $this->createUser(null, [], ['email' => 'scope-owner@example.com']);

        $byId = AuditRequest::factory()->create(['user_id' => $user->id, 'email' => 'other@example.com']);
        $byEmail = AuditRequest::factory()->create(['user_id' => null, 'email' => 'scope-owner@example.com']);
        $foreign = AuditRequest::factory()->create(['user_id' => null, 'email' => 'stranger@example.com']);

        $ids = AuditRequest::forUser($user)->pluck('id');

        $this->assertTrue($ids->contains($byId->id));
        $this->assertTrue($ids->contains($byEmail->id));
        $this->assertFalse($ids->contains($foreign->id));
    }

    public function test_has_audit_access_rules(): void
    {
        config(['audit.free_reports_limit' => 3]);
        $entitlements = app(AuditEntitlementService::class);

        // Free-run quota alone → access. This is what lets a directly
        // registered user reach the dashboard audit UI at all.
        $bare = $this->createUser();
        $this->assertTrue($entitlements->hasAuditAccess($bare, null));

        // Has an audit → access regardless of tenant
        $withAudit = $this->createUser();
        AuditRequest::factory()->create(['user_id' => $withAudit->id]);
        $this->assertTrue($entitlements->hasAuditAccess($withAudit, null));

        // With the free quota removed, the remaining arms govern on their own.
        config(['audit.free_reports_limit' => 0]);

        // No audits, no free runs, no tenant → no access
        $this->assertFalse($entitlements->hasAuditAccess($bare, null));

        // Tenant without allowance → no access
        $tenant = Tenant::factory()->create();
        $this->assertFalse($entitlements->hasAuditAccess($bare, $tenant));

        // An existing audit still grants access without any quota
        $this->assertTrue($entitlements->hasAuditAccess($withAudit, null));
    }
}
