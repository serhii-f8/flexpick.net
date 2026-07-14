<?php

namespace App\Listeners\User;

use App\Models\AuditReport;
use App\Models\AuditRequest;
use Illuminate\Auth\Events\Registered;

class LinkAuditReportsToUser
{
    public function handle(Registered $event): void
    {
        AuditRequest::query()
            ->whereNull('user_id')
            ->where('email', $event->user->email)
            ->update(['user_id' => $event->user->getAuthIdentifier()]);

        AuditReport::query()
            ->whereNull('user_id')
            ->whereHas('auditRequest', fn ($query) => $query->where('email', $event->user->email))
            ->update(['user_id' => $event->user->getAuthIdentifier()]);
    }
}
