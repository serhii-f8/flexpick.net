<?php

namespace App\Listeners\Tenant;

use App\Events\Tenant\TenantCreated;
use App\Events\Tenant\UserJoinedTenant;
use App\Models\AuditRequest;

/**
 * A landing-page audit is submitted before the visitor has a workspace, so
 * it is stored tenantless. The first workspace the person gets -- created
 * or joined -- takes those rows, exactly once: a claimed row is never
 * re-homed, so joining a second workspace brings nothing along.
 */
class ClaimAuditRequestsForTenant
{
    public function handle(TenantCreated|UserJoinedTenant $event): void
    {
        $user = $event instanceof TenantCreated ? $event->tenantCreator : $event->user;

        AuditRequest::query()
            ->whereNull('tenant_id')
            ->where(function ($query) use ($user): void {
                $query->where('user_id', $user->id)
                    ->orWhereRaw('LOWER(email) = ?', [strtolower($user->email)]);
            })
            ->update(['tenant_id' => $event->tenant->id]);
    }
}
