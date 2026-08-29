<?php

namespace Tests\Unit\Models;

use App\Models\AuditRequest;
use Tests\Feature\FeatureTest;

class AuditRequestBranchTest extends FeatureTest
{
    public function test_branch_is_mass_assignable_and_nullable(): void
    {
        $request = AuditRequest::factory()->create(['branch' => 'release/2.0']);
        $this->assertSame('release/2.0', $request->refresh()->branch);

        $default = AuditRequest::factory()->create();
        $this->assertNull($default->refresh()->branch);
    }
}
