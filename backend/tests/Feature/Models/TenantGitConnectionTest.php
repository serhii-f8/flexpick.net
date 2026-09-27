<?php

namespace Tests\Feature\Models;

use App\Models\Tenant;
use App\Models\TenantGitConnection;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTest;

class TenantGitConnectionTest extends FeatureTest
{
    public function test_access_token_is_encrypted_at_rest(): void
    {
        $tenant = Tenant::factory()->create();

        $connection = TenantGitConnection::factory()->for($tenant)->create([
            'provider' => 'github',
            'access_token' => 'ghp_super_secret_token',
        ]);

        $this->assertSame('ghp_super_secret_token', $connection->access_token);

        $rawValue = DB::table('tenant_git_connections')->where('id', $connection->id)->value('access_token');
        $this->assertNotSame('ghp_super_secret_token', $rawValue);
        $this->assertStringNotContainsString('ghp_super_secret_token', $rawValue);
    }

    public function test_one_connection_per_tenant_per_provider(): void
    {
        $tenant = Tenant::factory()->create();
        TenantGitConnection::factory()->for($tenant)->create(['provider' => 'github']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        TenantGitConnection::factory()->for($tenant)->create(['provider' => 'github']);
    }
}
