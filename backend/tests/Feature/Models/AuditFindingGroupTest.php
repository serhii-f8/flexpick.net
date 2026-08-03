<?php

namespace Tests\Feature\Models;

use App\Models\AuditFindingGroup;
use App\Models\AuditRequest;
use App\Services\AuditReport\Findings\FindingGroup;
use App\Services\AuditReport\Findings\Severity;
use Tests\Feature\FeatureTest;

class AuditFindingGroupTest extends FeatureTest
{
    private function group(): FindingGroup
    {
        return new FindingGroup(
            ruleFamily: 'php.injection',
            directory: 'app/Http',
            severity: Severity::HIGH,
            count: 37,
            score: 1480,
            examples: [['path' => 'app/Http/UserController.php', 'line' => 42]],
            tools: ['semgrep'],
            dimension: 'security_hygiene',
        );
    }

    public function test_persists_a_group_from_its_value_object(): void
    {
        $request = AuditRequest::factory()->create();

        AuditFindingGroup::create(AuditFindingGroup::fromValueObject($request, $this->group()));

        $this->assertDatabaseHas('audit_finding_groups', [
            'audit_request_id' => $request->id,
            'rule_family' => 'php.injection',
            'directory' => 'app/Http',
            'severity' => 'high',
            'count' => 37,
            'score' => 1480,
        ]);
    }

    public function test_casts_examples_and_tools_to_arrays(): void
    {
        $request = AuditRequest::factory()->create();
        $stored = AuditFindingGroup::create(AuditFindingGroup::fromValueObject($request, $this->group()));

        $fresh = $stored->fresh();

        // assertEquals, not assertSame: MySQL's JSON column normalizes object
        // key order alphabetically, so 'line' sorts before 'path' on read back.
        $this->assertEquals([['path' => 'app/Http/UserController.php', 'line' => 42]], $fresh->examples);
        $this->assertSame(['semgrep'], $fresh->tools);
    }

    public function test_groups_are_reachable_from_the_request(): void
    {
        $request = AuditRequest::factory()->create();
        AuditFindingGroup::create(AuditFindingGroup::fromValueObject($request, $this->group()));

        $this->assertCount(1, $request->fresh()->findingGroups);
    }

    public function test_groups_are_deleted_with_their_request(): void
    {
        $request = AuditRequest::factory()->create();
        AuditFindingGroup::create(AuditFindingGroup::fromValueObject($request, $this->group()));

        $request->delete();

        // Scoped, not assertDatabaseCount: this suite has no per-test DB reset
        // (FeatureTest::migrate:fresh runs once), so other tests' rows persist.
        $this->assertSame(0, AuditFindingGroup::where('audit_request_id', $request->id)->count());
    }
}
