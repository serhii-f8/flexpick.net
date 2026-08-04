<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Testing\TestingDatabaseSeeder;
use Tests\TestCase;

/**
 * Base class for the feature suite — abstract so PHPUnit does not collect it as
 * a test class of its own. Its filename matches the `*Test.php` suffix, and a
 * concrete class with no test methods is a test-runner warning, which PHPUnit 11
 * reports through a non-zero exit code and would turn CI red on every run.
 */
abstract class FeatureTest extends TestCase
{
    protected static bool $setUpHasRunOnce = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! static::$setUpHasRunOnce) {
            $this->artisan('migrate:fresh');
            $this->seed(TestingDatabaseSeeder::class);

            static::$setUpHasRunOnce = true;
        }

        $this->configureDefaultCurrency();
        $this->withoutExceptionHandling();
        $this->withoutVite();
    }

    protected function createUser(?Tenant $tenant = null, array $tenantPermissions = [], array $attributes = [])
    {
        $user = User::factory()->create($attributes);

        if ($tenant !== null) {
            $tenant->users()->attach($user);

            foreach ($tenantPermissions as $permission) {
                $user->tenants()->where('tenant_id', $tenant->id)->first()->pivot->givePermissionTo($permission);
            }
        }

        return $user;
    }

    protected function createTenant()
    {
        return Tenant::factory()->create();
    }

    protected function createAdminUser()
    {
        $user = User::factory()->create([
            'is_admin' => true,
        ]);

        $user->each(function ($user) {
            $user->assignRole('admin');
        });

        return $user;
    }

    protected function configureDefaultCurrency(): void
    {
        config()->set('app.default_currency', 'USD');
    }
}
