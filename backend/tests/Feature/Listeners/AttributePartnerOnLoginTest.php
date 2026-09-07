<?php

namespace Tests\Feature\Listeners;

use App\Constants\PartnerAttributionSource;
use App\Constants\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PartnerAttributionService;
use App\Services\ReferralService;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Cookie;
use Tests\Feature\FeatureTest;

class AttributePartnerOnLoginTest extends FeatureTest
{
    /** @return array{0: Tenant, 1: string} */
    private function partnerWithCode(): array
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
        $member = $this->createUser($tenant);

        return [$tenant, app(ReferralService::class)->getOrCreateReferralCode($member)->code];
    }

    public function test_login_attributes_an_unattributed_user_from_the_cookie(): void
    {
        [$tenant, $code] = $this->partnerWithCode();
        $this->app['request']->cookies->set(config('partner.cookie_name'), $code);
        $user = User::factory()->create();

        event(new Login('web', $user, false));

        $this->assertTrue($user->fresh()->partnerTenant->is($tenant));
        $this->assertSame(PartnerAttributionSource::LOGIN->value, $user->fresh()->partner_attribution_source);
    }

    public function test_login_reissues_the_cookie_from_the_database_over_a_stray_cookie(): void
    {
        [$tenant] = $this->partnerWithCode();
        [, $strayCode] = $this->partnerWithCode();
        $this->app['request']->cookies->set(config('partner.cookie_name'), $strayCode);
        $user = User::factory()->create(['partner_tenant_id' => $tenant->id]);

        event(new Login('web', $user, false));

        $this->assertSame($tenant->id, $user->fresh()->partner_tenant_id);
        $queued = Cookie::queued(config('partner.cookie_name'));
        $this->assertNotNull($queued);
        $this->assertTrue(app(PartnerAttributionService::class)->resolveTenantForCode($queued->getValue())->is($tenant));
    }

    /**
     * A direct customer's login clears any stray cookie rather than leaving
     * it alone: refreshCookieFromDatabase() now actively forgets the cookie
     * for a user with no partner, closing the mis-attribution window where a
     * stale cookie from a previous session on the same browser survives.
     */
    public function test_login_of_a_direct_customer_forgets_any_stray_cookie(): void
    {
        // A code that no longer resolves (e.g. the partner's plan lapsed
        // since the cookie was set) so attribute() is a no-op and the only
        // thing left to prove is that refreshCookieFromDatabase() clears it.
        $this->app['request']->cookies->set(config('partner.cookie_name'), 'REF-NOTAPARTNER0');
        $user = User::factory()->create();

        event(new Login('web', $user, false));

        $this->assertNull($user->fresh()->partner_tenant_id);
        $queued = Cookie::queued(config('partner.cookie_name'));
        $this->assertNotNull($queued);
        $this->assertTrue($queued->getExpiresTime() < now()->getTimestamp());
        $this->assertEmpty($queued->getValue());
    }
}
