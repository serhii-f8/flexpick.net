<?php

namespace Tests\Feature\Services;

use App\Constants\PartnerAttributionSource;
use App\Constants\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Services\ReferralService;
use App\Services\UserService;
use Illuminate\Support\Facades\Cookie;
use Tests\Feature\FeatureTest;

class UserServiceTest extends FeatureTest
{
    public function test_user_can_be_anonymized()
    {
        $user = User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'public_name' => 'JohnD',
            'phone_number' => '123456789',
            'notes' => 'Some notes',
        ]);

        $user->address()->create([
            'address_line_1' => '123 Street',
            'city' => 'New York',
            'country_code' => 'US',
        ]);

        $this->assertNotNull($user->address);

        $userService = app()->make(UserService::class);
        $userService->anonymize($user);
        $user->refresh();

        $this->assertEquals("Anonymized User {$user->id}", $user->name);
        $this->assertEquals("anonymized_{$user->id}@example.com", $user->email);
        $this->assertEquals("Anonymized User {$user->id}", $user->public_name);
        $this->assertNull($user->phone_number);
        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->phone_number_verified_at);
        $this->assertEquals('Some notes', $user->notes); // Should remain as is
        $this->assertNull($user->address);
        $this->assertNotEquals('password', $user->password);
    }

    public function test_creating_a_user_attributes_the_cookie_partner_and_reissues_the_cookie(): void
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
        $member = $this->createUser($partnerTenant);
        $code = app(ReferralService::class)->getOrCreateReferralCode($member)->code;
        $this->app['request']->cookies->set(config('partner.cookie_name'), $code);

        $user = app(UserService::class)->createUser([
            'name' => 'Jane Doe',
            'email' => 'jane-'.uniqid().'@example.com',
            'password' => 'password',
        ]);

        $this->assertTrue($user->partnerTenant->is($partnerTenant));
        $this->assertSame(PartnerAttributionSource::REGISTRATION->value, $user->partner_attribution_source);
        $this->assertSame($code, Cookie::queued(config('partner.cookie_name'))?->getValue());
    }
}
