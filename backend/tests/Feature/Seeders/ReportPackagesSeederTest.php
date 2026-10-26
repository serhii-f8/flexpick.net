<?php

namespace Tests\Feature\Seeders;

use App\Constants\PaymentProviderPlanPriceType;
use App\Models\Interval;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\PlanPricePaymentProviderData;
use App\Models\Product;
use App\Models\Subscription;
use Database\Seeders\AuditMonetizationSeeder;
use Database\Seeders\ReportPackagesSeeder;
use Tests\Feature\FeatureTest;

class ReportPackagesSeederTest extends FeatureTest
{
    // Named seedPackages(), not seed(): a same-named method here would
    // illegally reduce the visibility of Illuminate\Foundation\Testing\
    // Concerns\InteractsWithDatabase::seed() (a PHP fatal error) and, even
    // made public, would recurse into itself instead of reaching the real
    // seed() the other test methods below call directly with an explicit
    // seeder class.
    private function seedPackages(): void
    {
        $this->seed(ReportPackagesSeeder::class);
    }

    public function test_seeds_every_package_at_the_table_prices_and_billing_period(): void
    {
        $this->seedPackages();

        // [plan slug, price in cents, interval_count in months]
        $expected = [
            'audit-diagnostic-1' => ['audit-diagnostic-1-monthly', 4900, 1],
            'audit-diagnostic-5' => ['audit-diagnostic-5-monthly', 23275, 1],
            'audit-diagnostic-15' => ['audit-diagnostic-15-monthly', 66150, 1],
            'audit-deep-ai-1' => ['audit-deep-ai-1-monthly', 11900, 1],
            'audit-deep-ai-5' => ['audit-deep-ai-5-monthly', 56525, 1],
            'audit-deep-ai-15' => ['audit-deep-ai-15-monthly', 160650, 1],
            'audit-expert-1' => ['audit-expert-1-quarterly', 99900, 3],
            'audit-expert-5' => ['audit-expert-5-monthly', 474525, 1],
            'audit-expert-15' => ['audit-expert-15-monthly', 1348650, 1],
        ];

        foreach ($expected as $slug => [$planSlug, $cents, $months]) {
            $plan = Plan::where('slug', $planSlug)->first();

            $this->assertNotNull($plan, "Missing plan [{$planSlug}].");
            $this->assertTrue((bool) $plan->is_active);
            $this->assertTrue((bool) $plan->is_visible);
            $this->assertSame('month', $plan->interval->slug);
            $this->assertSame($months, (int) $plan->interval_count);
            $this->assertSame($cents, (int) $plan->prices()->first()->price);
            $this->assertSame($slug, $plan->product->slug);
        }
    }

    public function test_the_single_report_plans_never_cost_more_than_buying_one_report(): void
    {
        $oneTime = array_column(config('pricing.tiers'), 'price', 'tier');

        foreach (config('pricing.packages') as $slug => $package) {
            if ($package['actions'] === 1) {
                $this->assertLessThanOrEqual($oneTime[$package['tier']], $package['price'], "Package [{$slug}] costs more than the one-time report.");
            }
        }
    }

    public function test_the_quarterly_plan_describes_its_period(): void
    {
        $this->seedPackages();

        $plan = Plan::where('slug', 'audit-expert-1-quarterly')->firstOrFail();

        $this->assertSame('Expert Audit × 1 Quarterly', $plan->name);
        $this->assertSame('1 Expert Audit per quarter', $plan->product->features[0]['feature']);
    }

    public function test_the_thirty_report_packages_are_retired(): void
    {
        $product = Product::updateOrCreate(['slug' => 'audit-deep-ai-30'], ['name' => 'Deep AI Code Review × 30']);
        $legacy = Plan::updateOrCreate(['slug' => 'audit-deep-ai-30-monthly'], [
            'name' => 'Deep AI Code Review × 30 Monthly',
            'product_id' => $product->id,
            'interval_id' => Interval::where('slug', 'month')->firstOrFail()->id,
            'interval_count' => 1,
            'is_active' => true,
            'is_visible' => true,
        ]);

        $this->seedPackages();

        $this->assertFalse((bool) $legacy->fresh()->is_active);
        $this->assertFalse((bool) $legacy->fresh()->is_visible);
        $this->assertArrayNotHasKey('audit-deep-ai-30', config('pricing.packages'));
    }

    public function test_every_package_price_matches_unit_times_actions_less_discount(): void
    {
        foreach (config('pricing.packages') as $slug => $package) {
            $computed = (int) round($package['unit_price'] * $package['actions'] * (100 - $package['discount_percent']) / 100);

            $this->assertSame($computed, $package['price'], "Package [{$slug}] price drifted from its arithmetic.");
        }
    }

