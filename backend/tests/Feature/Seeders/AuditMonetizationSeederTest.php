<?php

namespace Tests\Feature\Seeders;

use App\Models\OneTimeProduct;
use App\Models\Plan;
use App\Models\Product;
use Database\Seeders\AuditMonetizationSeeder;
use Database\Seeders\DatabaseSeeder;
use Tests\Feature\FeatureTest;

class AuditMonetizationSeederTest extends FeatureTest
{
    public function test_seeds_unlock_product_and_plans_idempotently(): void
    {
        $this->seed(AuditMonetizationSeeder::class);
        $this->seed(AuditMonetizationSeeder::class); // idempotent

        $unlock = OneTimeProduct::where('slug', 'audit-report-unlock')->firstOrFail();
        $this->assertSame(500, $unlock->prices()->firstOrFail()->price);
        $this->assertSame(1, OneTimeProduct::where('slug', 'audit-report-unlock')->count());

        foreach ([['audit-starter', 5, 1000], ['audit-growth', 20, 3000], ['audit-scale', 50, 6000]] as [$slug, $allowance, $price]) {
            $product = Product::where('slug', $slug)->firstOrFail();
            $this->assertSame($allowance, $product->metadata['audit_analyses_per_month']);

            $plan = Plan::where('slug', $slug.'-monthly')->firstOrFail();
            $this->assertSame($product->id, $plan->product_id);
            $this->assertSame($price, $plan->prices()->firstOrFail()->price);
        }

        $growth = Product::where('slug', 'audit-growth')->firstOrFail();
        $this->assertTrue((bool) $growth->is_popular);
        $this->assertNotEmpty($growth->features);
        $this->assertNotEmpty($unlock->features);
    }

    public function test_default_database_seeder_includes_audit_catalog(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, OneTimeProduct::where('slug', 'audit-report-unlock')->count());
        $this->assertSame(3, Product::whereIn('slug', ['audit-starter', 'audit-growth', 'audit-scale'])->count());
        $this->assertSame(3, Plan::whereIn('slug', ['audit-starter-monthly', 'audit-growth-monthly', 'audit-scale-monthly'])->where('is_active', true)->count());
    }
}
