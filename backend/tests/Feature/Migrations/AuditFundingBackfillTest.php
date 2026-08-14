<?php

namespace Tests\Feature\Migrations;

use App\Constants\AuditFunding;
use App\Models\AuditRequest;
use Tests\Feature\FeatureTest;

class AuditFundingBackfillTest extends FeatureTest
{
    public function test_funding_defaults_to_allowance(): void
    {
        $request = AuditRequest::factory()->create();

        $this->assertSame(AuditFunding::ALLOWANCE, $request->fresh()->funding);
    }

    public function test_funding_is_mass_assignable_and_cast(): void
    {
        $request = AuditRequest::factory()->create(['funding' => AuditFunding::PURCHASE->value]);

        $this->assertSame(AuditFunding::PURCHASE, $request->fresh()->funding);
    }
}
