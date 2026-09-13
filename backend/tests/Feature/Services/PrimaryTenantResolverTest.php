<?php

namespace Tests\Feature\Services;

use App\Models\Tenant;
use App\Models\User;
use App\Services\PrimaryTenantResolver;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTest;

class PrimaryTenantResolverTest extends FeatureTest
{
    public function test_the_tenant_the_user_created_wins(): void
    {
        $user = User::factory()->create();
        $joined = Tenant::factory()->create();
        $joined->users()->attach($user, ['is_default' => true]);
        $created = Tenant::factory()->create(['created_by' => $user->id]);
        $created->users()->attach($user);

        $this->assertTrue(app(PrimaryTenantResolver::class)->resolve($user)->is($created));
    }

    public function test_default_membership_then_earliest(): void
    {
        $user = User::factory()->create();
        $earlier = Tenant::factory()->create();
        $default = Tenant::factory()->create();
        DB::table('tenant_user')->insert([
            ['tenant_id' => $earlier->id, 'user_id' => $user->id, 'is_default' => false, 'created_at' => now()->subDays(2), 'updated_at' => now()],
            ['tenant_id' => $default->id, 'user_id' => $user->id, 'is_default' => true, 'created_at' => now()->subDay(), 'updated_at' => now()],
        ]);

        $this->assertTrue(app(PrimaryTenantResolver::class)->resolve($user)->is($default));

        DB::table('tenant_user')->update(['is_default' => false]);

        $this->assertTrue(app(PrimaryTenantResolver::class)->resolve($user)->is($earlier));
    }

    public function test_no_tenant_resolves_to_null(): void
    {
        $this->assertNull(app(PrimaryTenantResolver::class)->resolve(User::factory()->create()));
    }
}
