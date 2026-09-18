<?php

namespace Tests\Feature\Filament\Dashboard;

use App\Constants\OrderStatus;
use App\Constants\SubscriptionStatus;
use App\Constants\TenancyPermissionConstants;
use App\Filament\Dashboard\Widgets\ReferralStatsWidget;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\CurrencyService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class ReferralStatsWidgetTest extends FeatureTest
{
    private function activePartnerTenant(): Tenant
    {
        $tenant = $this->createTenant();
        $product = Product::factory()->create(['metadata' => ['enables_reseller_program' => true]]);
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => Plan::factory()->create(['product_id' => $product->id])->id,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => now()->addDays(30),
        ]);

        return $tenant;
    }

    public function test_it_shows_the_margin_earned_from_approved_orders(): void
    {
        config(['app.referral.enabled' => true]);
        $partner = $this->activePartnerTenant();
        $member = $this->createUser($partner, [TenancyPermissionConstants::PERMISSION_MANAGE_PARTNER_ORDERS]);
        $this->actingAs($member);
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));
        Filament::setTenant($partner);

        $customerTenant = $this->createTenant();
        $customer = $this->createUser($customerTenant, [], ['partner_tenant_id' => $partner->id, 'partner_attributed_at' => now()]);
        Order::factory()->create([
            'user_id' => $customer->id,
            'tenant_id' => $customerTenant->id,
            'partner_tenant_id' => $partner->id,
            'status' => OrderStatus::SUCCESS->value,
            'is_local' => true,
            'total_amount' => 7300,
            'base_price_snapshot' => 4900,
        ]);

        Livewire::test(ReferralStatsWidget::class)
            ->assertSee(__('Margin earned'))
            ->assertSee(money(2400, app(CurrencyService::class)->getCurrency()->code));
    }
}
