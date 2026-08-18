<?php

namespace Database\Seeders\Demo;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Seeder;

/**
 * Minimal demo dataset: the real audit catalog (via DatabaseSeeder), one
 * admin, and one subscribed demo user (AuditDemoSeeder) -- built for handing
 * the product to someone to click through live, not for populating charts
 * with fake volume. That kind of bulk fixture data belongs in a dedicated QA
 * seeder if it's ever needed again, not here.
 */
class DemoDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->callOnce([
            DatabaseSeeder::class,
        ]);

        $adminUser = User::where('email', 'admin@admin.com')->first();

        if (! $adminUser) {
            $adminUser = User::factory()->create([
                'email' => 'admin@admin.com',
                'password' => bcrypt(config('demo.admin_password')),
                'name' => 'Admin',
                'public_name' => 'Admin',
                'is_admin' => true,
            ]);

            $adminUser->assignRole('admin');
        }

        $this->call(AuditDemoSeeder::class);
    }
}
