<?php

namespace App\Services\AuditReport;

use App\Models\AuditFindingGroup;
use App\Models\AuditRequest;
use App\Support\RepoName;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Platform-wide audit statistics a partner can quote in their own marketing.
 *
 * Only aggregates ever leave this class: counts, percentages, and issue labels
 * taken from the fixed ISSUE_LABELS table. No repository URL, path, tenant,
 * email or finding example is part of the result, so nothing a partner sees
 * can be traced back to one customer. Below `audit.insights_min_codebases`
 * the whole result is withheld, because a percentage over a handful of
 * codebases describes those customers rather than the population.
 *
 * Percentages are over codebases, not runs: a repository audited weekly on a
 * schedule would otherwise count fifty times. Each codebase contributes its
 * latest completed audit.
 */
class AuditInsightsService
{
    public const CACHE_KEY = 'audit-insights:v1';

    /** Share of files that are tests below which coverage counts as thin. */
    public const THIN_TESTS_PCT = 10.0;

    /** Duplication at or above this share of code counts as high. */
    public const HIGH_DUPLICATION_PCT = 10.0;

    /**
     * Rule families (see the scanners and resources/semgrep) worth naming in a
     * "most common issues" list. Families missing here are left out rather
     * than shown under a machine name. duplication.clone is absent on
     * purpose: nearly every repository has *some* clone, so it is measured by
     * the duplication threshold instead.
     */
    private const ISSUE_LABELS = [
        'secrets' => 'Exposed API keys or secrets',
        'dependencies.vulnerable' => 'Dependencies with known vulnerabilities',
        'structure.committed-build-output' => 'Build output committed to the repository',
        'common.configuration' => 'Debug mode hard-coded on',
        'common.deserialization' => 'Unsafe deserialization of input',
        'common.upload' => 'File uploads without size or type checks',
        'js.transport-security' => 'TLS certificate verification disabled',
        'php.authorization' => 'Write actions without authorization checks',
        'php.injection' => 'SQL built by string interpolation',
        'no_tests' => 'No automated tests',
        'thin_tests' => 'Thin test coverage',
        'no_ci' => 'No CI pipeline',
        'no_error_monitoring' => 'No error monitoring',
        'high_duplication' => 'High code duplication',
    ];

    /**
     * @return array{
     *     audits_total: int,
     *     codebases: int,
     *     loc_analyzed: int,
     *     average_score: int|null,
     *     metrics: array<string, int|null>,
     *     top_issues: list<array{label: string, pct: int}>,
     *     generated_at: string,
     * }|null null while the sample is too small to publish
     */
    public function stats(): ?array
    {
        $stats = $this->cached();

        return $stats['codebases'] >= (int) config('audit.insights_min_codebases') ? $stats : null;
    }

    /** How many codebases are still needed before stats() publishes anything. */
    public function codebasesNeeded(): int
    {
        return max(0, (int) config('audit.insights_min_codebases') - $this->cached()['codebases']);
    }

    /**
     * @return array{audits_total: int, codebases: int, loc_analyzed: int, average_score: int|null, metrics: array<string, int|null>, top_issues: list<array{label: string, pct: int}>, generated_at: string}
     */
    private function cached(): array
    {
        return Cache::remember(self::CACHE_KEY, (int) config('audit.insights_cache_seconds'), fn (): array => $this->compute());
    }

