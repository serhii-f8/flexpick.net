<?php

namespace Tests\Feature\Seeders;

use App\Models\OneTimeProduct;
use App\Models\Plan;
use App\Models\Product;
use Database\Seeders\AuditMonetizationSeeder;
use Tests\Feature\FeatureTest;

class AuditMonetizationSeederTest extends FeatureTest
{
    private function seedCatalog(): void
    {
        $this->seed(AuditMonetizationSeeder::class);
    }

    public function test_seeds_the_three_one_time_tier_products(): void
    {
        $this->seedCatalog();

        foreach (['audit-automated' => 4900, 'audit-deep-ai' => 19900, 'audit-expert' => 99900] as $slug => $cents) {
            $product = OneTimeProduct::where('slug', $slug)->first();

            $this->assertNotNull($product, "Missing one-time product [{$slug}].");
            $this->assertTrue((bool) $product->is_active);
            $this->assertSame($cents, (int) $product->prices()->first()->price);
        }
    }

    public function test_seeds_the_pitch_subscription_grid(): void
    {
        $this->seedCatalog();

        foreach (['audit-starter' => 5900, 'audit-growth' => 14900,
                  'audit-agency' => 49900, 'audit-enterprise' => 150000] as $slug => $cents) {
            $plan = Plan::where('slug', $slug.'-monthly')->first();

            $this->assertNotNull($plan, "Missing plan [{$slug}-monthly].");
            $this->assertSame($cents, (int) $plan->prices()->first()->price);
        }
    }

    public function test_subscription_products_carry_allowance_metadata(): void
    {
        $this->seedCatalog();

        $growth = Product::where('slug', 'audit-growth')->firstOrFail();

        $this->assertSame(
            config('pricing.subscriptions.audit-growth.audit_analyses_per_month'),
            (int) $growth->metadata['audit_analyses_per_month'],
        );
        $this->assertArrayHasKey('audit_deep_ai_credits', $growth->metadata);
    }

    public function test_the_legacy_five_dollar_unlock_is_deactivated_not_deleted(): void
    {
        // Q32: existing unlocks are grandfathered. Deleting the row would
        // re-lock reports customers already paid for (spec §8.2).
        OneTimeProduct::updateOrCreate(['slug' => 'audit-report-unlock'], [
            'name' => 'Full audit report unlock', 'is_active' => true, 'is_visible' => true,
        ]);

        $this->seedCatalog();

        $unlock = OneTimeProduct::where('slug', 'audit-report-unlock')->first();

        $this->assertNotNull($unlock, 'The unlock product row must survive to back existing purchases.');
        $this->assertFalse((bool) $unlock->is_active);
        $this->assertFalse((bool) $unlock->is_visible);
    }

    public function test_legacy_subscription_plans_are_deactivated_not_deleted(): void
    {
        $this->seedCatalog();

        foreach (['audit-scale-monthly'] as $slug) {
            $plan = Plan::where('slug', $slug)->first();

            if ($plan !== null) {
                $this->assertFalse((bool) $plan->is_active);
            }
        }
    }

    public function test_is_idempotent(): void
    {
        // F5.4.9 — a second run must create no duplicates.
        $this->seedCatalog();
        $firstCount = OneTimeProduct::count() + Plan::count();

        $this->seedCatalog();

        $this->assertSame($firstCount, OneTimeProduct::count() + Plan::count());
    }

    public function test_the_seeder_holds_no_literal_money_figure(): void
    {
        // A15: every figure comes from config/pricing.php, so a price change
        // is one edit and the marketing export cannot drift from the charge.
        $source = (string) file_get_contents(database_path('seeders/AuditMonetizationSeeder.php'));

        foreach (['4900', '19900', '99900', '5900', '14900', '49900', '150000'] as $literal) {
            $this->assertStringNotContainsString(
                $literal,
                $source,
                "The seeder contains the literal [{$literal}]; prices must come from config('pricing').",
            );
        }

        $this->assertStringContainsString("config('pricing", $source);
    }
}
