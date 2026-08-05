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
    case PENDING_VERIFICATION = 'pending_verification';
    case AWAITING_ACCESS = 'awaiting_access';
    case AWAITING_PAYMENT = 'awaiting_payment';
    case EXPERT_REVIEW = 'expert_review';
}
