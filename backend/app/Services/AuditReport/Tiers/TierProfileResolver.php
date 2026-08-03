<?php

namespace App\Services\AuditReport\Tiers;

use App\Constants\AuditTier;
use InvalidArgumentException;

class TierProfileResolver
{
    public function for(AuditTier $tier): TierProfile
    {
        $config = config("audit.tiers.{$tier->value}");

        if (! is_array($config)) {
            throw new InvalidArgumentException("No audit tier configuration for [{$tier->value}].");
        }

        return new TierProfile(
            tier: $tier,
            scanners: array_values($config['scanners']),
            excerptFiles: (int) $config['excerpt_files'],
            excerptBytes: (int) $config['excerpt_bytes'],
            aiMaxTokens: (int) $config['ai_max_tokens'],
            narratedGroups: (int) $config['narrated_groups'],
        );
    }
}
