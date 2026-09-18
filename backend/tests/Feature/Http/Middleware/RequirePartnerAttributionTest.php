<?php

namespace Tests\Feature\Http\Middleware;

use App\Constants\PaymentProviderConstants;
use App\Constants\PlanType;
use App\Constants\ReferralConstants;
use App\Constants\SubscriptionStatus;
use App\Models\OneTimeProduct;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\PartnerPricingResolver;
use App\Services\ReferralService;
use Tests\Feature\FeatureTest;

/**
 * Nothing is ever shown or sold at a price that does not belong to a
 * referrer: the storefront and every purchase entry point open only to a
 * visitor who arrived through a partner link or a user attributed to one.
 */
class RequirePartnerAttributionTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withExceptionHandling();
        config(['app.referral.enabled' => true]);
        PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)
            ->update(['is_active' => true, 'is_enabled_for_new_payments' => true]);
        app(PartnerPricingResolver::class)->flush();
    }

    public function test_a_guest_arriving_through_a_partner_link_sees_the_pricing_page(): void
    {
        $partner = $this->createActivePartnerTenant();
        $code = app(ReferralService::class)->getOrCreateReferralCode($this->createUser($partner))->code;

        $this->get(route('pricing', [ReferralConstants::HTTP_PARAM_REFERRAL_CODE => $code]))
            ->assertOk()
            ->assertSee(__('Plans & Pricing'));
    }

    public function test_a_guest_carrying_the_partner_cookie_sees_the_pricing_page(): void
    {
        $this->asReferredGuest()->get(route('pricing'))->assertOk();
    }

    public function test_a_guest_without_a_referral_is_turned_away(): void
    {
        $this->get(route('pricing'))
            ->assertForbidden()
            ->assertSee(__('Pricing is available through partner links only'))
            ->assertSee(route('login'));
    }

    public function test_a_code_that_is_not_a_partners_does_not_open_the_pricing_page(): void
    {
        $code = app(ReferralService::class)->getOrCreateReferralCode($this->createUser())->code;

        $this->get(route('pricing', [ReferralConstants::HTTP_PARAM_REFERRAL_CODE => $code]))->assertForbidden();
    }

    public function test_a_user_with_a_referrer_sees_the_pricing_page(): void
    {
        $this->actingAs($this->createReferredUser())->get(route('pricing'))->assertOk();
    }

    public function test_a_user_without_a_referrer_is_turned_away_even_with_the_cookie(): void
    {
        // A signed-in user is attributed by the database only (spec §3.4).
        $this->actingAs($this->createUser())
            ->asReferredGuest()
            ->get(route('pricing'))
            ->assertForbidden();
    }

    public function test_a_user_whose_partner_has_lapsed_is_turned_away(): void
    {
        $partner = $this->createActivePartnerTenant();
        Subscription::where('tenant_id', $partner->id)->update(['status' => SubscriptionStatus::CANCELED->value, 'ends_at' => now()->subDay()]);
        app(PartnerPricingResolver::class)->flush();

        // No partner price can be quoted for them any more, so base prices
        // would leak through -- exactly what the gate exists to prevent.
        $this->actingAs($this->createReferredUser($partner))->get(route('pricing'))->assertForbidden();
    }

    public function test_every_purchase_entry_point_is_gated(): void
    {
        $plan = Plan::factory()->create(['type' => PlanType::FLAT_RATE->value, 'is_active' => true, 'is_visible' => true]);
        $product = OneTimeProduct::factory()->create(['is_active' => true, 'is_visible' => true]);

        foreach ([
            route('plan.start'),
            route('checkout.subscription', ['planSlug' => $plan->slug]),
            route('buy.product', ['productSlug' => $product->slug]),
            route('checkout.product'),
        ] as $url) {
            $this->get($url)->assertForbidden();
            $this->actingAs($this->createUser())->get($url)->assertForbidden();
            auth()->logout();
        }
    }

    public function test_a_guest_on_the_home_page_lands_on_the_invite_only_page(): void
    {
        $this->get(route('home'))->assertRedirect(route('pricing.invite-only'));

        $this->get(route('pricing.invite-only'))
            ->assertOk()
            ->assertSee(__('Pricing is available through partner links only'));
    }

    public function test_a_referred_guest_on_the_home_page_still_lands_on_pricing(): void
    {
        $this->asReferredGuest()->get(route('home'))->assertRedirect(route('pricing'));
    }
}
