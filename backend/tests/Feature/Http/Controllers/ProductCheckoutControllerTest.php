<?php

namespace Tests\Feature\Http\Controllers;

use App\Constants\PaymentProviderConstants;
use App\Constants\TenancyPermissionConstants;
use App\Models\Currency;
use App\Models\OneTimeProduct;
use App\Models\OneTimeProductPrice;
use App\Models\PartnerProductOffering;
use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\PartnerPricingResolver;
use App\Services\SessionService;
use Tests\Feature\FeatureTest;

class ProductCheckoutControllerTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        // Checkout only opens to referred buyers, and a referred buyer can
        // only buy what their partner resells through the Offline provider.
        PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)
            ->update(['is_active' => true, 'is_enabled_for_new_payments' => true]);
        app(PartnerPricingResolver::class)->flush();
    }

    private function activePartnerTenant(): Tenant
    {
        return $this->createActivePartnerTenant();
    }

    /** Puts the product on the partner's shelf so a buyer they referred can add it to the cart. */
    private function offeredBy(OneTimeProduct $product, Tenant $partnerTenant, int $price = 9900): OneTimeProduct
    {
        PartnerProductOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'one_time_product_id' => $product->id,
            'price' => $price,
            'quota_overrides' => [],
            'is_enabled' => true,
        ]);

        return $product;
    }

    /**
     * Regression for Finding 3 of the final whole-branch review: the audit
     * unlock/upgrade funnels (AuditRequestController::purchaseRun(),
     * AuditReportController::unlock()) all redirect straight into this
     * action by slug, bypassing the filtered pricing-page listing entirely.
     * Without a check here, an attributed buyer would reach a fully-rendered
     * checkout page and only be rejected on final submit by CheckoutService's
     * guard -- by which point cart/session state has already been mutated.
     * The check must happen here, before that state is touched.
     */
    public function test_an_attributed_buyer_is_redirected_when_adding_a_not_configured_product_to_cart(): void
    {
        PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)
            ->update(['is_active' => true, 'is_enabled_for_new_payments' => true]);
        app(PartnerPricingResolver::class)->flush();

        $partnerTenant = $this->activePartnerTenant();

        $product = OneTimeProduct::factory()->create([
            'slug' => 'not-configured-product-'.rand(1, 100000),
            'is_active' => true,
            'is_visible' => true,
            'max_quantity' => 1,
        ]);
        OneTimeProductPrice::create([
            'one_time_product_id' => $product->id,
            'currency_id' => Currency::where('code', 'USD')->first()->id,
            'price' => 4900,
        ]);

        $user = $this->createUser(null, [], [
            'partner_tenant_id' => $partnerTenant->id,
            'partner_attributed_at' => now(),
        ]);
        $this->actingAs($user);

        $response = $this->get(route('buy.product', ['productSlug' => $product->slug]));

        $response->assertRedirect();
        $response->assertSessionHas('error', __('This product is not currently available for your account.'));
        $this->assertEmpty(app(SessionService::class)->getCartDto()->items);
    }

    public function test_an_attributed_buyer_can_add_a_configured_product_to_cart(): void
    {
        PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)
            ->update(['is_active' => true, 'is_enabled_for_new_payments' => true]);
        app(PartnerPricingResolver::class)->flush();

        $partnerTenant = $this->activePartnerTenant();

        $product = OneTimeProduct::factory()->create([
            'slug' => 'configured-product-'.rand(1, 100000),
            'is_active' => true,
            'is_visible' => true,
            'max_quantity' => 1,
        ]);
        OneTimeProductPrice::create([
            'one_time_product_id' => $product->id,
            'currency_id' => Currency::where('code', 'USD')->first()->id,
            'price' => 4900,
        ]);
        PartnerProductOffering::factory()->create([
            'tenant_id' => $partnerTenant->id,
            'one_time_product_id' => $product->id,
            'price' => 7900,
            'is_enabled' => true,
        ]);

        $user = $this->createUser(null, [], [
            'partner_tenant_id' => $partnerTenant->id,
            'partner_attributed_at' => now(),
        ]);
        $this->actingAs($user);

        $response = $this->get(route('buy.product', ['productSlug' => $product->slug]));

        $response->assertRedirect(route('checkout.product'));
        $this->assertNotEmpty(app(SessionService::class)->getCartDto()->items);
    }

    /**
     * A not-visible product (audit-report-unlock is the live case) can never
     * have a PartnerProductOffering -- PartnerProductPricingTable only lists
     * is_visible ones -- so this gate must exempt it exactly as
     * CheckoutService::assertProductPurchasable() already does. Without the
     * exemption, AuditReportController::unlock() lands every attributed buyer
     * here and they can never unlock a report.
     */
    public function test_an_attributed_buyer_can_add_a_not_visible_product_with_no_offering_to_cart(): void
    {
        PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)
            ->update(['is_active' => true, 'is_enabled_for_new_payments' => true]);
        app(PartnerPricingResolver::class)->flush();

        $partnerTenant = $this->activePartnerTenant();

        $product = OneTimeProduct::factory()->create([
            'slug' => 'not-visible-product-'.rand(1, 100000),
            'is_active' => true,
            'is_visible' => false,
            'max_quantity' => 1,
        ]);
        OneTimeProductPrice::create([
            'one_time_product_id' => $product->id,
            'currency_id' => Currency::where('code', 'USD')->first()->id,
            'price' => 4900,
        ]);

        $user = $this->createUser(null, [], [
            'partner_tenant_id' => $partnerTenant->id,
            'partner_attributed_at' => now(),
        ]);
        $this->actingAs($user);

        $response = $this->get(route('buy.product', ['productSlug' => $product->slug]));

        $response->assertRedirect(route('checkout.product'));
        $response->assertSessionMissing('error');
        $this->assertNotEmpty(app(SessionService::class)->getCartDto()->items);
    }

    public function test_a_direct_buyer_is_turned_away_before_the_cart_is_touched(): void
    {
        // There is no base-price sale: a buyer nobody referred never reaches
        // this action, so nothing lands in their cart either.
        $this->withExceptionHandling();
        $product = OneTimeProduct::factory()->create([
            'slug' => 'direct-buyer-product-'.rand(1, 100000),
            'is_active' => true,
            'max_quantity' => 1,
        ]);
        OneTimeProductPrice::create([
            'one_time_product_id' => $product->id,
            'currency_id' => Currency::where('code', 'USD')->first()->id,
            'price' => 100,
        ]);

        $this->actingAs($this->createUser());

        $response = $this->get(route('buy.product', ['productSlug' => $product->slug]));

        $response->assertForbidden();
        $this->assertEmpty(app(SessionService::class)->getCartDto()->items);
    }

    /**
     * The dashboard's tier purchase writes its checkout intent on the
     * workspace selected in the dashboard, but this action clears the cart
     * and ProductTenantPicker then defaults the order to the user's FIRST
     * orderable workspace. For a member of several, the order -- and the
     * run HandleAuditTierOrder starts from it -- would land on the wrong
     * one. The intent writer names its workspace with `?tenant=`, and that
     * pins the cart.
     */
    public function test_a_tenant_query_parameter_pins_the_cart_to_that_workspace(): void
    {
        $partner = $this->activePartnerTenant();
        $product = $this->offeredBy($this->orderableProduct(), $partner);
        $first = $this->createTenant();
        $second = $this->createTenant();
        $user = $this->createReferredUser($partner, $first, [TenancyPermissionConstants::PERMISSION_CREATE_ORDERS]);
        $second->users()->attach($user);
        $user->tenants()->where('tenant_id', $second->id)->first()->pivot
            ->givePermissionTo(TenancyPermissionConstants::PERMISSION_CREATE_ORDERS);
        $this->actingAs($user);

        $response = $this->get(route('buy.product', ['productSlug' => $product->slug, 'tenant' => $second->uuid]));

        $response->assertRedirect(route('checkout.product'));
        $cart = app(SessionService::class)->getCartDto();
        $this->assertSame($second->uuid, $cart->tenantUuid);
        $this->assertFalse($cart->shouldCreateNewTenant);
    }

    /**
     * The uuid comes off the query string, so it is never trusted: a
     * workspace the user is not a member of, or is a member of without the
     * create-orders permission, is ignored and the picker's default applies.
     */
    public function test_a_tenant_the_user_may_not_order_for_is_ignored(): void
    {
        $partner = $this->activePartnerTenant();
        $product = $this->offeredBy($this->orderableProduct(), $partner);
        $own = $this->createTenant();
        $stranger = $this->createTenant();
        $memberWithoutPermission = $this->createTenant();
        $user = $this->createReferredUser($partner, $own, [TenancyPermissionConstants::PERMISSION_CREATE_ORDERS]);
        $memberWithoutPermission->users()->attach($user);
        $this->actingAs($user);

        foreach ([$stranger->uuid, $memberWithoutPermission->uuid, 'not-a-uuid'] as $uuid) {
            $response = $this->get(route('buy.product', ['productSlug' => $product->slug, 'tenant' => $uuid]));

            $response->assertRedirect(route('checkout.product'));
            $this->assertNull(app(SessionService::class)->getCartDto()->tenantUuid, "'{$uuid}' must not be honoured.");
        }
    }

    public function test_a_guest_with_a_tenant_query_parameter_is_unaffected(): void
    {
        $partner = $this->activePartnerTenant();
        $product = $this->offeredBy($this->orderableProduct(), $partner);
        $tenant = $this->createTenant();

        $response = $this->asReferredGuest($partner)->get(route('buy.product', ['productSlug' => $product->slug, 'tenant' => $tenant->uuid]));

        $response->assertRedirect(route('checkout.product'));
        $this->assertNull(app(SessionService::class)->getCartDto()->tenantUuid);
    }

    private function orderableProduct(): OneTimeProduct
    {
        $product = OneTimeProduct::factory()->create([
            'slug' => 'pinned-tenant-product-'.rand(1, 100000),
            'is_active' => true,
            'max_quantity' => 1,
        ]);
        OneTimeProductPrice::create([
            'one_time_product_id' => $product->id,
            'currency_id' => Currency::where('code', 'USD')->first()->id,
            'price' => 100,
        ]);

        return $product;
    }

    public function test_checkout_loads()
    {
        $partner = $this->activePartnerTenant();
        $product = OneTimeProduct::factory()->create([
            'slug' => 'product-slug-5'.rand(1, 1000),
            'is_active' => true,
            'max_quantity' => 1,
        ]);

        OneTimeProductPrice::create([
            'one_time_product_id' => $product->id,
            'currency_id' => Currency::where('code', 'USD')->first()->id,
            'price' => 100,
        ]);

        $this->offeredBy($product, $partner);

        $response = $this->asReferredGuest($partner)->followingRedirects()->get(route('buy.product', [
            'productSlug' => $product->slug,
        ]));

        $response->assertStatus(200);

        $response->assertSee('Complete your purchase');
        $response->assertDontSeeHtml('wire:model.blur="quantity"');
    }

    public function test_checkout_quantity()
    {
        $partner = $this->activePartnerTenant();
        $product = OneTimeProduct::factory()->create([
            'slug' => 'product-slug-5'.rand(1, 100),
            'is_active' => true,
            'max_quantity' => 5,
        ]);

        OneTimeProductPrice::create([
            'one_time_product_id' => $product->id,
            'currency_id' => Currency::where('code', 'USD')->first()->id,
            'price' => 100,
        ]);

        $this->offeredBy($product, $partner);

        $response = $this->asReferredGuest($partner)->followingRedirects()->get(route('buy.product', [
            'productSlug' => $product->slug,
        ]));

        $response->assertStatus(200);

        $response->assertSee('Complete your purchase');
        $response->assertSeeHtml('wire:model.live.blur="quantity"');
    }
}
