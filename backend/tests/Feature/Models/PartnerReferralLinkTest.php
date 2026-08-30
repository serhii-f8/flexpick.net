<?php

namespace Tests\Feature\Models;

use App\Models\PartnerReferralLink;
use Illuminate\Database\QueryException;
use Tests\Feature\FeatureTest;

class PartnerReferralLinkTest extends FeatureTest
{
    public function test_it_belongs_to_a_tenant(): void
    {
        $tenant = $this->createTenant();
        $link = PartnerReferralLink::factory()->create(['tenant_id' => $tenant->id, 'code' => 'ABC123']);

        $this->assertTrue($link->tenant->is($tenant));
        $this->assertTrue($link->is_active);
    }

    public function test_code_must_be_unique(): void
    {
        $tenantA = $this->createTenant();
        $tenantB = $this->createTenant();
        PartnerReferralLink::factory()->create(['tenant_id' => $tenantA->id, 'code' => 'DUPLICATE']);

        $this->expectException(QueryException::class);
        PartnerReferralLink::factory()->create(['tenant_id' => $tenantB->id, 'code' => 'DUPLICATE']);
    }
}
