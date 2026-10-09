<?php

namespace Tests\Unit\Services\AuditReport;

use App\Services\AuditReport\ReportChartBuilder;
use Tests\TestCase;

class ReportChartBuilderTest extends TestCase
{
    private function charts(): ReportChartBuilder
    {
        return new ReportChartBuilder;
    }

    private function assertWellFormedSvg(string $svg): \SimpleXMLElement
    {
        $xml = simplexml_load_string($svg);
        $this->assertNotFalse($xml, 'SVG must be well-formed XML');
        $this->assertSame('svg', $xml->getName());
        $this->assertSame('img', (string) $xml['role']);
        $this->assertNotSame('', trim((string) $xml->title));

        return $xml;
    }

    public function test_score_gauge_prints_the_score_and_uses_its_band_colour(): void
    {
        $svg = $this->charts()->scoreGauge(44);

        $this->assertWellFormedSvg($svg);
        $this->assertStringContainsString('>44<', $svg);
        $this->assertStringContainsString('#c55538', $svg); // critical band (< 50)
    }

    public function test_score_gauge_clamps_out_of_range_scores(): void
    {
        $this->assertStringContainsString('>100<', $this->charts()->scoreGauge(140));
        $this->assertStringContainsString('>0<', $this->charts()->scoreGauge(-5));
    }

    public function test_score_gauge_at_zero_draws_only_the_track(): void
    {
        $xml = $this->assertWellFormedSvg($this->charts()->scoreGauge(0));

        $this->assertCount(1, $xml->path);
    }

    public function test_percentile_bar_places_the_marker_proportionally(): void
    {
        $svg = $this->charts()->percentileBar(62);

        $this->assertWellFormedSvg($svg);
        $this->assertStringContainsString('cx="186"', $svg); // 62% of 300
    }

    public function test_area_bars_label_each_measured_dimension_in_plain_words(): void
    {
        $svg = $this->charts()->areaBars(['testing' => 12, 'structure' => 58], []);

        $this->assertWellFormedSvg($svg);
        $this->assertStringContainsString(ReportChartBuilder::AREA_LABELS['testing'], $svg);
        $this->assertStringContainsString('>12<', $svg);
        $this->assertStringNotContainsString('security_hygiene', $svg);
    }

    public function test_area_bars_draw_an_unmeasured_dimension_as_an_empty_track(): void
    {
        $svg = $this->charts()->areaBars([], ['testing']);

        $this->assertWellFormedSvg($svg);
        $this->assertStringContainsString(__('not checked'), $svg);
    }

    public function test_severity_stack_groups_severities_into_plain_segments(): void
    {
        $svg = $this->charts()->severityStack(['critical' => 1, 'high' => 2, 'medium' => 3, 'low' => 0, 'info' => 4]);

        $this->assertWellFormedSvg($svg);
        $this->assertStringContainsString(ReportChartBuilder::SEGMENT_LABELS['urgent'].' 3', $svg);
        $this->assertStringContainsString(ReportChartBuilder::SEGMENT_LABELS['important'].' 3', $svg);
        $this->assertStringNotContainsString(ReportChartBuilder::SEGMENT_LABELS['worth_fixing'], $svg);
    }

    public function test_severity_stack_with_no_issues_renders_an_empty_state(): void
    {
        $svg = $this->charts()->severityStack([]);

        $this->assertWellFormedSvg($svg);
        $this->assertStringContainsString(__('No issues found'), $svg);
    }

    public function test_business_area_bars_scale_to_the_largest_count(): void
    {
        $svg = $this->charts()->businessAreaBars(['customers' => 2, 'costs' => 1, 'security' => 0, 'speed' => 0]);

        $this->assertWellFormedSvg($svg);
        $this->assertStringContainsString(ReportChartBuilder::BUSINESS_AREA_LABELS['customers'], $svg);
        $this->assertStringContainsString('width="90"', $svg); // costs: 1 of max 2 fills half the 180px track
    }

    public function test_business_area_bars_with_all_zero_counts_render_an_empty_state(): void
    {
        $svg = $this->charts()->businessAreaBars(['customers' => 0]);

        $this->assertWellFormedSvg($svg);
        $this->assertStringContainsString(__('Nothing to show'), $svg);
    }

    public function test_labels_are_escaped(): void
    {
        $this->assertWellFormedSvg($this->charts()->areaBars(['structure' => 50], []));
        $this->assertStringNotContainsString('&amp;amp;', $this->charts()->businessAreaBars(['security' => 1]));
    }
}
