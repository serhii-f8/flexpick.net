<?php

namespace App\Services\AuditReport\Findings;

/**
 * A problem family: one rule family within one directory.
 *
 * This is the unit the model narrates and the unit persisted to
 * audit_finding_groups. `examples` holds locations only — never content,
 * so a secret cannot reach storage or the prompt through a group.
 */
final readonly class FindingGroup
{
    /**
     * @param  list<array{path: string, line: int|null}>  $examples
     * @param  list<string>  $tools
     */
    public function __construct(
        public string $ruleFamily,
        public string $directory,
        public Severity $severity,
        public int $count,
        public int $score,
        public array $examples,
        public array $tools,
        /**
         * The score dimension this group feeds, taken from its findings.
         * A rule family always maps to one dimension — a family spanning two
         * is a ruleset defect, caught by the metadata test in Task 10.
         */
        public string $dimension,
    ) {}
}
