<?php

namespace App\Services\AuditReport\Findings;

/**
 * One normalized finding from one scanner.
 *
 * There is deliberately no field that could hold matched source content:
 * `message` carries the RULE's own description. This is what makes F5.2.6
 * (counts and paths only, never a secret value) structural rather than a
 * matter of remembering a redaction flag at each call site.
 */
final readonly class Finding
{
    /** The five score dimensions a finding may feed. */
    public const DIMENSIONS = ['structure', 'duplication', 'testing', 'dependencies', 'security_hygiene'];

    public function __construct(
        public string $tool,
        public string $ruleId,
        public string $ruleFamily,
        public Severity $severity,
        public string $path,
        public ?int $line,
        public string $message,
        /**
         * Which score dimension this finding feeds. Set by the normalizer:
         * fixed per tool for Gitleaks, OSV, and jscpd; read from the rule's
         * metadata.dimension for Semgrep. Carrying it here is what keeps
         * scoring from needing a second rule-to-dimension mapping (spec §7.1).
         */
        public string $dimension,
    ) {}

    /** Tool-agnostic, so two tools reporting one defect merge in deduplication. */
    public function fingerprint(): string
    {
        return sha1($this->ruleFamily.'|'.$this->path.'|'.($this->line ?? ''));
    }

    /** First $depth path segments; root-level files group under '.'. */
    public function directory(int $depth): string
    {
        $dir = trim(str_replace('\\', '/', dirname($this->path)), '/');

        if ($dir === '' || $dir === '.') {
            return '.';
        }

        return implode('/', array_slice(explode('/', $dir), 0, $depth));
    }
}
