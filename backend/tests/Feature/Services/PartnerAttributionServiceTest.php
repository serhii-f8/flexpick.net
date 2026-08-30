<?php

namespace Tests\Feature\Services;

use App\Models\PartnerReferralLink;
use App\Services\PartnerAttributionService;
use Tests\Feature\FeatureTest;

class PartnerAttributionServiceTest extends FeatureTest
{
    public function test_pending_code_round_trips_through_session(): void
    {
        $service = app(PartnerAttributionService::class);

        $this->assertNull($service->pendingCode());

        $service->rememberPendingCode('ABC123');
        $this->assertSame('ABC123', $service->pendingCode());

        $service->clearPendingCode();
        $this->assertNull($service->pendingCode());
    }

    public function test_resolve_tenant_for_code_finds_the_linked_tenant(): void
    {
        $tenant = $this->createTenant();
        PartnerReferralLink::factory()->create(['tenant_id' => $tenant->id, 'code' => 'FINDME']);

        $resolved = app(PartnerAttributionService::class)->resolveTenantForCode('FINDME');

        $this->assertTrue($resolved->is($tenant));
    }

    public function test_resolve_tenant_for_code_returns_null_for_inactive_link(): void
    {
        $tenant = $this->createTenant();
        PartnerReferralLink::factory()->create(['tenant_id' => $tenant->id, 'code' => 'INACTIVE1', 'is_active' => false]);

        $this->assertNull(app(PartnerAttributionService::class)->resolveTenantForCode('INACTIVE1'));
    }

    public function test_resolve_tenant_for_code_returns_null_for_unknown_code(): void
    {
        $this->assertNull(app(PartnerAttributionService::class)->resolveTenantForCode('NOPE'));
    }
}
