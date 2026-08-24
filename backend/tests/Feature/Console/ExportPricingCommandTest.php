<?php

namespace Tests\Feature\Console;

use Tests\Feature\FeatureTest;

class ExportPricingCommandTest extends FeatureTest
{
    private function target(): string
    {
        return base_path('../frontend/src/data/pricing.json');
    }

    public function test_writes_every_tier_and_subscription(): void
    {
        $this->artisan('app:export-pricing')->assertSuccessful();

        $exported = json_decode((string) file_get_contents($this->target()), true);

        $this->assertArrayHasKey('audit-deep-ai', $exported['tiers']);
        $this->assertArrayHasKey('audit-enterprise', $exported['subscriptions']);
    }

    public function test_exports_prices_as_display_strings_and_cents(): void
    {
        $this->artisan('app:export-pricing')->assertSuccessful();

        $exported = json_decode((string) file_get_contents($this->target()), true);

        // Both, so the marketing site never formats money itself and never
        // drifts from the figure the backend charges.
        $this->assertSame(11900, $exported['tiers']['audit-deep-ai']['price_cents']);
        $this->assertSame('$119', $exported['tiers']['audit-deep-ai']['price_display']);
        $this->assertSame('$1,500', $exported['subscriptions']['audit-enterprise']['price_display']);
    }

    public function test_check_mode_passes_when_the_committed_file_is_current(): void
    {
        $this->artisan('app:export-pricing')->assertSuccessful();

        $this->artisan('app:export-pricing', ['--check' => true])->assertSuccessful();
    }

    public function test_check_mode_fails_when_configuration_has_drifted(): void
    {
        $this->artisan('app:export-pricing')->assertSuccessful();

        config()->set('pricing.tiers.audit-deep-ai.price', 5900);

        $this->artisan('app:export-pricing', ['--check' => true])->assertFailed();
    }

    public function test_the_report_unlock_price_is_not_exported(): void
    {
        // audit-report-unlock lives in pricing.one_time, not pricing.tiers --
        // it's reachable only from a signed unlock link, never browsed to,
        // so no marketing surface may show it.
        $this->artisan('app:export-pricing')->assertSuccessful();

        $exported = (string) file_get_contents($this->target());

        $this->assertStringNotContainsString('audit-report-unlock', $exported);
    }
}
