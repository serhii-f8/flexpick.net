<?php

namespace Database\Factories;

use App\Models\Currency;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'status' => 'new',
            // Orders always carry a currency in practice -- the order mail view
            // dereferences $order->currency->code. Without a default here the
            // relation is null and any test that renders that view blows up.
            'currency_id' => fn () => Currency::where('code', 'USD')->value('id'),
        ];
    }
}
