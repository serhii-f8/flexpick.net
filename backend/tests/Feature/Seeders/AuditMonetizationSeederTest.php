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

    public function test_seeds_the_four_one_time_tier_products(): void
    {
        $this->seedCatalog();

        foreach ([
            'audit-diagnostic' => 4900,
            'audit-automated' => 11900,
            'audit-deep-ai' => 24900,
            'audit-expert' => 99900,
        ] as $slug => $cents) {
            $product = OneTimeProduct::where('slug', $slug)->first();

            $this->assertNotNull($product, "Missing one-time product [{$slug}].");
            $this->assertTrue((bool) $product->is_active);
            $this->assertSame($cents, (int) $product->prices()->first()->price);
        }
    }

    public function test_diagnostic_product_carries_the_diagnostic_tier_metadata(): void
    {
        $this->seedCatalog();

        $product = OneTimeProduct::where('slug', 'audit-diagnostic')->firstOrFail();

        $this->assertSame('diagnostic', $product->metadata['audit_tier']);
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

    /**
     * Deliberately outside config('pricing.subscriptions') -- never shown
     * publicly, never exported to the marketing site -- but must exist for
     * a super admin to assign it, and must grant a huge allowance on every
     * metered tier including Expert, which no other plan grants any of.
     */
    public function test_seeds_a_hidden_free_partner_plan(): void
    {
        $this->seedCatalog();

        $plan = Plan::where('slug', 'audit-partner-monthly')->first();

        $this->assertNotNull($plan, 'Missing plan [audit-partner-monthly].');
        $this->assertTrue((bool) $plan->is_active);
        $this->assertFalse((bool) $plan->is_visible);
        $this->assertSame(0, (int) $plan->prices()->first()->price);

        $metadata = $plan->product->metadata;
        $this->assertSame(99, (int) $metadata['audit_diagnostic_credits']);
        $this->assertSame(99, (int) $metadata['audit_analyses_per_month']);
        $this->assertSame(99, (int) $metadata['audit_deep_ai_credits']);
        $this->assertSame(99, (int) $metadata['audit_expert_credits']);
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

    /**
     * audit-report-unlock is a live, standalone product again (not part of
     * pricing.tiers, so HandleAuditTierOrder never sees it) -- priced to
     * match Diagnostic, but kept distinct so the "unlock the report you
     * already have" flow stays unambiguous from "buy a fresh diagnostic run".
     * Not visible: reachable only from the unlock link, never browsed to.
     */
    public function test_the_report_unlock_product_is_seeded_active_and_priced_like_diagnostic(): void
    {
        $this->seedCatalog();

        $unlock = OneTimeProduct::where('slug', 'audit-report-unlock')->first();

        $this->assertNotNull($unlock, 'Missing one-time product [audit-report-unlock].');
        $this->assertTrue((bool) $unlock->is_active);
        $this->assertFalse((bool) $unlock->is_visible);
        $this->assertSame(500, (int) $unlock->prices()->first()->price);
    }

    public function test_legacy_subscription_plans_are_deactivated_not_deleted(): void
    {
        // Scale is the one plan the new grid orphans (config('pricing.retired.plans')).
        // The row must survive so existing subscriptions keep resolving their plan.
        // Created here rather than guarded on existence: a conditional assertion
        // silently degrades to no assertion at all when the fixture stops matching.
        foreach (config('pricing.retired.plans') as $slug) {
            Plan::factory()->create([
                'slug' => $slug,
                'is_active' => true,
                'is_visible' => true,
            ]);
        }

        $this->seedCatalog();

        foreach (config('pricing.retired.plans') as $slug) {
            $plan = Plan::where('slug', $slug)->first();

            $this->assertNotNull($plan, "The retired plan [{$slug}] must survive to back existing subscriptions.");
            $this->assertFalse((bool) $plan->is_active);
            $this->assertFalse((bool) $plan->is_visible);
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

        foreach (['4900', '11900', '24900', '99900', '5900', '14900', '49900', '150000'] as $literal) {
            $this->assertStringNotContainsString(
                $literal,
                $source,
                "The seeder contains the literal [{$literal}]; prices must come from config('pricing').",
            );
        }

        $this->assertStringContainsString("config('pricing", $source);
    }

    public function test_every_plan_carries_an_expert_credits_metadata_key(): void
    {
        $this->seedCatalog();

        foreach (array_keys(config('pricing.subscriptions')) as $slug) {
            $product = Product::where('slug', $slug)->firstOrFail();

            $this->assertArrayHasKey(
                'audit_expert_credits',
                $product->metadata,
                "Plan {$slug} is missing audit_expert_credits metadata",
            );
            $this->assertSame(
                0,
                $product->metadata['audit_expert_credits'],
                "Plan {$slug} must seed zero expert credits",
            );
        }
    }
}
