<?php

namespace Database\Seeders;

use App\Constants\PlanType;
use App\Models\Currency;
use App\Models\Interval;
use App\Models\Plan;
use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * The nine monthly report packages from config('pricing.packages'), safe to
 * run on a live database at any time:
 *
 *   php artisan db:seed --class=ReportPackagesSeeder
 *
 * Idempotent on slug. Every package is a NEW slug on purpose: the Stripe
 * provider reuses a stored price id whenever one exists, so repricing an
 * existing plan in place would keep charging the old amount. Retired plans
 * are deactivated, never deleted — their subscriptions keep resolving.
 * Provider price mappings (plan_price_payment_provider_data) are never
 * touched. Nothing here holds a literal money figure.
 */
class ReportPackagesSeeder extends Seeder
{
    public function run(): void
    {
        $currency = Currency::where('code', config('pricing.currency'))->firstOrFail();
        $month = Interval::where('slug', 'month')->firstOrFail();
        $tiers = config('pricing.package_tiers');

        foreach (config('pricing.packages') as $slug => $package) {
            $tier = $tiers[$package['tier']];

            $credits = array_fill_keys(array_column($tiers, 'credit_key'), 0);
            $credits[$tier['credit_key']] = $package['actions'];

            $product = Product::updateOrCreate(['slug' => $slug], [
                'name' => $package['name'],
                'description' => $tier['headline'],
                'features' => [
                    ['feature' => "{$package['actions']} {$tier['plural']} per month"],
                    ['feature' => 'Full detailed reports'],
                    ['feature' => 'PDF export'],
                ],
                'is_popular' => $package['is_popular'],
                'is_default' => false,
                'metadata' => $credits + [
                    'audit_tier_group' => $package['tier'],
                    'package_actions' => $package['actions'],
                    'partner_suggested_price' => (int) round($package['partner_unit_price'] * $package['actions'] * (100 - $package['discount_percent']) / 100),
                ],
                'reseller_quota_keys' => [],
            ]);

            $plan = Plan::updateOrCreate(['slug' => $slug.'-monthly'], [
                'name' => $package['name'].' Monthly',
                'product_id' => $product->id,
                'interval_id' => $month->id,
                'interval_count' => 1,
                'has_trial' => false,
                'is_active' => true,
                'is_visible' => true,
                'type' => PlanType::FLAT_RATE->value,
            ]);

            $plan->prices()->updateOrCreate(['currency_id' => $currency->id], ['price' => $package['price']]);
        }

        $this->flagResellerPlan();
        $this->retire();
    }

    /**
     * The hidden, admin-assigned audit-partner plan is the Partner plan
     * (spec §6.2). Merged, not replaced, so its credit allowances survive.
     * AuditMonetizationSeeder writes the same flag, so a full reseed keeps it.
     */
    private function flagResellerPlan(): void
    {
        $product = Product::where('slug', 'audit-partner')->first();

        if ($product === null) {
            return;
        }

        $product->metadata = ['enables_reseller_program' => true] + (array) $product->metadata;
        $product->save();
    }

    private function retire(): void
    {
        $plans = Plan::whereIn('slug', config('pricing.retired.plans'))->get();

        foreach ($plans as $plan) {
            $plan->update(['is_active' => false, 'is_visible' => false]);
            $plan->product?->update(['is_popular' => false]);
        }
    }
}
