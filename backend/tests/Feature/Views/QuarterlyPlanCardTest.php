<?php

namespace Tests\Feature\Views;

use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Product;
use App\Services\CurrencyService;
use Illuminate\Support\Facades\Blade;
use Tests\Feature\FeatureTest;

class QuarterlyPlanCardTest extends FeatureTest
{
    /**
     * A plan billed every N > 1 intervals used to hand a Stringable to the
     * translator, which threw on every page listing such a plan.
     */
    public function test_a_plan_billed_every_three_months_renders_its_interval(): void
    {
        $product = Product::factory()->create(['name' => 'Expert Audit × 1']);
        $plan = Plan::factory()->create(['product_id' => $product->id, 'interval_count' => 3, 'is_active' => true, 'is_visible' => true]);
        PlanPrice::factory()->create(['plan_id' => $plan->id, 'currency_id' => app(CurrencyService::class)->getCurrency()->id, 'price' => 99900]);

        $html = Blade::render('<x-plans.one :plan="$plan" />', ['plan' => $plan->fresh()]);

        $this->assertStringContainsString('per 3 months', $html);
    }
}