    public function test_a_package_grants_its_actions_on_its_own_tier_only(): void
    {
        $this->seedPackages();

        $metadata = Product::where('slug', 'audit-deep-ai-15')->firstOrFail()->metadata;

        $this->assertSame(0, (int) $metadata['audit_diagnostic_credits']);
        $this->assertSame(15, (int) $metadata['audit_deep_ai_credits']);
        $this->assertSame(0, (int) $metadata['audit_expert_credits']);
        $this->assertSame('deep_ai', $metadata['audit_tier_group']);
        $this->assertSame(15, (int) $metadata['package_actions']);
    }

    public function test_a_package_carries_the_suggested_partner_package_price(): void
    {
        $this->seedPackages();

        $this->assertSame(47500, (int) Product::where('slug', 'audit-diagnostic-5')->firstOrFail()->metadata['partner_suggested_price']);
        $this->assertSame(2295000, (int) Product::where('slug', 'audit-expert-15')->firstOrFail()->metadata['partner_suggested_price']);
        $this->assertSame(170000, (int) Product::where('slug', 'audit-expert-1')->firstOrFail()->metadata['partner_suggested_price']);
    }

    public function test_a_package_describes_its_best_use_case_and_features(): void
    {
        $this->seedPackages();

        $product = Product::where('slug', 'audit-expert-5')->firstOrFail();

        $this->assertSame(config('pricing.package_tiers.expert.headline'), $product->description);
        $this->assertSame('5 Expert Audits per month', $product->features[0]['feature']);
        $this->assertSame([], $product->reseller_quota_keys);
    }

    public function test_is_idempotent_and_resets_a_manual_price_edit(): void
    {
        $this->seedPackages();
        $before = Product::count() + Plan::count() + PlanPrice::count();
        $plan = Plan::where('slug', 'audit-diagnostic-5-monthly')->firstOrFail();
        $plan->prices()->first()->update(['price' => 1]);

        $this->seedPackages();

        $this->assertSame($before, Product::count() + Plan::count() + PlanPrice::count());
        $this->assertSame(23275, (int) $plan->prices()->first()->fresh()->price);
    }

    public function test_a_provider_price_mapping_survives_a_re_run(): void
    {
        $this->seedPackages();
        $price = Plan::where('slug', 'audit-diagnostic-5-monthly')->firstOrFail()->prices()->first();
        $provider = PaymentProvider::where('slug', 'stripe')->firstOrFail();
        PlanPricePaymentProviderData::updateOrCreate(
            ['plan_price_id' => $price->id, 'payment_provider_id' => $provider->id, 'type' => PaymentProviderPlanPriceType::MAIN_PRICE->value],
            ['payment_provider_price_id' => 'price_keepme'],
        );

        $this->seedPackages();

        $this->assertSame('price_keepme', PlanPricePaymentProviderData::where('plan_price_id', $price->id)->value('payment_provider_price_id'));
    }

    public function test_legacy_plans_are_retired_and_their_subscriptions_kept(): void
    {
        $legacy = Plan::updateOrCreate(['slug' => 'audit-growth-monthly'], [
            'name' => 'Growth Monthly',
            'product_id' => Product::updateOrCreate(['slug' => 'audit-growth'], ['name' => 'Growth', 'is_popular' => true])->id,
            'interval_id' => Interval::where('slug', 'month')->firstOrFail()->id,
            'interval_count' => 1,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $subscription = Subscription::factory()->create(['plan_id' => $legacy->id, 'tenant_id' => $this->createTenant()->id]);

        $this->seedPackages();

        $legacy->refresh();
        $this->assertFalse((bool) $legacy->is_active);
        $this->assertFalse((bool) $legacy->is_visible);
        $this->assertFalse((bool) $legacy->product->is_popular);
        $this->assertSame($legacy->id, $subscription->fresh()->plan_id);
    }

    public function test_the_partner_plan_gains_the_reseller_flag_and_keeps_its_credits(): void
    {
        $this->seed(AuditMonetizationSeeder::class);

        $this->seedPackages();

        $metadata = Product::where('slug', 'audit-partner')->firstOrFail()->metadata;
        $this->assertTrue($metadata['enables_reseller_program']);
        $this->assertSame(100, (int) $metadata['audit_diagnostic_credits']);
    }

    public function test_the_seeder_holds_no_literal_money_figure(): void
    {
        $source = (string) file_get_contents(database_path('seeders/ReportPackagesSeeder.php'));

        foreach (['23275', '66150', '117600', '56525', '160650', '285600', '474525', '1348650', '2397600', '4900', '11900', '99900'] as $literal) {
            $this->assertStringNotContainsString($literal, $source);
        }
        $this->assertStringContainsString("config('pricing", $source);
    }
}
