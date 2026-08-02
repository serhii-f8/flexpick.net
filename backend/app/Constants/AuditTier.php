<?php

namespace App\Constants;

enum AuditTier: string
{
    case DIAGNOSTIC = 'diagnostic';
    case AUTOMATED = 'automated';
    case DEEP_AI = 'deep_ai';
    case EXPERT = 'expert';
}
