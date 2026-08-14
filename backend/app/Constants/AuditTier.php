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
}
