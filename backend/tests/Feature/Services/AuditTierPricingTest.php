<?php

namespace Tests\Feature\Services;

use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Constants\PaymentProviderConstants;
use App\Filament\Dashboard\Pages\AuditReports;
use App\Filament\Dashboard\Resources\AuditRequests\Pages\ListAuditRequests;
use App\Mail\Audit\AuditQuotaExhausted;
use App\Models\AuditRequest;
use App\Models\Currency;
use App\Models\OneTimeProduct;
use App\Models\OneTimeProductPrice;
use App\Models\PartnerProductOffering;
use App\Models\PaymentProvider;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditReport\AuditTierPricing;
use App\Services\PartnerPricingResolver;
use App\Services\ReferralService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

/**
 * A customer a partner referred pays the partner's price, so every audit
 * price the dashboard (or an email) quotes them must be that price -- the
 * same one /pricing shows -- never the base catalog's.
 */
class AuditTierPricingTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        PaymentProvider::where('slug', PaymentProviderConstants::OFFLINE_SLUG)
            ->update(['is_active' => true, 'is_enabled_for_new_payments' => true]);
        app(PartnerPricingResolver::class)->flush();
    }

    private function tierProduct(AuditTier $tier): OneTimeProduct
    {
        $slug = $tier->productSlug();
        $product = OneTimeProduct::where('slug', $slug)->first()
            ?? OneTimeProduct::factory()->create(['slug' => $slug, 'is_active' => true]);

        OneTimeProductPrice::updateOrCreate(
            ['one_time_product_id' => $product->id, 'currency_id' => Currency::where('code', 'USD')->first()->id],
            ['price' => $tier->priceCents()],
        );

        return $product;
    }

    private function offer(Tenant $partner, AuditTier $tier, int $price): void
    {
        PartnerProductOffering::factory()->create([
            'tenant_id' => $partner->id,
            'one_time_product_id' => $this->tierProduct($tier)->id,
            'price' => $price,
            'quota_overrides' => [],
            'is_enabled' => true,
        ]);
    }

    public function test_a_referred_customer_is_quoted_the_partners_price(): void
    {
        $partner = $this->createActivePartnerTenant();
        $this->offer($partner, AuditTier::DIAGNOSTIC, 7700);
        $user = $this->createReferredUser($partner);

        $this->assertSame(7700, app(AuditTierPricing::class)->priceCentsFor(AuditTier::DIAGNOSTIC, $user));
        $this->assertSame('Diagnostic Report — $77', app(AuditTierPricing::class)->labelWithPrice(AuditTier::DIAGNOSTIC, $user));
    }

    public function test_a_tier_the_partner_does_not_resell_has_no_price_for_their_customer(): void
    {
        $partner = $this->createActivePartnerTenant();
        $user = $this->createReferredUser($partner);
        $this->tierProduct(AuditTier::EXPERT);

        // Checkout would refuse it (catalog restriction), so it must not be offered either.
        $this->assertNull(app(AuditTierPricing::class)->priceCentsFor(AuditTier::EXPERT, $user));
        $this->assertSame('Expert Audit', app(AuditTierPricing::class)->labelWithPrice(AuditTier::EXPERT, $user));
    }

    public function test_an_unreferred_customer_keeps_the_catalog_price(): void
    {
        $user = User::factory()->create();

        $this->assertSame(AuditTier::DIAGNOSTIC->priceCents(), app(AuditTierPricing::class)->priceCentsFor(AuditTier::DIAGNOSTIC, $user));
    }

    public function test_the_dashboard_never_shows_a_referred_customer_the_base_price(): void
    {
        $partner = $this->createActivePartnerTenant();
        $this->offer($partner, AuditTier::DIAGNOSTIC, 7700);
        $this->offer($partner, AuditTier::DEEP_AI, 15500);
        $tenant = $this->createTenant();
        $user = $this->createReferredUser($partner, $tenant);
        AuditRequest::factory()->count(3)->freeRun()->create(['tenant_id' => $tenant->id, 'email' => $user->email]);
        AuditRequest::factory()->create(['tenant_id' => $tenant->id, 'tier' => AuditTier::DEEP_AI->value, 'status' => AuditRequestStatus::SENT->value]);

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($tenant);

        Livewire::actingAs($user)->test(AuditReports::class)
            ->assertSee('$77')
            ->assertDontSee('$49')
            ->assertDontSee('$119')
            ->assertDontSee('$999');

        Livewire::actingAs($user)->test(ListAuditRequests::class)
            ->assertSee('Deep AI Code Review — $155')
            ->assertDontSee('$119');
    }

    public function test_the_quota_email_to_a_referred_visitor_quotes_no_base_price(): void
    {
        $partner = $this->createActivePartnerTenant();
        $referrer = $this->createUser($partner);
        $code = app(ReferralService::class)->getOrCreateReferralCode($referrer)->code;
        $request = AuditRequest::factory()->create(['meta' => ['referral_code' => $code]]);

        $html = (new AuditQuotaExhausted($request, 'https://buy.example'))->render();

        $this->assertStringNotContainsString('$'.number_format(AuditTier::DIAGNOSTIC->priceCents() / 100), $html);
        $this->assertStringNotContainsString('/month', $html);
        $this->assertStringContainsString('https://buy.example', $html);
    }

    public function test_the_quota_email_to_an_unreferred_visitor_keeps_the_price(): void
    {
        $request = AuditRequest::factory()->create(['meta' => []]);

        $html = (new AuditQuotaExhausted($request, 'https://buy.example'))->render();

        $this->assertStringContainsString('$'.number_format(AuditTier::DIAGNOSTIC->priceCents() / 100), $html);
    }
}
