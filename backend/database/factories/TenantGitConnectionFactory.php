<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TenantGitConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TenantGitConnection> */
class TenantGitConnectionFactory extends Factory
{
    protected $model = TenantGitConnection::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'provider' => 'github',
            'account_login' => $this->faker->userName(),
            'access_token' => 'test-token-'.$this->faker->uuid(),
            'refresh_token' => null,
            'expires_at' => null,
            'connected_by_user_id' => User::factory(),
            'connected_at' => now(),
        ];
    }
}
