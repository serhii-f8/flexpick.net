<?php

namespace Tests\Feature\Views;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class FpPlanCardComponentTest extends TestCase
{
    public function test_renders_the_plan_with_its_price_features_and_call_to_action(): void
    {
        $html = Blade::render('<x-fp.plan-card name="Growth" price="$149.00" interval="per month" :features="[\'20 Diagnostic Reports / month\', \'Re-audit trends\']" href="/buy/growth" cta="Switch to Growth" />');

        $this->assertStringContainsString('Growth', $html);
        $this->assertStringContainsString('$149.00', $html);
        $this->assertStringContainsString('per month', $html);
        $this->assertStringContainsString('20 Diagnostic Reports / month', $html);
        $this->assertStringContainsString('href="/buy/growth"', $html);
        $this->assertStringContainsString('Switch to Growth', $html);
        $this->assertStringNotContainsString('Most popular', $html);
    }

    public function test_the_current_plan_is_labelled_and_cannot_be_bought_again(): void
    {
        $html = Blade::render('<x-fp.plan-card name="Starter" price="$59.00" interval="per month" href="/buy/starter" cta="Switch to Starter" :current="true" />');

        $this->assertStringContainsString('Current plan', $html);
        $this->assertStringContainsString('fp-plan-card-current', $html);
        $this->assertStringNotContainsString('href="/buy/starter"', $html);
    }

    public function test_popular_and_partner_annotations_render_as_quiet_captions(): void
    {
        $html = Blade::render('<x-fp.plan-card name="Agency" price="$549.00" interval="per month" href="/buy/agency" cta="Buy Agency" :popular="true" partner="Acme Studio" />');

        $this->assertStringContainsString('Most popular', $html);
        $this->assertStringContainsString('Sold through Acme Studio', $html);
    }

    public function test_a_disabled_card_explains_why(): void
    {
        $html = Blade::render('<x-fp.plan-card name="Solo" price="$9.00" interval="per month" href="/buy/solo" cta="Buy Solo" :disabled="true" disabled-reason="Your workspace has too many members for this plan." />');

        $this->assertStringContainsString('Your workspace has too many members for this plan.', $html);
        $this->assertStringNotContainsString('href="/buy/solo"', $html);
    }
}
