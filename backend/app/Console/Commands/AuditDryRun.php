<?php

namespace App\Console\Commands;

use App\Constants\AuditTier;
use App\Services\AuditReport\AuditMeasurer;
use App\Services\AuditReport\NotMeasuredReason;
use App\Services\AuditReport\RepositoryFacts;
use App\Services\AuditReport\Scanners\RepoContext;
use App\Services\AuditReport\Tiers\TierProfileResolver;
use Illuminate\Console\Command;

/**
 * Runs the deterministic half of an audit — scanners, collectors, scores —
 * on a local directory. No clone, no database writes, no AI call, no charge.
 * For checking a scanner change against a real repository before it ships.
 */
class AuditDryRun extends Command
{
    protected $signature = 'app:audit-dry-run {path : Local repository directory} {--tier=diagnostic} {--json : Print the full measurement as JSON}';

    protected $description = 'Measure a local repository with the audit scanners, without AI, billing or delivery';

    public function handle(AuditMeasurer $measurer, TierProfileResolver $tiers): int
    {
        $path = realpath((string) $this->argument('path'));

        if ($path === false || ! is_dir($path)) {
            $this->error('Not a directory: '.$this->argument('path'));

            return self::FAILURE;
        }

        $profile = $tiers->for(AuditTier::from((string) $this->option('tier')));
        $measurement = $measurer->measure(new RepoContext($path, $profile), $profile->scanners);
        $facts = RepositoryFacts::from($measurement->metrics, $measurement->groups);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'facts' => $facts,
                'scores' => $measurement->scores->scores,
                'not_measured' => $measurement->scores->notMeasuredReasons,
                'groups' => array_map(fn ($g) => [
                    'family' => $g->ruleFamily, 'directory' => $g->directory, 'severity' => $g->severity->value,
                    'count' => $g->count, 'examples' => $g->examples,
                ], $measurement->groups),
                'runs' => $measurement->suite->runsAsArray(),
                'metrics' => $measurement->metrics,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(['Fact', 'Value'], [
            ['Source files', number_format($facts['files_total'])],
            ['Lines of code', number_format($facts['loc_total'])],
            ['Excluded files', number_format($facts['excluded_files'])],
            ['Duplicated lines', $facts['duplication_pct'] === null ? '—' : $facts['duplication_pct'].'%'],
            ['Test file ratio', $facts['test_ratio_pct'].'%'],
            ['CI configured', ($facts['has_ci'] ? 'yes' : 'no').($facts['ci_systems'] !== [] ? ' ('.implode(', ', $facts['ci_systems']).')' : '')],
            ['Potential secrets', $facts['secrets_likely'].' (+'.$facts['secrets_fixtures'].' likely fixtures)'],
            ['Vulnerable dependencies', $facts['vulnerable_dependencies'] ?? 'not checked'],
        ]);

        $this->table(['Dimension', 'Score'], array_map(
            fn ($dimension, $score) => [$dimension, $score],
            array_keys($measurement->scores->scores),
            $measurement->scores->scores,
        ));

        foreach ($measurement->scores->notMeasuredReasons as $dimension => $reason) {
            $this->warn("{$dimension}: not measured — ".NotMeasuredReason::describe($reason)." ({$reason})");
        }

        $this->table(['Family', 'Directory', 'Severity', 'Count'], array_map(
            fn ($g) => [$g->ruleFamily, $g->directory, $g->severity->value, $g->count],
            $measurement->groups,
        ));

        return self::SUCCESS;
    }
}
