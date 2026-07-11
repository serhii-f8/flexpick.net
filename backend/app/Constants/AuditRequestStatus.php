<?php

namespace App\Constants;

enum AuditRequestStatus: string
{
    case NEW = 'new';
    case QUEUED = 'queued';
    case ANALYZING = 'analyzing';
    case REPORT_READY = 'report_ready';
    case SENT = 'sent';
    case FAILED = 'failed';
    case NEEDS_FOLLOWUP = 'needs_followup';
    case HANDLED = 'handled';
}
