<?php

namespace Tests\Feature\Listeners;

use App\Constants\PartnerAttributionSource;
use App\Constants\SessionConstants;
use App\Constants\SubscriptionStatus;
use App\Models\PartnerReferralLink;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Tests\Feature\FeatureTest;

class AttributePartnerOnLoginTest extends FeatureTest
{
    public function test_login_attributes_a_pending_partner_code_for_an_unattributed_user(): void
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
        PartnerReferralLink::factory()->create(['tenant_id' => $partnerTenant->id, 'code' => 'LOGINCODE']);
        session([SessionConstants::PARTNER_REFERRAL_CODE => 'LOGINCODE']);

        $user = User::factory()->create();

        event(new Login('web', $user, false));

        $this->assertTrue($user->fresh()->partnerTenant->is($partnerTenant));
        $this->assertSame(PartnerAttributionSource::LOGIN->value, $user->fresh()->partner_attribution_source);
    }
}
