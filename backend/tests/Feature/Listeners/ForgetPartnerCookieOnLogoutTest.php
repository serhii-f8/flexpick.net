<?php

namespace Tests\Feature\Listeners;

use App\Constants\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ReferralService;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Cookie;
use Tests\Feature\FeatureTest;

class ForgetPartnerCookieOnLogoutTest extends FeatureTest
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

    /**
     * The scenario from the finding: partner P's cookie survives A's logout
     * and mis-attributes B, an unrelated existing customer, on the next
     * login on the same browser. Clearing the cookie on logout closes it.
     */
    public function test_logout_forgets_the_partner_cookie(): void
    {
        [$tenant, $code] = $this->partnerWithCode();
        $this->app['request']->cookies->set(config('partner.cookie_name'), $code);
        $user = User::factory()->create(['partner_tenant_id' => $tenant->id]);

        event(new Logout('web', $user));

        $queued = Cookie::queued(config('partner.cookie_name'));
        $this->assertNotNull($queued);
        $this->assertTrue($queued->getExpiresTime() < now()->getTimestamp());
        $this->assertEmpty($queued->getValue());
    }
}
