<?php

namespace App\Services\AuditReport\Findings;

enum Severity: string
{
    case CRITICAL = 'critical';
    case HIGH = 'high';
    case MEDIUM = 'medium';
    case LOW = 'low';
    case INFO = 'info';

    /** Ranking weight; group score is the sum of its findings' weights. */
    public function weight(): int
    {
        return (int) config("audit.findings.severity_weights.{$this->value}");
    }

    public function isAtLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    public static function max(self ...$severities): self
    {
        $max = self::INFO;

        foreach ($severities as $severity) {
            if ($severity->isAtLeast($max)) {
                $max = $severity;
            }
        }

        return $max;
    }

    /**
     * Ordering rank, independent of the configurable weights — comparison must
     * not change when an operator retunes the ranking weights.
     */
    private function rank(): int
    {
        return match ($this) {
            self::CRITICAL => 5,
            self::HIGH => 4,
            self::MEDIUM => 3,
            self::LOW => 2,
            self::INFO => 1,
        };
    }
}
