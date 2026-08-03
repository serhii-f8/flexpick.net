<?php

namespace App\Services\AuditReport\Tiers;

use App\Constants\AuditTier;

final readonly class TierProfile
{
    /**
     * @param  list<string>  $scanners  scanner names in committed execution order
     */
    public function __construct(
        public AuditTier $tier,
        public array $scanners,
        public int $excerptFiles,
        public int $excerptBytes,
        public int $aiMaxTokens,
        public int $narratedGroups,
    ) {}

    public function runsScanner(string $name): bool
    {
        return in_array($name, $this->scanners, true);
    }
}
