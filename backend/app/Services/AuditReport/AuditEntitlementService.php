<?php

namespace App\Services\AuditReport;

use App\Models\AuditRequest;
use App\Models\User;
use App\Models\UserParameter;

class AuditEntitlementService
{
    public const BONUS_PARAM = 'audit_bonus_free_runs';

    public function freeRunsLimit(string $email): int
    {
        $bonus = 0;

        $userId = User::where('email', $email)->value('id');
        if ($userId !== null) {
            $bonus = (int) UserParameter::query()
                ->where('user_id', $userId)
                ->where('name', self::BONUS_PARAM)
                ->value('value');
        }

        return (int) config('audit.free_reports_limit') + $bonus;
    }

    public function freeRunsUsed(string $email): int
    {
        return AuditRequest::query()
            ->where('email', $email)
            ->where('free_run', true)
            ->count();
    }

    public function hasFreeRun(string $email): bool
    {
        return $this->freeRunsUsed($email) < $this->freeRunsLimit($email);
    }

    public function consumeFreeRun(AuditRequest $auditRequest): void
    {
        $auditRequest->update(['free_run' => true]);
    }
}
