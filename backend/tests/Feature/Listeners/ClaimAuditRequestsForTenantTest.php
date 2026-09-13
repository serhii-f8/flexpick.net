<?php

namespace Tests\Feature\Listeners;

use App\Events\Tenant\TenantCreated;
use App\Events\Tenant\UserJoinedTenant;
use App\Models\AuditRequest;
use App\Models\Tenant;
use App\Models\User;
use Tests\Feature\FeatureTest;

class ClaimAuditRequestsForTenantTest extends FeatureTest
{
    public function test_tenant_created_claims_unclaimed_rows_by_user_id_and_email(): void
    {
        $user = User::factory()->create(['email' => 'claim@example.com']);
        $tenant = Tenant::factory()->create(['created_by' => $user->id]);
        $byId = AuditRequest::factory()->create(['user_id' => $user->id, 'email' => 'other@example.com']);
        $byEmail = AuditRequest::factory()->create(['user_id' => null, 'email' => 'Claim@Example.com']);
        $stranger = AuditRequest::factory()->create(['user_id' => null, 'email' => 'stranger@example.com']);

        TenantCreated::dispatch($tenant, $user);

        $this->assertSame($tenant->id, $byId->fresh()->tenant_id);
        $this->assertSame($tenant->id, $byEmail->fresh()->tenant_id);
        $this->assertNull($stranger->fresh()->tenant_id);
    }

    public function test_user_joined_tenant_claims_too(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::factory()->create();
        $request = AuditRequest::factory()->create(['user_id' => $user->id]);

        UserJoinedTenant::dispatch($user, $tenant);

        $this->assertSame($tenant->id, $request->fresh()->tenant_id);
    }

    public function test_an_already_claimed_row_is_never_rehomed(): void
    {
        $user = User::factory()->create();
        $first = Tenant::factory()->create();
        $second = Tenant::factory()->create();
        $request = AuditRequest::factory()->create(['user_id' => $user->id, 'tenant_id' => $first->id]);

        UserJoinedTenant::dispatch($user, $second);

        $this->assertSame($first->id, $request->fresh()->tenant_id);
    }
}
