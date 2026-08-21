<?php

namespace Database\Seeders;

use App\Constants\PlanType;
use App\Models\Currency;
use App\Models\Interval;
use App\Models\OneTimeProduct;
use App\Models\Plan;
use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * Seeds the tiered catalog from config/pricing.php.
 *
 * Payment-provider identifiers are intentionally NOT seeded: SaaSykit creates
 * provider products and prices on the fly at first checkout, so placeholder
 * IDs would point at nonexistent Stripe objects and break test checkouts.
 *
 * Retired products are deactivated, never deleted — an unlock row still backs
 * already-unlocked reports, and a plan with active subscriptions cannot be
 * removed without orphaning them (spec §8.1).
 */
class AuditMonetizationSeeder extends Seeder
{
    public function run(): void
    {
        $currency = Currency::where('code', config('pricing.currency'))->firstOrFail();
        $month = Interval::where('slug', 'month')->firstOrFail();

        $this->seedTierProducts($currency);
        $this->seedOneTimeProducts($currency);
        $this->seedSubscriptions($currency, $month);
        $this->retire();
    }

    private function seedTierProducts(Currency $currency): void
    {
        foreach (config('pricing.tiers') as $slug => $tier) {
            $product = OneTimeProduct::updateOrCreate(['slug' => $slug], [
                'name' => $tier['name'],
                'description' => $tier['description'],
                'features' => array_map(fn (string $f): array => ['feature' => $f], $tier['features']),
                'max_quantity' => 1,
                'is_active' => true,
                'is_visible' => true,
                'metadata' => ['audit_tier' => $tier['tier']],
            ]);

            $product->prices()->updateOrCreate(['currency_id' => $currency->id], ['price' => $tier['price']]);
        }
    }

    /**
     * Standalone one-time products with no tier metadata -- deliberately
     * kept out of pricing.tiers so HandleAuditTierOrder's per-tier purchase
     * loop never sees them, and unmarked visible/active only where the flow
     * that sells them wants it (audit-report-unlock is a real product but
     * not one anybody browses to, so is_visible stays false).
     */
    private function seedOneTimeProducts(Currency $currency): void
    {
        foreach (config('pricing.one_time') as $slug => $item) {
            $product = OneTimeProduct::updateOrCreate(['slug' => $slug], [
                'name' => $item['name'],
                'description' => $item['description'],
                'features' => array_map(fn (string $f): array => ['feature' => $f], $item['features']),
                'max_quantity' => 1,
                'is_active' => true,
                'is_visible' => false,
            ]);

            $product->prices()->updateOrCreate(['currency_id' => $currency->id], ['price' => $item['price']]);
        }
    }

    private function seedSubscriptions(Currency $currency, Interval $month): void
    {
        foreach (config('pricing.subscriptions') as $slug => $subscription) {
            $product = Product::updateOrCreate(['slug' => $slug], [
                'name' => $subscription['name'],
                'description' => $subscription['audit_analyses_per_month']
                    .' automated analyses per month, with full reports and PDF export.',
                'features' => [
                    ['feature' => $subscription['audit_analyses_per_month'].' automated analyses / month'],
                    ['feature' => $subscription['audit_deep_ai_credits'].' Deep AI review credits / month'],
                    ['feature' => 'Full detailed reports'],
                    ['feature' => 'Re-audit trends'],
                ],
                'is_popular' => $subscription['is_popular'],
                'metadata' => [
                    'audit_analyses_per_month' => $subscription['audit_analyses_per_month'],
                    'audit_deep_ai_credits' => $subscription['audit_deep_ai_credits'],
                    'audit_expert_credits' => $subscription['audit_expert_credits'],
                ],
                'is_default' => false,
            ]);

            $plan = Plan::updateOrCreate(['slug' => $slug.'-monthly'], [
                'name' => $subscription['name'].' Monthly',
                'product_id' => $product->id,
                'interval_id' => $month->id,
                'interval_count' => 1,
                'has_trial' => false,
                'is_active' => true,
                'is_visible' => true,
                'type' => PlanType::FLAT_RATE->value,
            ]);

            $plan->prices()->updateOrCreate(['currency_id' => $currency->id], ['price' => $subscription['price']]);
        }
    }

    private function retire(): void
    {
        OneTimeProduct::whereIn('slug', config('pricing.retired.one_time'))
            ->update(['is_active' => false, 'is_visible' => false]);

        Plan::whereIn('slug', config('pricing.retired.plans'))
            ->update(['is_active' => false, 'is_visible' => false]);
    }
}
