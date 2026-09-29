<?php

namespace Tests\Feature\Services\GitProviders;

use App\Models\TenantGitConnection;
use App\Services\TenantService;
use App\Services\UserService;
use Tests\Feature\FeatureTest;

class GitConnectionCleanupTest extends FeatureTest
{
    public function test_removing_a_member_deletes_only_their_connections_in_that_tenant(): void
    {
        $tenant = $this->createTenant();
        $otherTenant = $this->createTenant();
        $owner = $this->createUser($tenant);
        $leaver = $this->createUser($tenant);
        $otherTenant->users()->attach($leaver);

        $leavers = TenantGitConnection::factory()->for($tenant)->create(['provider' => 'github', 'connected_by_user_id' => $leaver->id]);
        $ownersConnection = TenantGitConnection::factory()->for($tenant)->create(['provider' => 'gitlab', 'connected_by_user_id' => $owner->id]);
        $leaversElsewhere = TenantGitConnection::factory()->for($otherTenant)->create(['provider' => 'github', 'connected_by_user_id' => $leaver->id]);

        $this->actingAs($owner);
        $this->assertTrue(app(TenantService::class)->removeUser($tenant, $leaver));

        $this->assertModelMissing($leavers);
        $this->assertModelExists($ownersConnection);
        $this->assertModelExists($leaversElsewhere);
    }

    public function test_deleting_a_user_deletes_every_connection_they_made(): void
    {
        $tenantA = $this->createTenant();
        $tenantB = $this->createTenant();
        $user = $this->createUser($tenantA);
        $bystander = $this->createUser($tenantA);

        $a = TenantGitConnection::factory()->for($tenantA)->create(['provider' => 'github', 'connected_by_user_id' => $user->id]);
        $b = TenantGitConnection::factory()->for($tenantB)->create(['provider' => 'github', 'connected_by_user_id' => $user->id]);
        $kept = TenantGitConnection::factory()->for($tenantA)->create(['provider' => 'gitlab', 'connected_by_user_id' => $bystander->id]);

        $user->delete();

        $this->assertModelMissing($a);
        $this->assertModelMissing($b);
        $this->assertModelExists($kept);
    }

    public function test_anonymizing_a_user_deletes_every_connection_they_made(): void
    {
        $tenant = $this->createTenant();
        $user = $this->createUser($tenant);
        $connection = TenantGitConnection::factory()->for($tenant)->create(['provider' => 'github', 'connected_by_user_id' => $user->id]);

        app(UserService::class)->anonymize($user);

        $this->assertModelMissing($connection);
    }
}
