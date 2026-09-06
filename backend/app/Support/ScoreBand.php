<?php

namespace App\Support;

/**
 * The single place that turns a 0–100 audit score into a band. Every surface
 * that colours a score (dashboard widgets, the audit view, the repository
 * cards, the public report) reads from here so a 62 is never "amber" in one
 * place and "green" in another.
 *
 * The thresholds match the landing page's report animation: coral below 50,
 * amber up to 69, gold from 70.
 */
enum ScoreBand: string
{
    case CRITICAL = 'critical';
    case WATCH = 'watch';
    case HEALTHY = 'healthy';

    public static function fromScore(int $score): self
    {
        return match (true) {
            $score >= 70 => self::HEALTHY,
            $score >= 50 => self::WATCH,
            default => self::CRITICAL,
        };
    }

    public function caption(): string
    {
        return match ($this) {
            self::CRITICAL => __('critical'),
            self::WATCH => __('needs work'),
            self::HEALTHY => __('healthy'),
        };
    }

    public function cssClass(): string
    {
        return 'fp-band-'.$this->value;
    }

    public function filamentColor(): string
    {
        return match ($this) {
            self::CRITICAL => 'danger',
            self::WATCH => 'warning',
            self::HEALTHY => 'primary',
        };
    }
}
