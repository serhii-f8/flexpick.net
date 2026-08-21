<?php

use App\Constants\PlanType;
use App\Models\Currency;
use App\Models\Interval;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use Illuminate\Database\Migrations\Migration;

/**
 * A free, unlimited plan for partners -- assigned manually by a super admin
 * via the Subscriptions resource, never self-serve. Deliberately outside
 * config/pricing.php's catalog: it must never appear on the public pricing
 * page or in the exported marketing pricing.json, and it must not be
 * reconciled/reset by AuditMonetizationSeeder on every seed run.
 *
 * "Unlimited" is a large numeric ceiling on the same audit_* metadata keys
 * every other plan uses (AuditEntitlementService::QUOTA_KEYS), not a new
 * sentinel value -- so quota math, "X of Y left" displays, and every other
 * consumer of plan metadata all work unmodified.
 */
return new class extends Migration
{
    private const SLUG = 'audit-partner';

    public function up(): void
    {
        $currency = Currency::where('code', config('pricing.currency'))->first();
        $month = Interval::where('slug', 'month')->first();

        if ($currency === null || $month === null) {
            // Base catalog (AuditMonetizationSeeder / its currency+interval
            // seeders) hasn't run yet in this environment -- nothing to
            // attach a plan to. Safe to skip: whoever seeds the base catalog
            // can re-run this migration's effect via `migrate` again, since
            // it's idempotent (updateOrCreate throughout).
            return;
        }

        $product = Product::updateOrCreate(['slug' => self::SLUG], [
            'name' => 'Partner (Unlimited)',
            'description' => 'Unlimited audits for partner accounts. Assigned manually -- never sold, never shown publicly.',
            'features' => [
                ['feature' => 'Unlimited automated analyses'],
                ['feature' => 'Unlimited Deep AI review credits'],
                ['feature' => 'Unlimited expert audit credits'],
            ],
            'metadata' => [
                'audit_analyses_per_month' => 999999,
                'audit_deep_ai_credits' => 999999,
                'audit_expert_credits' => 999999,
            ],
            'is_default' => false,
        ]);

        $plan = Plan::updateOrCreate(['slug' => self::SLUG.'-monthly'], [
            'name' => 'Partner (Unlimited) Monthly',
            'product_id' => $product->id,
            'interval_id' => $month->id,
            'interval_count' => 1,
            'has_trial' => false,
            'is_active' => true,
            'is_visible' => false,
            'type' => PlanType::FLAT_RATE->value,
        ]);

        PlanPrice::updateOrCreate(
            ['plan_id' => $plan->id, 'currency_id' => $currency->id],
            ['price' => 0],
        );
    }

    public function down(): void
    {
        $plan = Plan::where('slug', self::SLUG.'-monthly')->first();

        if ($plan !== null) {
            $plan->prices()->delete();
            $plan->delete();
        }

        Product::where('slug', self::SLUG)->delete();
    }
};
