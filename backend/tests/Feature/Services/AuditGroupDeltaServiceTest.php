<?php

namespace Tests\Feature\Services;

use App\Models\AuditFindingGroup;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Services\AuditReport\AuditGroupDeltaService;
use Tests\Feature\FeatureTest;

class AuditGroupDeltaServiceTest extends FeatureTest
{
    /**
     * @param  list<array<string, mixed>>  $groups
     */
    private function reportWithGroups(
        string $email,
        string $repoUrl,
        ?string $branch,
        array $groups,
        int $scoringVersion = 1,
    ): AuditReport {
        $request = AuditRequest::factory()->verified()->create([
            'email' => $email,
            'repo_url' => $repoUrl,
            'branch' => $branch,
        ]);

        $report = AuditReport::factory()->locked()->create([
            'audit_request_id' => $request->id,
            'scoring_version' => $scoringVersion,
        ]);

        foreach ($groups as $group) {
            AuditFindingGroup::factory()->create(array_merge(['audit_request_id' => $request->id], $group));
        }

        return $report;
    }

    public function test_a_group_absent_from_the_previous_run_is_reported_as_new(): void
    {
        $this->reportWithGroups('gd1@example.com', 'https://github.com/acme/app', null, []);
        $current = $this->reportWithGroups('gd1@example.com', 'https://github.com/acme/app', null, [
            ['rule_family' => 'php.injection', 'directory' => 'app/Http', 'dimension' => 'security_hygiene', 'count' => 4],
        ]);

        $result = app(AuditGroupDeltaService::class)->deltasFor($current);

        $this->assertNotNull($result);
        $group = $result['groups']['php.injection|app/Http|security_hygiene'];
        $this->assertSame('new', $group['status']);
        $this->assertSame(4, $group['count']);
        $this->assertNull($group['previous_count']);
        $this->assertSame(1, $result['summary']['new_groups']);
        $this->assertSame(4, $result['summary']['new_findings']);
    }

    public function test_a_group_absent_from_the_current_run_is_reported_as_fixed(): void
    {
        $this->reportWithGroups('gd2@example.com', 'https://github.com/acme/app', null, [
            ['rule_family' => 'php.injection', 'directory' => 'app/Http', 'dimension' => 'security_hygiene', 'count' => 4],
        ]);
        $current = $this->reportWithGroups('gd2@example.com', 'https://github.com/acme/app', null, []);

        $result = app(AuditGroupDeltaService::class)->deltasFor($current);

        $group = $result['groups']['php.injection|app/Http|security_hygiene'];
        $this->assertSame('fixed', $group['status']);
        $this->assertNull($group['count']);
        $this->assertSame(4, $group['previous_count']);
        $this->assertSame(1, $result['summary']['fixed_groups']);
        $this->assertSame(4, $result['summary']['resolved_findings']);
    }

    public function test_a_persisting_group_with_more_findings_reports_a_positive_delta(): void
    {
        $this->reportWithGroups('gd3@example.com', 'https://github.com/acme/app', null, [
            ['rule_family' => 'jscpd.duplication', 'directory' => 'src', 'dimension' => 'duplication', 'count' => 5],
        ]);
        $current = $this->reportWithGroups('gd3@example.com', 'https://github.com/acme/app', null, [
            ['rule_family' => 'jscpd.duplication', 'directory' => 'src', 'dimension' => 'duplication', 'count' => 8],
        ]);

        $result = app(AuditGroupDeltaService::class)->deltasFor($current);

        $group = $result['groups']['jscpd.duplication|src|duplication'];
        $this->assertSame('persisting', $group['status']);
        $this->assertSame(3, $group['count_delta']);
        $this->assertSame(3, $result['summary']['new_findings']);
        $this->assertSame(0, $result['summary']['resolved_findings']);
    }

    public function test_a_persisting_group_with_fewer_findings_reports_a_negative_delta(): void
    {
        $this->reportWithGroups('gd4@example.com', 'https://github.com/acme/app', null, [
            ['rule_family' => 'jscpd.duplication', 'directory' => 'src', 'dimension' => 'duplication', 'count' => 8],
        ]);
        $current = $this->reportWithGroups('gd4@example.com', 'https://github.com/acme/app', null, [
            ['rule_family' => 'jscpd.duplication', 'directory' => 'src', 'dimension' => 'duplication', 'count' => 3],
        ]);

        $result = app(AuditGroupDeltaService::class)->deltasFor($current);

        $group = $result['groups']['jscpd.duplication|src|duplication'];
        $this->assertSame('persisting', $group['status']);
        $this->assertSame(-5, $group['count_delta']);
        $this->assertSame(5, $result['summary']['resolved_findings']);
        $this->assertSame(0, $result['summary']['new_findings']);
    }

