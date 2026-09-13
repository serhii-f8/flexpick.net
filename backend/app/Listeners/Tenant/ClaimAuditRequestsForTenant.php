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
 *
 * Only for an actual member. The admin panel's CreateTenant page fires
 * TenantCreated with the operator as `tenantCreator`, and the operator's
 * own audits must not land in the customer's workspace. On the
 * TenantCreationService path the attach precedes the dispatch, so this
 * check is a no-op there.
 */
class ClaimAuditRequestsForTenant
{
    public function handle(TenantCreated|UserJoinedTenant $event): void
    {
        $user = $event instanceof TenantCreated ? $event->tenantCreator : $event->user;

        if (! $event->tenant->users()->whereKey($user->id)->exists()) {
            return;
        }

        AuditRequest::query()
            ->whereNull('tenant_id')
            ->where(function ($query) use ($user): void {
                $query->where('user_id', $user->id)
                    ->orWhereRaw('LOWER(email) = ?', [strtolower($user->email)]);
            })
            ->update(['tenant_id' => $event->tenant->id]);
    }
}
