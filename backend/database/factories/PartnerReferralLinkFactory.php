<?php

namespace Database\Factories;

use App\Models\PartnerReferralLink;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PartnerReferralLink>
 */
class PartnerReferralLinkFactory extends Factory
{
    protected $model = PartnerReferralLink::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'code' => strtoupper(fake()->bothify('PTR-########')),
            'is_active' => true,
        ];
    }
}
