<?php

namespace App\Services\AuditReport;

use App\Support\ScoreBand;

/**
 * The business report's charts, as SVG strings: numbers in, markup out, no
 * I/O. The same string goes inline on the web page and, base64'd, into the
 * PDF -- dompdf runs no JavaScript, so this is the one way a chart can look
 * the same in both. Keep every chart to rect/path/circle/text: dompdf's SVG
 * renderer handles nothing fancier.
 */
class ReportChartBuilder
{
    /** Print-safe brand colours (the light theme's values). */
    private const BAND_COLORS = ['critical' => '#c55538', 'watch' => '#a8712b', 'healthy' => '#b98e41'];

    private const TRACK = '#e8e2d6';

    private const INK = '#1c1917';

    private const MUTED = '#78716c';

    private const FONT = 'font-family="DejaVu Sans, Helvetica, Arial, sans-serif"';

    public const DIMENSIONS = ['structure', 'duplication', 'testing', 'dependencies', 'security_hygiene'];

    public const AREA_LABELS = [
        'structure' => 'Code organisation',
        'duplication' => 'Repeated code',
        'testing' => 'Automatic checks',
        'dependencies' => 'Third-party parts',
        'security_hygiene' => 'Security basics',
    ];

    /** Plain-language segments, worst first, and the severities each covers. */
    private const SEGMENTS = [
        'urgent' => ['critical', 'high'],
        'important' => ['medium'],
        'worth_fixing' => ['low'],
        'minor' => ['info'],
    ];

    public const SEGMENT_LABELS = [
        'urgent' => 'Urgent',
        'important' => 'Important',
        'worth_fixing' => 'Worth fixing',
        'minor' => 'Minor',
    ];

    private const SEGMENT_COLORS = [
        'urgent' => '#c55538',
        'important' => '#c98a3b',
        'worth_fixing' => '#b98e41',
        'minor' => '#a8a29e',
    ];

    public const BUSINESS_AREA_LABELS = [
        'customers' => 'Your customers',
        'costs' => 'Running costs',
        'security' => 'Security & compliance',
        'speed' => 'Speed of change',
    ];

    public function scoreGauge(int $score): string
    {
        $score = max(0, min(100, $score));
        $color = self::BAND_COLORS[ScoreBand::fromScore($score)->value];

        // Semicircle centred on (100,100), radius 80, drawn left to right
        // over the top. sweep-flag 1 is clockwise on screen.
        $body = '<path d="M 20 100 A 80 80 0 0 1 180 100" fill="none" stroke="'.self::TRACK.'" stroke-width="16" stroke-linecap="round"/>';

        if ($score > 0) {
            $angle = M_PI * (1 - $score / 100);
            $x = round(100 + 80 * cos($angle), 2);
            $y = round(100 - 80 * sin($angle), 2);
            $body .= sprintf('<path d="M 20 100 A 80 80 0 0 1 %s %s" fill="none" stroke="%s" stroke-width="16" stroke-linecap="round"/>', $x, $y, $color);
        }

        $body .= sprintf('<text x="100" y="92" text-anchor="middle" font-size="40" font-weight="bold" fill="%s" %s>%d</text>', self::INK, self::FONT, $score);
        $body .= sprintf('<text x="100" y="114" text-anchor="middle" font-size="11" fill="%s" %s>%s</text>', self::MUTED, self::FONT, $this->e(__('out of 100')));

        return $this->svg(__('Overall health: :score out of 100', ['score' => $score]), 200, 120, $body);
    }

    public function percentileBar(int $percentile): string
    {
        $percentile = max(0, min(100, $percentile));
        $x = $percentile * 3;

        $body = sprintf('<rect x="0" y="10" width="300" height="8" rx="4" fill="%s"/>', self::TRACK);
        $body .= sprintf('<rect x="0" y="10" width="%d" height="8" rx="4" fill="%s"/>', $x, self::BAND_COLORS['healthy']);
        $body .= sprintf('<circle cx="%d" cy="14" r="7" fill="%s"/>', $x, self::INK);

        return $this->svg(__('Healthier than :p% of audited codebases', ['p' => $percentile]), 300, 28, $body);
    }

