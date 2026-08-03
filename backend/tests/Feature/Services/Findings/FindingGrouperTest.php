<?php

namespace Tests\Feature\Services\Findings;

use App\Services\AuditReport\Findings\DedupedFinding;
use App\Services\AuditReport\Findings\Finding;
use App\Services\AuditReport\Findings\FindingGrouper;
use App\Services\AuditReport\Findings\Severity;
use Tests\Feature\FeatureTest;

class FindingGrouperTest extends FeatureTest
{
    private function deduped(
        string $family,
        string $path,
        Severity $severity,
        ?int $line = 1,
        string $dimension = 'security_hygiene',
    ): DedupedFinding {
        return new DedupedFinding(
            new Finding(
                tool: 'semgrep',
                ruleId: $family.'.rule',
                ruleFamily: $family,
                severity: $severity,
                path: $path,
                line: $line,
                message: 'Description of the rule.',
                dimension: $dimension,
            ),
            ['semgrep'],
        );
    }

    public function test_a_group_carries_the_dimension_of_its_findings(): void
    {
        $groups = app(FindingGrouper::class)->group([
            $this->deduped('common.configuration', 'config/app.php', Severity::MEDIUM, 1, 'structure'),
        ]);

        $this->assertSame('structure', $groups[0]->dimension);
    }

    public function test_groups_by_rule_family_and_directory(): void
    {
        $groups = app(FindingGrouper::class)->group([
            $this->deduped('php.injection', 'app/Http/UserController.php', Severity::HIGH),
            $this->deduped('php.injection', 'app/Http/OrderController.php', Severity::HIGH, 2),
            $this->deduped('php.injection', 'app/Models/User.php', Severity::HIGH, 3),
        ]);

        $this->assertCount(2, $groups);
        $this->assertSame('app/Http', $groups[0]->directory);
        $this->assertSame(2, $groups[0]->count);
    }

    public function test_score_is_the_sum_of_severity_weights(): void
    {
        // critical 100 + low 3 = 103
        $groups = app(FindingGrouper::class)->group([
            $this->deduped('secrets.credential', 'app/A.php', Severity::CRITICAL),
            $this->deduped('secrets.credential', 'app/B.php', Severity::LOW, 2),
        ]);

        $this->assertSame(103, $groups[0]->score);
    }

    public function test_one_critical_outranks_many_low_findings(): void
    {
        $findings = [$this->deduped('secrets.credential', 'app/A.php', Severity::CRITICAL)];

        // Twenty low findings: 20 × 3 = 60, below one critical's 100.
        for ($i = 0; $i < 20; $i++) {
            $findings[] = $this->deduped('style.formatting', 'src/B.php', Severity::LOW, $i);
        }

        $groups = app(FindingGrouper::class)->group($findings);

        $this->assertSame('secrets.credential', $groups[0]->ruleFamily);
    }

    public function test_group_severity_is_the_maximum_within_it(): void
    {
        $groups = app(FindingGrouper::class)->group([
            $this->deduped('php.injection', 'app/A.php', Severity::LOW),
            $this->deduped('php.injection', 'app/B.php', Severity::CRITICAL, 2),
        ]);

        $this->assertSame(Severity::CRITICAL, $groups[0]->severity);
    }

    public function test_examples_are_capped_and_carry_no_content(): void
    {
        config()->set('audit.findings.max_group_examples', 3);

        $findings = [];
        for ($i = 1; $i <= 10; $i++) {
            $findings[] = $this->deduped('php.injection', "app/Http/File{$i}.php", Severity::HIGH, $i);
        }

        $group = app(FindingGrouper::class)->group($findings)[0];

        $this->assertCount(3, $group->examples);
        $this->assertSame(['path', 'line'], array_keys($group->examples[0]));
    }

    public function test_group_count_is_not_capped_by_the_example_cap(): void
    {
        config()->set('audit.findings.max_group_examples', 3);

        $findings = [];
        for ($i = 1; $i <= 10; $i++) {
            $findings[] = $this->deduped('php.injection', "app/Http/File{$i}.php", Severity::HIGH, $i);
        }

        // The report must say "10 findings", not "3".
        $this->assertSame(10, app(FindingGrouper::class)->group($findings)[0]->count);
    }

    public function test_number_of_groups_is_capped(): void
    {
        config()->set('audit.findings.max_groups', 2);

        $findings = [];
        for ($i = 1; $i <= 10; $i++) {
            $findings[] = $this->deduped("family.number{$i}", "app/Dir{$i}/File.php", Severity::HIGH, $i);
        }

        $this->assertCount(2, app(FindingGrouper::class)->group($findings));
    }

    public function test_grouping_is_deterministic_under_shuffled_input(): void
    {
        $findings = [];
        for ($i = 1; $i <= 30; $i++) {
            $findings[] = $this->deduped(
                'family.'.($i % 4),
                'app/Dir'.($i % 5).'/File'.$i.'.php',
                [Severity::CRITICAL, Severity::HIGH, Severity::MEDIUM, Severity::LOW][$i % 4],
                $i,
            );
        }

        $grouper = app(FindingGrouper::class);
        $first = $grouper->group($findings);

        $shuffled = $findings;
        shuffle($shuffled);
        $second = $grouper->group($shuffled);

        $this->assertSame(
            json_encode(array_map(fn ($g) => (array) $g, $first)),
            json_encode(array_map(fn ($g) => (array) $g, $second)),
        );
    }

    public function test_handles_an_empty_finding_list(): void
    {
        $this->assertSame([], app(FindingGrouper::class)->group([]));
    }
}