    public function test_an_unchanged_group_has_a_zero_delta_and_does_not_count_toward_the_summary(): void
    {
        $this->reportWithGroups('gd5@example.com', 'https://github.com/acme/app', null, [
            ['rule_family' => 'php.injection', 'directory' => 'app/Http', 'dimension' => 'security_hygiene', 'count' => 2],
        ]);
        $current = $this->reportWithGroups('gd5@example.com', 'https://github.com/acme/app', null, [
            ['rule_family' => 'php.injection', 'directory' => 'app/Http', 'dimension' => 'security_hygiene', 'count' => 2],
        ]);

        $result = app(AuditGroupDeltaService::class)->deltasFor($current);

        $this->assertSame(0, $result['groups']['php.injection|app/Http|security_hygiene']['count_delta']);
        $this->assertSame(0, $result['summary']['new_findings']);
        $this->assertSame(0, $result['summary']['resolved_findings']);
    }

    public function test_first_run_for_a_repo_and_branch_has_no_deltas(): void
    {
        $current = $this->reportWithGroups('gd6@example.com', 'https://github.com/acme/app', 'main', [
            ['rule_family' => 'php.injection', 'directory' => 'app/Http', 'dimension' => 'security_hygiene', 'count' => 2],
        ]);

        $this->assertNull(app(AuditGroupDeltaService::class)->deltasFor($current));
    }

    public function test_a_previous_run_on_a_different_branch_is_not_compared(): void
    {
        $this->reportWithGroups('gd7@example.com', 'https://github.com/acme/app', 'main', [
            ['rule_family' => 'php.injection', 'directory' => 'app/Http', 'dimension' => 'security_hygiene', 'count' => 2],
        ]);
        $current = $this->reportWithGroups('gd7@example.com', 'https://github.com/acme/app', 'feature/x', [
            ['rule_family' => 'php.injection', 'directory' => 'app/Http', 'dimension' => 'security_hygiene', 'count' => 9],
        ]);

        $this->assertNull(app(AuditGroupDeltaService::class)->deltasFor($current));
    }

    public function test_default_branch_runs_compare_against_each_other(): void
    {
        // Both requests leave branch null (the default-branch convention) --
        // this must behave as "same branch," not as two mismatched nulls.
        $this->reportWithGroups('gd8@example.com', 'https://github.com/acme/app', null, [
            ['rule_family' => 'php.injection', 'directory' => 'app/Http', 'dimension' => 'security_hygiene', 'count' => 2],
        ]);
        $current = $this->reportWithGroups('gd8@example.com', 'https://github.com/acme/app', null, [
            ['rule_family' => 'php.injection', 'directory' => 'app/Http', 'dimension' => 'security_hygiene', 'count' => 5],
        ]);

        $result = app(AuditGroupDeltaService::class)->deltasFor($current);

        $this->assertNotNull($result);
        $this->assertSame(3, $result['groups']['php.injection|app/Http|security_hygiene']['count_delta']);
    }

    public function test_does_not_compare_across_scoring_versions(): void
    {
        $this->reportWithGroups('gd9@example.com', 'https://github.com/acme/app', null, [
            ['rule_family' => 'php.injection', 'directory' => 'app/Http', 'dimension' => 'security_hygiene', 'count' => 2],
        ], scoringVersion: 1);
        $current = $this->reportWithGroups('gd9@example.com', 'https://github.com/acme/app', null, [
            ['rule_family' => 'php.injection', 'directory' => 'app/Http', 'dimension' => 'security_hygiene', 'count' => 9],
        ], scoringVersion: 2);

        $this->assertNull(app(AuditGroupDeltaService::class)->deltasFor($current));
    }

    public function test_summary_totals_aggregate_across_a_mixed_set_of_groups(): void
    {
        $this->reportWithGroups('gd10@example.com', 'https://github.com/acme/app', null, [
            ['rule_family' => 'secrets.credential', 'directory' => 'config', 'dimension' => 'security_hygiene', 'count' => 1], // will be fixed
            ['rule_family' => 'jscpd.duplication', 'directory' => 'src', 'dimension' => 'duplication', 'count' => 10], // will drop to 4 (6 resolved)
        ]);
        $current = $this->reportWithGroups('gd10@example.com', 'https://github.com/acme/app', null, [
            ['rule_family' => 'jscpd.duplication', 'directory' => 'src', 'dimension' => 'duplication', 'count' => 4],
            ['rule_family' => 'style.formatting', 'directory' => 'app', 'dimension' => 'structure', 'count' => 3], // new
        ]);

        $result = app(AuditGroupDeltaService::class)->deltasFor($current);

        $this->assertSame(1, $result['summary']['fixed_groups']);
        $this->assertSame(1, $result['summary']['new_groups']);
        $this->assertSame(1 + 6, $result['summary']['resolved_findings']); // fixed group's 1, plus 10->4
        $this->assertSame(3, $result['summary']['new_findings']); // the new group's 3
    }
}
