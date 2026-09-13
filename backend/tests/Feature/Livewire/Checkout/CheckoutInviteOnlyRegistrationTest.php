<?php

namespace Tests\Feature\Livewire\Checkout;

use App\Constants\SessionConstants;
use App\Dto\CartDto;
use App\Dto\CartItemDto;
use App\Livewire\Checkout\ProductCheckoutForm;
use App\Models\Currency;
use App\Models\OneTimeProduct;
use App\Models\OneTimeProductPrice;
use App\Models\PaymentProvider;
use App\Models\ReferralCode;
use App\Models\User;
use App\Services\PaymentProviders\PaymentProviderInterface;
use App\Services\PaymentProviders\PaymentService;
use App\Services\SessionService;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\Feature\FeatureTest;

/**
 * With REFERRAL_ONLY_REGISTRATION on, /pricing is public and every plan CTA
 * leads to a checkout that registers guests inline. That form must apply
 * the same gate as /register, or the flag is a decoration.
 */
class CheckoutInviteOnlyRegistrationTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.referral.only_registration' => true]);
        $this->addPaymentProvider();
        $this->stubCartWith($this->product());
    }

    public function test_a_cold_guest_cannot_register_at_checkout_without_a_code(): void
    {
        $email = $this->uniqueEmail('cold');

        Livewire::test(ProductCheckoutForm::class)
            ->set('name', 'Cold Visitor')
            ->set('email', $email)
            ->set('password', 'password')
            ->set('paymentProvider', 'paymore')
            ->call('checkout')
            ->assertHasErrors(['referral_code']);

        $this->assertDatabaseMissing('users', ['email' => $email]);
    }

    public function test_a_typed_valid_code_lets_a_guest_register_at_checkout(): void
    {
        $this->referralCode('PARTNER1');
        $email = $this->uniqueEmail('invited');

        Livewire::test(ProductCheckoutForm::class)
            ->set('name', 'Invited Visitor')
            ->set('email', $email)
            ->set('password', 'password')
            ->set('referralCode', 'PARTNER1')
            ->set('paymentProvider', 'paymore')
            ->call('checkout')
            ->assertHasNoErrors()
            ->assertRedirect('http://paymore.com/checkout');

        $this->assertDatabaseHas('users', ['email' => $email]);
    }

    public function test_a_referred_visitor_carrying_a_session_code_never_sees_the_field(): void
    {
        $this->referralCode('PARTNER2');
        session([SessionConstants::REFERRAL_CODE => 'PARTNER2']);
        $email = $this->uniqueEmail('referred');

        Livewire::test(ProductCheckoutForm::class)
            ->assertViewHas('requiresInvitationCode', false)
            ->set('name', 'Referred Visitor')
            ->set('email', $email)
            ->set('password', 'password')
            ->set('paymentProvider', 'paymore')
            ->call('checkout')
            ->assertHasNoErrors()
            ->assertRedirect('http://paymore.com/checkout');

        $this->assertDatabaseHas('users', ['email' => $email]);
    }

    public function test_the_field_is_offered_to_a_cold_guest(): void
    {
        Livewire::test(ProductCheckoutForm::class)
            ->assertViewHas('requiresInvitationCode', true)
            ->assertSee(__('Invitation code'));
    }

    public function test_the_gate_is_inert_when_the_flag_is_off(): void
    {
        config(['app.referral.only_registration' => false]);
        $email = $this->uniqueEmail('anyone');

        Livewire::test(ProductCheckoutForm::class)
            ->assertViewHas('requiresInvitationCode', false)
            ->set('name', 'Anyone')
            ->set('email', $email)
            ->set('password', 'password')
            ->set('paymentProvider', 'paymore')
            ->call('checkout')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => $email]);
    }

    /**
     * The suite shares one database across classes, and RegisterControllerTest
     * and ReferralRegistrationGateTest register the same fixture addresses;
     * a literal here would collide with theirs in a full run.
     */
    private function uniqueEmail(string $prefix): string
    {
        return $prefix.'-'.Str::random(6).'@example.com';
    }

    private function product(): OneTimeProduct
    {
        // No RefreshDatabase in this suite (FeatureTest runs migrate:fresh once
        // per process), so a fixed slug collides across the test methods below.
        $product = OneTimeProduct::factory()->create(['slug' => 'invite-gate-product-'.str()->random(8), 'is_active' => true]);

        OneTimeProductPrice::create([
            'one_time_product_id' => $product->id,
            'currency_id' => Currency::where('code', 'USD')->first()->id,
            'price' => 100,
        ]);

        return $product;
    }

    private function stubCartWith(OneTimeProduct $product): void
    {
        $this->instance(SessionService::class, Mockery::mock(SessionService::class, function (MockInterface $mock) use ($product) {
            $cartDto = new CartDto;
            $cartItem = new CartItemDto;
            $cartItem->productId = $product->id;
            $cartDto->items = [$cartItem];
            $mock->shouldReceive('getCartDto')->andReturn($cartDto);
            $mock->shouldReceive('saveCartDto');
            $mock->shouldReceive('getCouponCode')->andReturn(null);
            $mock->shouldReceive('clearCouponCode');
            $mock->shouldReceive('shouldCreateTenantForFreePlanUser')->andReturn(false);
            $mock->shouldReceive('resetCreateTenantForFreePlanUser');
        }));
    }

    private function referralCode(string $code): ReferralCode
    {
        return ReferralCode::create(['user_id' => User::factory()->create()->id, 'code' => $code]);
    }

    private function addPaymentProvider(bool $isRedirect = true)
    {
        // find or create payment provider
        PaymentProvider::updateOrCreate([
            'slug' => 'paymore',
        ], [
            'name' => 'Paymore',
            'is_active' => true,
            'type' => 'any',
        ]);

        $mock = Mockery::mock(PaymentProviderInterface::class);
        // Unlike ProductCheckoutFormTest (which calls this per test, only when
        // checkout() will complete), this class calls it from setUp() for
        // every test, including ones that never reach checkout() (invite-gate
        // validation failure, view-only assertions) -- so the call count here
        // is zeroOrMoreTimes() rather than the original's once().
        $mock->shouldReceive('initProductCheckout')
            ->zeroOrMoreTimes()
            ->andReturn([]);

        $mock->shouldReceive('isRedirectProvider')
            ->andReturn($isRedirect);

        $mock->shouldReceive('getSlug')
            ->andReturn('paymore');

        $mock->shouldReceive('getName')
            ->andReturn('Paymore');

        $mock->shouldReceive('isOverlayProvider')
            ->andReturn(! $isRedirect);

        if ($isRedirect) {
            $mock->shouldReceive('createProductCheckoutRedirectLink')
                ->andReturn('http://paymore.com/checkout');
        }

        $this->app->instance(PaymentProviderInterface::class, $mock);

        $this->app->bind(PaymentService::class, function () use ($mock) {
            return new PaymentService($mock);
        });

        return $mock;
    }
}
