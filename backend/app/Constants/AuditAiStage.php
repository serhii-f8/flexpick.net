<?php

namespace App\Constants;

enum AuditAiStage: string
{
    case ANALYSIS = 'analysis';
    case DEEP_REVIEW = 'deep_review';

    public function label(): string
    {
        return match ($this) {
            self::ANALYSIS => __('Tier-1 analysis'),
            self::DEEP_REVIEW => __('Deep review'),
        };
    }
}
