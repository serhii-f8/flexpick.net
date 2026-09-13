<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Audits and audit quotas moved from the user to the workspace. Assign
 * every legacy row to the workspace its requester most plausibly meant:
 * the one they created, else their default/earliest membership. Rows that
 * resolve to nothing stay NULL and are claimed later by
 * ClaimAuditRequestsForTenant when the person gets a workspace.
 *
 * Idempotent: only NULL tenant_id rows and only audit-related user
 * parameters are touched. Not reversible -- down() is a no-op by design.
 */
return new class extends Migration
{
    private const MOVED_PARAMS = [
        'audit_purchased_credits_diagnostic',
        'audit_purchased_credits_deep_ai',
        'audit_purchased_credits_expert',
        'audit_bonus_free_runs',
    ];

    public function up(): void
    {
        $tenantByUser = $this->primaryTenantByUserId();

        $this->assignAuditRequests($tenantByUser);
        $this->moveUserParameters($tenantByUser);

        // An in-flight dashboard checkout intent is not worth carrying
        // across the ownership change; the buyer simply starts again.
        DB::table('user_parameters')->where('name', 'audit_tier_intent')->delete();
    }

    public function down(): void
    {
        // Data migration; the columns are dropped by their own migrations.
    }

    /** @return array<int, int> user_id => tenant_id */
    private function primaryTenantByUserId(): array
    {
        $result = [];

        // Earliest-created tenant per creator wins outright.
        foreach (DB::table('tenants')->whereNotNull('created_by')->orderBy('id')->get(['id', 'created_by']) as $row) {
            $result[(int) $row->created_by] ??= (int) $row->id;
        }

        // Otherwise the default membership, then the earliest one.
        $memberships = DB::table('tenant_user')
            ->orderByDesc('is_default')
            ->orderBy('created_at')
            ->orderBy('tenant_id')
            ->get(['user_id', 'tenant_id']);

        foreach ($memberships as $row) {
            $result[(int) $row->user_id] ??= (int) $row->tenant_id;
        }

        return $result;
    }

    /** @param array<int, int> $tenantByUser */
    private function assignAuditRequests(array $tenantByUser): void
    {
        $userIdByEmail = DB::table('users')
            ->get(['id', 'email'])
            ->mapWithKeys(fn ($user) => [strtolower((string) $user->email) => (int) $user->id])
            ->all();

        DB::table('audit_requests')
            ->whereNull('tenant_id')
            ->orderBy('id')
            ->select(['id', 'user_id', 'email'])
            ->chunkById(500, function ($rows) use ($tenantByUser, $userIdByEmail): void {
                foreach ($rows as $row) {
                    $userId = $row->user_id !== null ? (int) $row->user_id : ($userIdByEmail[strtolower((string) $row->email)] ?? null);
                    $tenantId = $userId !== null ? ($tenantByUser[$userId] ?? null) : null;

                    if ($tenantId === null) {
                        continue;
                    }

                    DB::table('audit_requests')->where('id', $row->id)->update(['tenant_id' => $tenantId]);
                }
            });
    }

    /** @param array<int, int> $tenantByUser */
    private function moveUserParameters(array $tenantByUser): void
    {
        $rows = DB::table('user_parameters')->whereIn('name', self::MOVED_PARAMS)->get();

        foreach ($rows as $row) {
            $tenantId = $tenantByUser[(int) $row->user_id] ?? null;

            if ($tenantId === null) {
                continue; // no workspace to move it to; harmless and no longer read
            }

            $existing = DB::table('tenant_parameters')->where('tenant_id', $tenantId)->where('name', $row->name)->first();
            $sum = (int) ($existing->value ?? 0) + (int) $row->value;

            if ($existing === null) {
                DB::table('tenant_parameters')->insert([
                    'tenant_id' => $tenantId,
                    'name' => $row->name,
                    'value' => (string) $sum,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('tenant_parameters')->where('id', $existing->id)->update(['value' => (string) $sum, 'updated_at' => now()]);
            }

            DB::table('user_parameters')->where('id', $row->id)->delete();
        }
    }
};
