<?php

namespace Tests\Feature\Models;

use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\Feature\FeatureTest;

class PartnerAttributionRelationsTest extends FeatureTest
{
    public function test_user_can_be_attributed_to_a_partner_tenant(): void
    {
        $partnerTenant = $this->createTenant();
        $user = User::factory()->create([
            'partner_tenant_id' => $partnerTenant->id,
            'partner_attributed_at' => now(),
            'partner_attribution_source' => 'registration',
        ]);

        $this->assertTrue($user->partnerTenant->is($partnerTenant));
        $this->assertInstanceOf(Carbon::class, $user->fresh()->partner_attributed_at);
    }

    public function test_tenant_lists_its_referred_users(): void
    {
        $partnerTenant = $this->createTenant();
        $referred = User::factory()->create(['partner_tenant_id' => $partnerTenant->id]);
        User::factory()->create();

        $this->assertTrue($partnerTenant->partnerReferredUsers->pluck('id')->contains($referred->id));
        $this->assertCount(1, $partnerTenant->partnerReferredUsers);
    }
}
