<?php

namespace App\Services\AuditReport\Tiers;

use App\Constants\AuditTier;
use App\Services\AuditReport\DeepReview\DeepReviewProfile;
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
            deepReview: $this->deepReviewProfile($config),
        );
    }

    /** @param array<string, mixed> $config */
    private function deepReviewProfile(array $config): ?DeepReviewProfile
    {
        $deep = $config['deep_review'] ?? null;

        if (! is_array($deep)) {
            return null;
        }

        return new DeepReviewProfile(
            minFiles: (int) $deep['min_files'],
            maxFiles: (int) $deep['max_files'],
            fileBytes: (int) $deep['file_bytes'],
            minFileBytes: (int) $deep['min_file_bytes'],
            inputTokenBudget: (int) $deep['input_token_budget'],
            maxTokens: (int) $deep['max_tokens'],
        );
    }
}
