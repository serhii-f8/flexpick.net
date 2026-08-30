<?php

namespace Database\Factories;

use App\Models\Currency;
use App\Models\OneTimeProduct;
use App\Models\OneTimeProductPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OneTimeProductPrice>
 */
class OneTimeProductPriceFactory extends Factory
{
    protected $model = OneTimeProductPrice::class;

    public function definition(): array
    {
        return [
            'one_time_product_id' => OneTimeProduct::factory(),
            'currency_id' => Currency::where('code', 'USD')->first()->id,
            'price' => 1000,
        ];
    }
}
