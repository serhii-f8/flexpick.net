<?php

namespace App\Services\AuditReport;

use App\Models\AuditFunnelEvent;
use App\Models\AuditRequest;

class AuditFunnelRecorder
{
    public const STAGE_SUBMITTED = 'submitted';

    public const STAGE_VERIFIED = 'verified';

    public const STAGE_QUEUED = 'queued';

    public const STAGE_AWAITING_PAYMENT = 'awaiting_payment';

    public const STAGE_REPORT_SENT = 'report_sent';

    public const STAGE_REPORT_VIEWED = 'report_viewed';

    public const STAGE_UNLOCK_STARTED = 'unlock_started';

    public const STAGE_UNLOCK_PAID = 'unlock_paid';

    public const STAGE_RUN_PURCHASED = 'run_purchased';

    public const STAGE_FAILED = 'failed';

    public const STAGES = [
        self::STAGE_SUBMITTED,
        self::STAGE_VERIFIED,
        self::STAGE_QUEUED,
        self::STAGE_AWAITING_PAYMENT,
        self::STAGE_REPORT_SENT,
        self::STAGE_REPORT_VIEWED,
        self::STAGE_UNLOCK_STARTED,
        self::STAGE_UNLOCK_PAID,
        self::STAGE_RUN_PURCHASED,
        self::STAGE_FAILED,
    ];

    public function record(string $stage, ?AuditRequest $auditRequest = null, array $meta = []): void
    {
        AuditFunnelEvent::create([
            'audit_request_id' => $auditRequest?->id,
            'stage' => $stage,
            'meta' => $meta === [] ? null : $meta,
        ]);
    }
}
