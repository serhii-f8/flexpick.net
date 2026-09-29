<?php

namespace App\Services\AuditReport;

/**
 * The repository size AuditRunSizer bills on, with where it came from.
 *
 * Only scc's code count (generated and minified files excluded) is a basis
 * for charging. A walked file inventory counts every newline, so a broken or
 * missing scc must not decide what a customer pays: that case is carried as
 * "unavailable" instead of as a number the sizer could mistake for a count.
 */
final readonly class AuditSize
{
    private function __construct(
        public ?int $codeLoc,
        public ?string $unavailableReason,
    ) {}

    public static function measured(int $codeLoc): self
    {
        return new self($codeLoc, null);
    }

    public static function unavailable(string $reason): self
    {
        return new self(null, $reason);
    }

    public function isMeasured(): bool
    {
        return $this->codeLoc !== null;
    }
}
