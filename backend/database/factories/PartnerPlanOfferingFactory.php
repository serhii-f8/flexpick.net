<?php

namespace Database\Factories;

use App\Models\PartnerPlanOffering;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PartnerPlanOffering>
 */
class PartnerPlanOfferingFactory extends Factory
{
    protected $model = PartnerPlanOffering::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'plan_id' => Plan::factory(),
            'price' => 1000,
            'quota_overrides' => [],
            'is_enabled' => false,
        ];
    }
}
