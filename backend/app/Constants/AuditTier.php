<?php

namespace App\Constants;

enum AuditTier: string
{
    case DIAGNOSTIC = 'diagnostic';
    case AUTOMATED = 'automated';
    case DEEP_AI = 'deep_ai';
    case EXPERT = 'expert';

    public function label(): string
    {
        return match ($this) {
            self::DIAGNOSTIC => __('Free Diagnostic'),
            self::AUTOMATED => __('Automated Health Report'),
            self::DEEP_AI => __('Deep AI Code Review'),
            self::EXPERT => __('Expert Audit'),
        };
    }

    /**
     * Filament badge colour for this tier, shared by every surface that lists
     * runs so the same tier never appears in two colours.
     *
     * `warning` is deliberately unused: the report list paints an
     * "In expert review" badge warning on the same row, and a tier sharing
     * that colour would read as one state rather than two.
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::DIAGNOSTIC => 'gray',
            self::AUTOMATED => 'info',
            self::DEEP_AI => 'primary',
            self::EXPERT => 'success',
        };
    }
}
