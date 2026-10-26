<?php

namespace App\Support;

/**
 * How a report package's billing period, in months, is named. One table, so
 * the seeded plan slug, the plan's feature text and the marketing site all
 * say the same thing.
 */
final class PackagePeriod
{
    /** @param  array{interval_count?: int}  $package */
    public static function months(array $package): int
    {
        return max(1, (int) ($package['interval_count'] ?? 1));
    }

    /** Plan slug suffix: "monthly", "quarterly", "every-2-months". */
    public static function slug(int $months): string
    {
        return match ($months) {
            1 => 'monthly',
            3 => 'quarterly',
            default => "every-{$months}-months",
        };
    }

    /** Plan name suffix: "Monthly", "Quarterly", "Every 2 Months". */
    public static function name(int $months): string
    {
        return match ($months) {
            1 => 'Monthly',
            3 => 'Quarterly',
            default => "Every {$months} Months",
        };
    }

    /** Allowance phrase: "per month", "per quarter", "every 2 months". */
    public static function phrase(int $months): string
    {
        return match ($months) {
            1 => 'per month',
            3 => 'per quarter',
            default => "every {$months} months",
        };
    }
}
