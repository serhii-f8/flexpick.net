<?php

namespace App\Services\AuditReport;

final readonly class ScoreChartPoint
{
    public function __construct(
        public float $x,
        public float $y,
        public int $score,
        public ?int $delta,
        public string $colorClass,
        public string $tooltip,
    ) {}
}
