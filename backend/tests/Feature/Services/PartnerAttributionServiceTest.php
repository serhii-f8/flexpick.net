<?php

namespace Tests\Feature\Services;

use App\Constants\PartnerAttributionSource;
use App\Constants\SessionConstants;
use App\Constants\SubscriptionStatus;
use App\Models\PartnerReferralLink;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
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

    public function test_pending_code_ignores_a_non_string_session_value(): void
    {
        session([SessionConstants::PARTNER_REFERRAL_CODE => ['x']]);

        $this->assertNull(app(PartnerAttributionService::class)->pendingCode());
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

    public function test_attribute_sets_partner_tenant_when_code_resolves_to_an_active_partner(): void
    {
        $partnerTenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        Subscription::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);
        PartnerReferralLink::factory()->create(['tenant_id' => $partnerTenant->id, 'code' => 'REGCODE1']);
        $user = User::factory()->create();

        $service = app(PartnerAttributionService::class);
        $service->rememberPendingCode('REGCODE1');
        $service->attribute($user, PartnerAttributionSource::REGISTRATION);

        $user->refresh();
        $this->assertTrue($user->partnerTenant->is($partnerTenant));
        $this->assertSame(PartnerAttributionSource::REGISTRATION->value, $user->partner_attribution_source);
        $this->assertNull($service->pendingCode());
    }

    public function test_attribute_does_not_overwrite_an_existing_attribution(): void
    {
        $originalPartner = $this->createTenant();
        $otherPartner = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        Subscription::factory()->create([
            'tenant_id' => $otherPartner->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);
        PartnerReferralLink::factory()->create(['tenant_id' => $otherPartner->id, 'code' => 'OTHERCODE']);
        $user = User::factory()->create(['partner_tenant_id' => $originalPartner->id]);

        $service = app(PartnerAttributionService::class);
        $service->rememberPendingCode('OTHERCODE');
        $service->attribute($user, PartnerAttributionSource::LOGIN);

        $this->assertSame($originalPartner->id, $user->fresh()->partner_tenant_id);
    }

    public function test_attribute_ignores_a_code_for_a_tenant_that_is_not_an_active_partner(): void
    {
        $tenant = $this->createTenant();
        PartnerReferralLink::factory()->create(['tenant_id' => $tenant->id, 'code' => 'LAPSEDCODE']);
        $user = User::factory()->create();

        $service = app(PartnerAttributionService::class);
        $service->rememberPendingCode('LAPSEDCODE');
        $service->attribute($user, PartnerAttributionSource::REGISTRATION);

        $this->assertNull($user->fresh()->partner_tenant_id);
    }

    public function test_pending_code_conflicts_with_existing_attribution(): void
    {
        $originalPartner = $this->createTenant();
        $otherPartner = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        Subscription::factory()->create([
            'tenant_id' => $otherPartner->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);
        PartnerReferralLink::factory()->create(['tenant_id' => $otherPartner->id, 'code' => 'CONFLICTCODE']);
        $user = User::factory()->create(['partner_tenant_id' => $originalPartner->id]);

        $service = app(PartnerAttributionService::class);
        $service->rememberPendingCode('CONFLICTCODE');

        $this->assertTrue($service->pendingCodeConflictsWithExisting($user));
    }

    public function test_pending_code_does_not_conflict_when_it_matches_existing_attribution(): void
    {
        $partnerTenant = $this->createTenant();
        PartnerReferralLink::factory()->create(['tenant_id' => $partnerTenant->id, 'code' => 'SAMECODE']);
        $user = User::factory()->create(['partner_tenant_id' => $partnerTenant->id]);

        $service = app(PartnerAttributionService::class);
        $service->rememberPendingCode('SAMECODE');

        $this->assertFalse($service->pendingCodeConflictsWithExisting($user));
    }

    public function test_attribute_is_not_overwritten_by_a_concurrent_call_after_the_first_wins(): void
    {
        $winningPartner = $this->createTenant();
        $losingPartner = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        foreach ([$winningPartner, $losingPartner] as $tenant) {
            Subscription::factory()->create([
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'status' => SubscriptionStatus::ACTIVE->value,
                'ends_at' => now()->addDays(30),
            ]);
        }
        PartnerReferralLink::factory()->create(['tenant_id' => $winningPartner->id, 'code' => 'WINCODE']);
        PartnerReferralLink::factory()->create(['tenant_id' => $losingPartner->id, 'code' => 'LOSECODE']);
        $user = User::factory()->create();

        $service = app(PartnerAttributionService::class);

        // Simulate the first call already having won the race by writing directly.
        User::whereKey($user->id)->update([
            'partner_tenant_id' => $winningPartner->id,
            'partner_attributed_at' => now(),
            'partner_attribution_source' => PartnerAttributionSource::REGISTRATION->value,
        ]);

        // The in-memory $user object is still stale (partner_tenant_id null in memory),
        // simulating a second concurrent request that read the row before the first write.
        $service->rememberPendingCode('LOSECODE');
        $service->attribute($user, PartnerAttributionSource::LOGIN);

        $this->assertSame($winningPartner->id, $user->fresh()->partner_tenant_id);
    }
}
