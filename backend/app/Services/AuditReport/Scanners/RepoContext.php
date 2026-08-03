<?php

namespace App\Services\AuditReport\Scanners;

use App\Services\AuditReport\Tiers\TierProfile;

/**
 * Per-run state shared across the scanner sequence.
 *
 * Mutable by design in two respects: scc runs first and fills `inventory`,
 * which every later scanner reads to cap its file set (spec §3.2); and any
 * scanner may `record()` a scalar measurement the pipeline needs afterwards.
 *
 * Both live HERE rather than on the scanner instances because Horizon workers
 * are long-lived: state on a scanner would persist across audit runs in one
 * process, so a scanner that failed on run B could be read with run A's value.
 * Scoping it to the context makes that impossible by construction rather than
 * guarded against.
 */
final class RepoContext
{
    /** @var array<string, float|int> */
    public private(set) array $measurements = [];

    public function __construct(
        public readonly string $path,
        public readonly TierProfile $tier,
        public ?SccInventory $inventory = null,
    ) {}

    public function withInventory(SccInventory $inventory): void
    {
        $this->inventory = $inventory;
    }

    public function record(string $key, float|int $value): void
    {
        $this->measurements[$key] = $value;
    }

    public function measurement(string $key, float|int $default = 0): float|int
    {
        return $this->measurements[$key] ?? $default;
    }
}
