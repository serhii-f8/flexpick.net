<?php

namespace Database\Seeders;

use App\Constants\PlanType;
use App\Models\Currency;
use App\Models\Interval;
use App\Models\OneTimeProduct;
use App\Models\Plan;
use App\Models\Product;
use Illuminate\Database\Seeder;

class AuditMonetizationSeeder extends Seeder
{
    /**
     * Payment-provider identifiers are intentionally NOT seeded: SaaSykit
     * creates provider products/prices on the fly at first checkout
     * (e.g. StripeProvider::findOrCreateStripe*), so placeholder IDs would
     * point at nonexistent Stripe objects and break test checkouts.
     */
    public function run(): void
    {
        $usd = Currency::where('code', 'USD')->firstOrFail();
        $month = Interval::where('slug', 'month')->firstOrFail();

        $unlock = OneTimeProduct::updateOrCreate(['slug' => 'audit-report-unlock'], [
            'name' => 'Full audit report unlock',
            'description' => 'Unlock every finding, recommendation, and the fix-first plan of one codebase audit report, including PDF export.',
            'features' => [
                ['feature' => 'All findings & recommendations'],
                ['feature' => 'Fix-first action plan'],
                ['feature' => 'PDF export'],
            ],
            'max_quantity' => 1,
            'is_active' => true,
            'is_visible' => true,
        ]);
        $unlock->prices()->updateOrCreate(['currency_id' => $usd->id], ['price' => 500]);

        $tiers = [
            ['slug' => 'audit-starter', 'name' => 'Audit Starter', 'allowance' => 5, 'price' => 1000, 'is_popular' => false],
            ['slug' => 'audit-growth', 'name' => 'Audit Growth', 'allowance' => 20, 'price' => 3000, 'is_popular' => true],
            ['slug' => 'audit-scale', 'name' => 'Audit Scale', 'allowance' => 50, 'price' => 6000, 'is_popular' => false],
        ];

        foreach ($tiers as $tier) {
            $product = Product::updateOrCreate(['slug' => $tier['slug']], [
                'name' => $tier['name'],
                'description' => $tier['allowance'].' codebase analyses per month, fully detailed with PDF export.',
                'features' => [
                    ['feature' => $tier['allowance'].' codebase analyses / month'],
                    ['feature' => 'Full detailed reports'],
                    ['feature' => 'PDF export'],
                    ['feature' => 'Re-audit trends'],
                ],
                'is_popular' => $tier['is_popular'],
                'metadata' => ['audit_analyses_per_month' => $tier['allowance']],
                'is_default' => false,
            ]);

            $plan = Plan::updateOrCreate(['slug' => $tier['slug'].'-monthly'], [
                'name' => $tier['name'].' Monthly',
                'product_id' => $product->id,
                'interval_id' => $month->id,
                'interval_count' => 1,
                'has_trial' => false,
                'is_active' => true,
                'is_visible' => true,
                'type' => PlanType::FLAT_RATE->value,
            ]);

            $plan->prices()->updateOrCreate(['currency_id' => $usd->id], ['price' => $tier['price']]);
        }
    }
}
