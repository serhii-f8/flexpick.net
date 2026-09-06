<?php

namespace Tests\Feature\Views;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class FpScoreComponentTest extends TestCase
{
    public function test_the_score_component_renders_the_value_with_its_band_and_caption(): void
    {
        $html = Blade::render('<x-fp.score :value="55" />');

        $this->assertStringContainsString('fp-band-watch', $html);
        $this->assertStringContainsString('>55<', $html);
        $this->assertStringContainsString('needs work', $html);
    }

    public function test_a_missing_score_renders_a_placeholder_without_a_band(): void
    {
        $html = Blade::render('<x-fp.score :value="null" />');

        $this->assertStringContainsString('—', $html);
        $this->assertStringNotContainsString('fp-band-', $html);
    }

    public function test_the_meter_component_fills_to_the_score_and_names_the_band(): void
    {
        $html = Blade::render('<x-fp.meter label="Testing" :value="20" />');

        $this->assertStringContainsString('Testing', $html);
        $this->assertStringContainsString('width: 20%', $html);
        $this->assertStringContainsString('fp-band-critical', $html);
        $this->assertStringContainsString('aria-valuenow="20"', $html);
    }
}