    /**
     * @param  array<string, int>  $scores  dimension => 0-100, without `overall`
     * @param  list<string>  $notMeasured
     */
    public function areaBars(array $scores, array $notMeasured): string
    {
        $rows = array_values(array_filter(
            self::DIMENSIONS,
            fn (string $d): bool => array_key_exists($d, $scores) || in_array($d, $notMeasured, true),
        ));

        if ($rows === []) {
            return $this->emptyState(__('Nothing to show'), 300);
        }

        $body = '';
        foreach ($rows as $i => $dimension) {
            $y = $i * 30;
            $body .= sprintf('<text x="0" y="%d" font-size="12" fill="%s" %s>%s</text>', $y + 15, self::INK, self::FONT, $this->e(__(self::AREA_LABELS[$dimension])));
            $body .= sprintf('<rect x="140" y="%d" width="160" height="10" rx="5" fill="%s"/>', $y + 6, self::TRACK);

            if (array_key_exists($dimension, $scores)) {
                $score = max(0, min(100, (int) $scores[$dimension]));
                $color = self::BAND_COLORS[ScoreBand::fromScore($score)->value];
                $body .= sprintf('<rect x="140" y="%d" width="%s" height="10" rx="5" fill="%s"/>', $y + 6, round($score * 1.6, 2), $color);
                $body .= sprintf('<text x="340" y="%d" text-anchor="end" font-size="12" font-weight="bold" fill="%s" %s>%d</text>', $y + 15, self::INK, self::FONT, $score);
            } else {
                $body .= sprintf('<text x="340" y="%d" text-anchor="end" font-size="10" fill="%s" %s>%s</text>', $y + 15, self::MUTED, self::FONT, $this->e(__('not checked')));
            }
        }

        return $this->svg(__('Health by area'), 340, count($rows) * 30, $body);
    }

    /** @param  array<string, int>  $countsBySeverity */
    public function severityStack(array $countsBySeverity): string
    {
        $segments = [];
        foreach (self::SEGMENTS as $segment => $severities) {
            $count = array_sum(array_map(fn (string $s): int => (int) ($countsBySeverity[$s] ?? 0), $severities));
            if ($count > 0) {
                $segments[$segment] = $count;
            }
        }

        $total = array_sum($segments);
        if ($total === 0) {
            return $this->emptyState(__('No issues found'), 300);
        }

        $body = '';
        $x = 0.0;
        foreach ($segments as $segment => $count) {
            $width = round(300 * $count / $total, 2);
            $body .= sprintf('<rect x="%s" y="0" width="%s" height="18" fill="%s"/>', $x, $width, self::SEGMENT_COLORS[$segment]);
            $x += $width;
        }

        $legendX = 0;
        foreach ($segments as $segment => $count) {
            $body .= sprintf('<rect x="%d" y="30" width="10" height="10" fill="%s"/>', $legendX, self::SEGMENT_COLORS[$segment]);
            $body .= sprintf('<text x="%d" y="39" font-size="11" fill="%s" %s>%s %d</text>', $legendX + 14, self::INK, self::FONT, $this->e(__(self::SEGMENT_LABELS[$segment])), $count);
            $legendX += 100;
        }

        return $this->svg(__(':n problem areas by seriousness', ['n' => $total]), max(300, $legendX), 46, $body);
    }

    /** @param  array<string, int>  $counts */
    public function businessAreaBars(array $counts): string
    {
        $max = max([0, ...array_values(array_map('intval', $counts))]);
        if ($max === 0) {
            return $this->emptyState(__('Nothing to show'), 300);
        }

        $body = '';
        $i = 0;
        foreach (self::BUSINESS_AREA_LABELS as $area => $label) {
            $count = (int) ($counts[$area] ?? 0);
            $y = $i * 30;
            $body .= sprintf('<text x="0" y="%d" font-size="12" fill="%s" %s>%s</text>', $y + 15, self::INK, self::FONT, $this->e(__($label)));
            $body .= sprintf('<rect x="150" y="%d" width="180" height="12" rx="6" fill="%s"/>', $y + 5, self::TRACK);
            if ($count > 0) {
                $body .= sprintf('<rect x="150" y="%d" width="%s" height="12" rx="6" fill="%s"/>', $y + 5, round(180 * $count / $max, 2), self::BAND_COLORS['healthy']);
            }
            $body .= sprintf('<text x="360" y="%d" text-anchor="end" font-size="12" font-weight="bold" fill="%s" %s>%d</text>', $y + 15, self::INK, self::FONT, $count);
            $i++;
        }

        return $this->svg(__('Findings by part of your business'), 360, $i * 30, $body);
    }

    private function emptyState(string $message, int $width): string
    {
        $body = sprintf('<text x="0" y="16" font-size="12" fill="%s" %s>%s</text>', self::MUTED, self::FONT, $this->e($message));

        return $this->svg($message, $width, 24, $body);
    }

    private function svg(string $title, int $width, int $height, string $body): string
    {
        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %2$d" width="%1$d" height="%2$d" role="img"><title>%3$s</title>%4$s</svg>',
            $width, $height, $this->e($title), $body,
        );
    }

    private function e(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