    /**
     * @return array{audits_total: int, codebases: int, loc_analyzed: int, average_score: int|null, metrics: array<string, int|null>, top_issues: list<array{label: string, pct: int}>, generated_at: string}
     */
    private function compute(): array
    {
        $latest = [];
        $auditsTotal = 0;

        // An audit "was performed" once it produced a report; failed and
        // not-analyzable runs never get one.
        AuditRequest::query()
            ->whereNotNull('metrics')
            ->whereHas('report')
            ->select(['id', 'repo_url', 'metrics'])
            ->orderBy('id')
            ->chunk(500, function (Collection $requests) use (&$latest, &$auditsTotal): void {
                foreach ($requests as $request) {
                    $auditsTotal++;
                    $latest[self::codebaseKey((string) $request->repo_url)] = [$request->id, (array) $request->metrics];
                }
            });

        $groups = collect();
        foreach (array_chunk(array_column($latest, 0), 500) as $ids) {
            $groups = $groups->concat(
                AuditFindingGroup::query()
                    ->whereIn('audit_request_id', $ids)
                    ->get(['audit_request_id', 'rule_family', 'severity', 'count'])
            );
        }
        $groupsByRequest = $groups->groupBy('audit_request_id');

        $profiles = array_map(
            fn (array $entry): array => self::profile($entry[1], $groupsByRequest->get($entry[0], collect())),
            array_values($latest),
        );

        $scores = array_values(array_filter(array_column($profiles, 'score'), 'is_int'));

        return [
            'audits_total' => $auditsTotal,
            'codebases' => count($profiles),
            'loc_analyzed' => array_sum(array_column($profiles, 'loc')),
            'average_score' => $scores === [] ? null : (int) round(array_sum($scores) / count($scores)),
            'metrics' => [
                'secrets_pct' => self::share($profiles, 'secrets'),
                'no_tests_pct' => self::share($profiles, 'no_tests'),
                'thin_tests_pct' => self::share($profiles, 'thin_or_no_tests'),
                'vulnerable_dependencies_pct' => self::share($profiles, 'vulnerable_dependencies'),
                'no_ci_pct' => self::share($profiles, 'no_ci'),
                'no_error_monitoring_pct' => self::share($profiles, 'no_error_monitoring'),
                'high_duplication_pct' => self::share($profiles, 'high_duplication'),
                'low_score_pct' => self::share($profiles, 'low_score'),
            ],
            'top_issues' => self::topIssues($profiles),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * One codebase reduced to booleans. A null flag means "not measured" and
     * leaves that codebase out of the flag's denominator, so an audit whose
     * OSV lookup failed does not count as "no vulnerable dependencies".
     *
     * @param  array<string, mixed>  $metrics
     * @param  Collection<int, AuditFindingGroup>  $groups
     * @return array{loc: int, score: int|null, secrets: bool, no_tests: bool, thin_or_no_tests: bool, vulnerable_dependencies: bool|null, no_ci: bool, no_error_monitoring: bool|null, high_duplication: bool|null, low_score: bool|null, issues: list<string>}
     */
    private static function profile(array $metrics, Collection $groups): array
    {
        $facts = RepositoryFacts::from($metrics, $groups);
        $tooling = is_array($metrics['tooling'] ?? null) ? $metrics['tooling'] : [];
        $testFiles = isset($tooling['test_files']) ? (int) $tooling['test_files'] : null;
        $score = isset($metrics['computed_scores']['overall']) ? (int) $metrics['computed_scores']['overall'] : null;

        $noTests = $testFiles !== null ? $testFiles === 0 : $facts['test_ratio_pct'] <= 0.0;
        $thin = $noTests || $facts['test_ratio_pct'] < self::THIN_TESTS_PCT;

        $profile = [
            'loc' => $facts['loc_total'],
            'score' => $score,
            'secrets' => $facts['secrets_likely'] > 0,
            'no_tests' => $noTests,
            'thin_or_no_tests' => $thin,
            'vulnerable_dependencies' => $facts['vulnerable_dependencies'] === null ? null : $facts['vulnerable_dependencies'] > 0,
            'no_ci' => ! $facts['has_ci'],
            'no_error_monitoring' => array_key_exists('error_monitoring', $tooling) ? ! $tooling['error_monitoring'] : null,
            'high_duplication' => $facts['duplication_pct'] === null ? null : $facts['duplication_pct'] >= self::HIGH_DUPLICATION_PCT,
            'low_score' => $score === null ? null : $score < 50,
        ];

        $issues = $groups->pluck('rule_family')
            ->map(fn ($family): string => (string) $family)
            ->filter(fn (string $family): bool => array_key_exists($family, self::ISSUE_LABELS))
            ->all();

        foreach (['secrets', 'no_tests', 'no_ci', 'no_error_monitoring', 'high_duplication'] as $flag) {
            if ($profile[$flag] === true) {
                $issues[] = $flag;
            }
        }
        if ($profile['vulnerable_dependencies'] === true) {
            $issues[] = 'dependencies.vulnerable';
        }
        // Mutually exclusive with no_tests, so one codebase never counts twice
        // for the same gap in the ranked list.
        if ($thin && ! $noTests) {
            $issues[] = 'thin_tests';
        }

        return [...$profile, 'issues' => array_values(array_unique($issues))];
    }

    /**
     * Whole-number percentage of codebases where $flag is true, over those
     * where it was measured; null when none were.
     *
     * @param  list<array<string, mixed>>  $profiles
     */
    private static function share(array $profiles, string $flag): ?int
    {
        $measured = array_filter(array_column($profiles, $flag), fn ($value): bool => $value !== null);

        return $measured === [] ? null : (int) round(100 * count(array_filter($measured)) / count($measured));
    }

    /**
     * @param  list<array{issues: list<string>}>  $profiles
     * @return list<array{label: string, pct: int}>
     */
    private static function topIssues(array $profiles, int $limit = 8): array
    {
        $counts = array_count_values(array_merge(...array_column($profiles, 'issues')));
        arsort($counts);

        return collect($counts)
            ->take($limit)
            ->map(fn (int $count, string $key): array => [
                'label' => __(self::ISSUE_LABELS[$key]),
                'pct' => (int) round(100 * $count / count($profiles)),
            ])
            ->values()
            ->all();
    }

    /** Host plus owner/repo, case-folded: branches and `.git` suffixes are one codebase. */
    private static function codebaseKey(string $repoUrl): string
    {
        return strtolower(parse_url($repoUrl, PHP_URL_HOST).'/'.RepoName::short($repoUrl));
    }
}
