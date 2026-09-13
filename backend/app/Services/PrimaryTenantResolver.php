<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;

/**
 * The one workspace to credit when something happens to a *person* rather
 * than inside a workspace (a referral reward, a legacy backfill). Same rule
 * as the 2026_09_13 backfill migration: the workspace they created, else
 * their default membership, else the earliest one.
 */
class PrimaryTenantResolver
{
    public function resolve(User $user): ?Tenant
    {
        $created = Tenant::query()->where('created_by', $user->id)->orderBy('id')->first();

        if ($created !== null) {
            return $created;
        }

        /** @var Tenant|null $membership */
        $membership = $user->tenants()
            ->orderByPivot('is_default', 'desc')
            ->orderByPivot('created_at')
            ->orderBy('tenants.id')
            ->first();

        return $membership;
    }
}
