<?php

namespace App\Listeners\Mail;

use App\Models\AuditEmailLog;
use Illuminate\Mail\Events\MessageSent;

/** A queued audit email's log row becomes "sent" only once the transport has accepted it. */
class MarkAuditEmailSent
{
    public function handle(MessageSent $event): void
    {
        $id = $event->data['auditEmailLogId'] ?? null;

        if (! is_int($id)) {
            return;
        }

        AuditEmailLog::query()
            ->whereKey($id)
            ->where('status', AuditEmailLog::STATUS_PENDING)
            ->update(['status' => AuditEmailLog::STATUS_SENT]);
    }
}
