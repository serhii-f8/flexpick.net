<?php

namespace Tests\Unit\Services;

use App\Services\AuditReport\ScoreChartBuilder;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class ScoreChartBuilderTest extends TestCase
{
    public function test_a_single_score_produces_no_points(): void
    {
        $points = (new ScoreChartBuilder)->build(collect([60]), collect([Carbon::parse('2026-08-01')]));

        $this->assertSame([], $points);
    }

    public function test_a_rising_score_gets_the_positive_color_and_a_directional_tooltip(): void
    {
        $scores = collect([60, 75]);
        $dates = collect([Carbon::parse('2026-08-01'), Carbon::parse('2026-08-20')]);

        $points = (new ScoreChartBuilder)->build($scores, $dates);

        $this->assertCount(2, $points);
        $this->assertNull($points[0]->delta);
        $this->assertSame(15, $points[1]->delta);
        $this->assertSame('text-emerald-500', $points[1]->colorClass);
        $this->assertSame('60 → 75 (+15) on Aug 20, 2026', $points[1]->tooltip);
    }

    public function test_a_falling_score_gets_the_negative_color(): void
    {
        $scores = collect([80, 70]);
        $dates = collect([Carbon::parse('2026-08-01'), Carbon::parse('2026-08-20')]);

        $points = (new ScoreChartBuilder)->build($scores, $dates);

        $this->assertSame(-10, $points[1]->delta);
        $this->assertSame('text-rose-500', $points[1]->colorClass);
    }

    public function test_an_unchanged_score_gets_the_neutral_color(): void
    {
        $scores = collect([70, 70]);
        $dates = collect([Carbon::parse('2026-08-01'), Carbon::parse('2026-08-20')]);

        $points = (new ScoreChartBuilder)->build($scores, $dates);

        $this->assertSame(0, $points[1]->delta);
        $this->assertSame('text-gray-400', $points[1]->colorClass);
    }

    public function test_points_span_the_full_200_unit_width(): void
    {
        $scores = collect([50, 60, 70, 80]);
        $dates = collect(array_fill(0, 4, Carbon::parse('2026-08-01')));

        $points = (new ScoreChartBuilder)->build($scores, $dates);

        $this->assertSame(0.0, $points[0]->x);
        $this->assertSame(200.0, $points[3]->x);
    }
}
