<?php

namespace App\Mail\Concerns;

use App\Models\AuditEmailLog;
use Throwable;

/**
 * Carries the AuditEmailLog row a queued audit email belongs to, so the row can
 * say what actually happened to the message instead of only that it was queued:
 * MarkAuditEmailSent flips it to sent once the worker hands the message to the
 * transport, and failed() -- which SendQueuedMailable calls when the job gives up
 * -- flips it to failed.
 */
trait TracksAuditEmailLog
{
    public ?int $auditEmailLogId = null;

    public function failed(Throwable $e): void
    {
        if ($this->auditEmailLogId === null) {
            return;
        }

        AuditEmailLog::query()->whereKey($this->auditEmailLogId)->update([
            'status' => AuditEmailLog::STATUS_FAILED,
            'last_error' => mb_substr($e->getMessage(), 0, 1000),
        ]);
    }
}
