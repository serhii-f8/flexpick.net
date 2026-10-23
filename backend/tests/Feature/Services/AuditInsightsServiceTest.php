<?php

namespace Tests\Feature\Services;

use App\Constants\AuditRequestStatus;
use App\Models\AuditFindingGroup;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Services\AuditReport\AuditInsightsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTest;

class AuditInsightsServiceTest extends FeatureTest
{
    protected function setUp(): void
    {
        parent::setUp();

        // The suite shares one database and the stats are platform-wide, so
        // every other test's audits would count. Start from none, and put
        // them all back afterwards.
        DB::beginTransaction();
        AuditRequest::query()->delete();
        Cache::forget(AuditInsightsService::CACHE_KEY);
        config()->set('audit.insights_min_codebases', 2);
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        Cache::forget(AuditInsightsService::CACHE_KEY);

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $tooling
     * @param  list<array{0: string, 1: string}>  $groups  [rule_family, severity]
     */
    public static function completedAudit(string $repoUrl, array $tooling = [], array $groups = [], ?int $vulnerable = 0, int $score = 60): AuditRequest
    {
        $request = AuditRequest::factory()->create([
            'repo_url' => $repoUrl,
            'email' => 'client-'.md5($repoUrl).'@example.com',
            'status' => AuditRequestStatus::SENT->value,
            'metrics' => [
                'loc_total' => 1000,
                'duplication_pct' => 4.0,
                'computed_scores' => ['overall' => $score],
                'tooling' => [
                    'has_ci' => true,
                    'test_files' => 10,
                    'test_ratio_pct' => 25.0,
                    'error_monitoring' => true,
                    ...$tooling,
                ],
                'dependency_audit' => $vulnerable === null
                    ? ['error' => 'osv unavailable']
                    : ['packages_scanned' => 100, 'vulnerable_count' => $vulnerable],
            ],
        ]);

        AuditReport::factory()->create(['audit_request_id' => $request->id]);

        foreach ($groups as [$family, $severity]) {
            AuditFindingGroup::factory()->create([
                'audit_request_id' => $request->id,
                'rule_family' => $family,
                'severity' => $severity,
                'directory' => 'secret-client-dir',
                'examples' => [['path' => 'secret-client-dir/config.php', 'line' => 3]],
            ]);
        }

        return $request;
    }

    public function test_stats_are_withheld_until_enough_codebases_were_audited(): void
    {
        self::completedAudit('https://github.com/acme/one');

        $service = app(AuditInsightsService::class);

        $this->assertNull($service->stats());
        $this->assertSame(1, $service->codebasesNeeded());
    }

    public function test_percentages_are_per_codebase_from_its_latest_audit(): void
    {
        // Leaked a key once, then fixed it: the latest audit is what counts.
        self::completedAudit('https://github.com/acme/one', groups: [['secrets.credential', 'critical']]);
        self::completedAudit('https://github.com/Acme/one.git');
        self::completedAudit('https://github.com/acme/two', ['test_files' => 0, 'test_ratio_pct' => 0.0, 'has_ci' => false], [['secrets.credential', 'critical']], vulnerable: 3, score: 40);
        self::completedAudit('https://gitlab.com/acme/three', ['test_files' => 2, 'test_ratio_pct' => 5.0], [['php.injection', 'high']]);
        self::completedAudit('https://github.com/acme/four', groups: [['secrets.likely-fixture', 'low']]);

        $stats = app(AuditInsightsService::class)->stats();

        $this->assertSame(5, $stats['audits_total']);
        $this->assertSame(4, $stats['codebases']);
        $this->assertSame(4000, $stats['loc_analyzed']);
        $this->assertSame(55, $stats['average_score']);
        $this->assertSame(25, $stats['metrics']['secrets_pct'], 'fixtures and fixed leaks do not count');
        $this->assertSame(25, $stats['metrics']['no_tests_pct']);
        $this->assertSame(50, $stats['metrics']['thin_tests_pct']);
        $this->assertSame(25, $stats['metrics']['vulnerable_dependencies_pct']);
        $this->assertSame(25, $stats['metrics']['no_ci_pct']);
        $this->assertSame(25, $stats['metrics']['low_score_pct']);

        $issues = array_column($stats['top_issues'], 'pct', 'label');
        $this->assertSame(25, $issues['Exposed API keys or secrets']);
        $this->assertSame(25, $issues['SQL built by string interpolation']);
        $this->assertSame(25, $issues['No automated tests']);
        $this->assertSame(25, $issues['Thin test coverage'], 'a codebase with no tests is not also counted as thin');
    }

    public function test_audits_without_a_report_do_not_count(): void
    {
        self::completedAudit('https://github.com/acme/one');
        self::completedAudit('https://github.com/acme/two');
        AuditRequest::factory()->create([
            'repo_url' => 'https://github.com/acme/failed',
            'status' => AuditRequestStatus::NOT_ANALYZABLE->value,
            'metrics' => ['loc_total' => 5],
        ]);

        $stats = app(AuditInsightsService::class)->stats();

        $this->assertSame(2, $stats['audits_total']);
        $this->assertSame(2, $stats['codebases']);
    }

    public function test_an_unmeasured_check_leaves_the_codebase_out_of_that_percentage_only(): void
    {
        self::completedAudit('https://github.com/acme/one', vulnerable: 2);
        self::completedAudit('https://github.com/acme/two', vulnerable: null);

        $stats = app(AuditInsightsService::class)->stats();

        $this->assertSame(100, $stats['metrics']['vulnerable_dependencies_pct']);
        $this->assertSame(0, $stats['metrics']['secrets_pct']);
    }

    public function test_the_result_carries_no_client_or_repository_detail(): void
    {
        $one = self::completedAudit('https://github.com/acme-private/one', groups: [['secrets.credential', 'critical']]);
        $two = self::completedAudit('https://bitbucket.org/acme-private/two', groups: [['php.authorization', 'high']]);

        $json = json_encode(app(AuditInsightsService::class)->stats());

        foreach (['acme-private', 'github.com', 'bitbucket.org', $one->email, $two->email, 'secret-client-dir', $one->uuid] as $detail) {
            $this->assertStringNotContainsString($detail, $json);
        }
    }
}
