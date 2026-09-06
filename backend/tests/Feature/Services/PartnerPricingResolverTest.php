<?php

namespace Tests\Feature\Services;

use App\Constants\PaymentProviderConstants;
use App\Constants\PlanType;
use App\Constants\SessionConstants;
use App\Constants\SubscriptionStatus;
use App\Models\OneTimeProduct;
use App\Models\OneTimeProductPrice;
use App\Models\PartnerPlanOffering;
use App\Models\PartnerProductOffering;
use App\Models\PartnerReferralLink;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrencyService;
use App\Services\PartnerCatalogService;
use App\Services\PartnerPricingResolver;
use Tests\Feature\FeatureTest;

class PartnerPricingResolverTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        // Both columns: PartnerPricingResolver gates on exactly the pair
        // checkout itself filters on (is_active AND is_enabled_for_new_payments).
        // Set explicitly here rather than relying on the seeded default —
        // FeatureTest does not reset the database between test classes.
        PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)
            ->update(['is_active' => true, 'is_enabled_for_new_payments' => true]);

        app(PartnerPricingResolver::class)->flush();
    }

    private function resolver(): PartnerPricingResolver
    {
        return app(PartnerPricingResolver::class);
    }

    private function activePartnerTenant(): Tenant
    {
        $tenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        $plan = Plan::factory()->create(['product_id' => $product->id]);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);

        return $tenant;
    }

    /** A resellable plan priced at $49 base, with a $79 enabled partner offering. */
    private function sellablePlan(Tenant $partnerTenant, int $basePrice = 4900, int $partnerPrice = 7900): array
    {
        $product = Product::factory()->create([
            'metadata' => ['audit_diagnostic_credits' => 1],
            'reseller_quota_keys' => ['audit_diagnostic_credits'],
        ]);
        $plan = Plan::factory()->create([
            'product_id' => $product->id,
            'type' => PlanType::FLAT_RATE->value,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $plan->prices()->create([
            'currency_id' => app(CurrencyService::class)->getCurrency()->id,
            'price' => $basePrice,
        ]);

        $offering = PartnerPlanOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $plan->id,
            'price' => $partnerPrice,
            'quota_overrides' => ['audit_diagnostic_credits' => 3],
            'is_enabled' => true,
        ]);

        return [$plan, $offering];
    }

    private function attributedUser(Tenant $partnerTenant): User
    {
        return $this->createUser(null, [], [
            'partner_tenant_id' => $partnerTenant->id,
            'partner_attributed_at' => now(),
            'partner_attribution_source' => 'registration',
        ]);
    }

    public function test_an_attributed_user_gets_the_partner_price(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        [$plan] = $this->sellablePlan($partnerTenant);
        $user = $this->attributedUser($partnerTenant);

        $this->assertSame(7900, $this->resolver()->planPrice($user, $plan));
        $this->assertTrue($this->resolver()->resolvePartnerTenant($user)->is($partnerTenant));
    }

    public function test_an_unattributed_user_gets_no_partner_price(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        [$plan] = $this->sellablePlan($partnerTenant);
        $user = $this->createUser();

        $this->assertNull($this->resolver()->planPrice($user, $plan));
        $this->assertNull($this->resolver()->resolvePartnerTenant($user));
    }

    public function test_an_anonymous_visitor_with_a_session_code_gets_the_partner_price(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        [$plan] = $this->sellablePlan($partnerTenant);

        $link = PartnerReferralLink::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'is_active' => true,
        ]);

        session([SessionConstants::PARTNER_REFERRAL_CODE => $link->code]);
        $this->resolver()->flush();

        $this->assertSame(7900, $this->resolver()->planPrice(null, $plan));
    }

    public function test_a_disabled_offering_yields_no_partner_price(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        [$plan, $offering] = $this->sellablePlan($partnerTenant);
        $user = $this->attributedUser($partnerTenant);

        $offering->update(['is_enabled' => false]);
        $this->resolver()->flush();

        $this->assertNull($this->resolver()->planPrice($user, $plan));
    }

    public function test_an_offering_below_the_live_minimum_yields_no_partner_price(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        [$plan, $offering] = $this->sellablePlan($partnerTenant, basePrice: 4900, partnerPrice: 5900);
        $user = $this->attributedUser($partnerTenant);

        // Admin raises the base price above what the partner is charging.
        $plan->prices()->update(['price' => 9900]);
        $this->resolver()->flush();

        $this->assertTrue(app(PartnerCatalogService::class)->isPlanOfferingBelowMinimum($offering->fresh()));
        $this->assertNull($this->resolver()->planPrice($user, $plan));
    }

    public function test_a_lapsed_partner_plan_yields_no_partner_price(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        [$plan] = $this->sellablePlan($partnerTenant);
        $user = $this->attributedUser($partnerTenant);

        Subscription::where('tenant_id', $partnerTenant->id)
            ->update(['status' => SubscriptionStatus::INACTIVE->value]);
        $this->resolver()->flush();

        $this->assertNull($this->resolver()->planPrice($user, $plan));
        $this->assertNull($this->resolver()->resolvePartnerTenant($user));
    }

    public function test_an_inactive_offline_provider_yields_no_partner_price(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        [$plan] = $this->sellablePlan($partnerTenant);
        $user = $this->attributedUser($partnerTenant);

        PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)
            ->update(['is_active' => false]);
        $this->resolver()->flush();

        $this->assertNull($this->resolver()->planPrice($user, $plan));
    }

    /**
     * Checkout resolves its provider list through
     * PaymentService::getActivePaymentProvidersFromDatabase(), which requires
     * is_enabled_for_new_payments as well as is_active whenever $isNewPayment
     * — and both checkout forms pass true. A resolver that checked only
     * is_active would quote a partner price and then have
     * restrictToPartnerProviders() filter the list to empty, throwing an
     * unhandled NoPaymentProvidersAvailableException at the customer.
     */
    public function test_an_offline_provider_closed_to_new_payments_yields_no_partner_price(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        [$plan] = $this->sellablePlan($partnerTenant);
        $user = $this->attributedUser($partnerTenant);

        $this->assertSame(7900, $this->resolver()->planPrice($user, $plan), 'Arrangement failure: expected a usable offering to begin with.');

        PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)
            ->update(['is_enabled_for_new_payments' => false]);
        $this->resolver()->flush();

        $this->assertNull($this->resolver()->planPrice($user, $plan));
        $this->assertNull($this->resolver()->usablePlanOffering($user, $plan));

        // Restore the shared row: FeatureTest does not reset the database
        // between test classes, so a mutation left behind here would silently
        // disable partner pricing for every later class.
        PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)
            ->update(['is_enabled_for_new_payments' => true]);
        $this->resolver()->flush();
    }

    public function test_a_seat_based_plan_yields_no_partner_price(): void
    {
        $partnerTenant = $this->activePartnerTenant();

        // Offline cannot bill a seat-based plan at all (OfflineProvider::supportsPlan()
        // returns true only for PlanType::FLAT_RATE), so a partner offering on one
        // must never surface a partner price — there would be no way to pay it.
        $product = Product::factory()->create([
            'metadata' => ['audit_diagnostic_credits' => 1],
            'reseller_quota_keys' => ['audit_diagnostic_credits'],
        ]);
        $plan = Plan::factory()->create([
            'product_id' => $product->id,
            'type' => PlanType::SEAT_BASED->value,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $plan->prices()->create([
            'currency_id' => app(CurrencyService::class)->getCurrency()->id,
            'price' => 4900,
        ]);
        PartnerPlanOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'plan_id' => $plan->id,
            'price' => 7900,
            'quota_overrides' => ['audit_diagnostic_credits' => 3],
            'is_enabled' => true,
        ]);
        $user = $this->attributedUser($partnerTenant);
        $this->resolver()->flush();

        $this->assertNull($this->resolver()->planPrice($user, $plan));
        $this->assertNull($this->resolver()->usablePlanOffering($user, $plan));
    }

    public function test_a_one_time_product_offering_resolves_the_same_way(): void
    {
        $partnerTenant = $this->activePartnerTenant();
        $user = $this->attributedUser($partnerTenant);

        $product = OneTimeProduct::factory()->create([
            'is_active' => true,
            'is_visible' => true,
            'metadata' => ['audit_diagnostic_credits' => 1],
            'reseller_quota_keys' => ['audit_diagnostic_credits'],
        ]);
        OneTimeProductPrice::create([
            'one_time_product_id' => $product->id,
            'currency_id' => app(CurrencyService::class)->getCurrency()->id,
            'price' => 4900,
        ]);
        PartnerProductOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'one_time_product_id' => $product->id,
            'price' => 7900,
            'quota_overrides' => [],
            'is_enabled' => true,
        ]);
        $this->resolver()->flush();

        $this->assertSame(7900, $this->resolver()->productPrice($user, $product));
    }
}
