<?php

namespace Tests\Feature\Models;

use App\Models\PartnerPlanOffering;
use App\Models\Plan;
use Illuminate\Database\QueryException;
use Tests\Feature\FeatureTest;

class PartnerPlanOfferingTest extends FeatureTest
{
    public function test_it_belongs_to_a_tenant_and_a_plan(): void
    {
        $tenant = $this->createTenant();
        $plan = Plan::factory()->create();
        $offering = PartnerPlanOffering::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'price' => 5000,
            'quota_overrides' => ['audit_diagnostic_credits' => 20],
            'is_enabled' => true,
        ]);

        $this->assertTrue($offering->tenant->is($tenant));
        $this->assertTrue($offering->plan->is($plan));
        $this->assertSame(['audit_diagnostic_credits' => 20], $offering->fresh()->quota_overrides);
        $this->assertTrue($offering->is_enabled);
    }

    public function test_tenant_and_plan_combination_must_be_unique(): void
    {
        $tenant = $this->createTenant();
        $plan = Plan::factory()->create();
        PartnerPlanOffering::factory()->create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id]);

        $this->expectException(QueryException::class);
        PartnerPlanOffering::factory()->create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id]);
    }
}
