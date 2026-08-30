<?php

namespace Database\Factories;

use App\Models\OneTimeProduct;
use App\Models\PartnerProductOffering;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PartnerProductOffering>
 */
class PartnerProductOfferingFactory extends Factory
{
    protected $model = PartnerProductOffering::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'one_time_product_id' => OneTimeProduct::factory(),
            'price' => 1000,
            'quota_overrides' => [],
            'is_enabled' => false,
        ];
    }
}
