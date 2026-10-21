<?php

use App\Constants\TenancyPermissionConstants;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * Workspace members (the tenant "user" role) can see the workspace's orders,
 * subscriptions and transactions. Mirrors RolesAndPermissionsSeeder for
 * databases seeded before members were granted these.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        TenancyPermissionConstants::PERMISSION_VIEW_SUBSCRIPTIONS,
        TenancyPermissionConstants::PERMISSION_VIEW_ORDERS,
        TenancyPermissionConstants::PERMISSION_VIEW_TRANSACTIONS,
    ];

    public function up(): void
    {
        $role = $this->userRole();

        if ($role === null) {
            return;
        }

        $role->givePermissionTo(array_map(fn (string $name) => Permission::findOrCreate($name), self::PERMISSIONS));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $role = $this->userRole();

        if ($role === null) {
            return;
        }

        foreach (self::PERMISSIONS as $name) {
            if ($role->hasPermissionTo($name)) {
                $role->revokePermissionTo($name);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function userRole(): ?Role
    {
        return Role::query()
            ->where('name', TenancyPermissionConstants::ROLE_USER)
            ->where('is_tenant_role', true)
            ->first();
    }
};
