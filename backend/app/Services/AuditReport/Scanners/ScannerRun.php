<?php

namespace App\Services\AuditReport\Scanners;

/**
 * Provenance for one scanner within one run.
 *
 * `reason` is a bounded classification, never captured stdout or stderr —
 * Semgrep's stderr can echo matched source lines, and this value reaches both
 * the pipeline log and Bugsink (spec §5.4).
 */
final readonly class ScannerRun
{
    public function __construct(
        public string $name,
        public string $version,
        public int $wallMs,
        public int $findingCount,
        public ScannerOutcome $outcome,
        public ?string $reason = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
            'wall_ms' => $this->wallMs,
            'finding_count' => $this->findingCount,
            'outcome' => $this->outcome->value,
            'reason' => $this->reason,
        ];
    }
}
