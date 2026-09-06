<?php

namespace Tests\Unit\Support;

use App\Support\ScoreBand;
use Tests\TestCase;

class ScoreBandTest extends TestCase
{
    public function test_scores_below_fifty_are_critical(): void
    {
        $this->assertSame(ScoreBand::CRITICAL, ScoreBand::fromScore(0));
        $this->assertSame(ScoreBand::CRITICAL, ScoreBand::fromScore(49));
    }

    public function test_scores_from_fifty_to_sixty_nine_need_work(): void
    {
        $this->assertSame(ScoreBand::WATCH, ScoreBand::fromScore(50));
        $this->assertSame(ScoreBand::WATCH, ScoreBand::fromScore(69));
    }

    public function test_scores_from_seventy_are_healthy(): void
    {
        $this->assertSame(ScoreBand::HEALTHY, ScoreBand::fromScore(70));
        $this->assertSame(ScoreBand::HEALTHY, ScoreBand::fromScore(100));
    }

    public function test_each_band_has_a_plain_language_caption(): void
    {
        $this->assertSame('critical', ScoreBand::CRITICAL->caption());
        $this->assertSame('needs work', ScoreBand::WATCH->caption());
        $this->assertSame('healthy', ScoreBand::HEALTHY->caption());
    }

    public function test_each_band_maps_to_one_css_token(): void
    {
        $this->assertSame('fp-band-critical', ScoreBand::CRITICAL->cssClass());
        $this->assertSame('fp-band-watch', ScoreBand::WATCH->cssClass());
        $this->assertSame('fp-band-healthy', ScoreBand::HEALTHY->cssClass());
    }

    public function test_each_band_maps_to_a_filament_color_name(): void
    {
        $this->assertSame('danger', ScoreBand::CRITICAL->filamentColor());
        $this->assertSame('warning', ScoreBand::WATCH->filamentColor());
        $this->assertSame('primary', ScoreBand::HEALTHY->filamentColor());
    }
}
