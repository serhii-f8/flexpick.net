<?php

namespace Tests\Unit;

use App\Models\Permission;
use App\Models\Role;
use Tests\Feature\FeatureTest;

class RolesAndPermissionsSeederTest extends FeatureTest
{
    public function test_admin_role_has_the_review_expert_audits_permission(): void
    {
        $this->assertTrue(Permission::where('name', 'review expert audits')->exists());
        $this->assertTrue(Role::findByName('admin')->hasPermissionTo('review expert audits'));
    }
}
