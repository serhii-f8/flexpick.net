<?php

namespace App\Mapper;

use App\Constants\AuditRequestStatus;

class AuditRequestStatusMapper
{
    public function mapForDisplay(string $status): string
    {
        return match ($status) {
            AuditRequestStatus::NEW->value => __('New'),
            AuditRequestStatus::QUEUED->value => __('Queued'),
            AuditRequestStatus::ANALYZING->value => __('Analyzing'),
            AuditRequestStatus::REPORT_READY->value => __('Report ready'),
            AuditRequestStatus::SENT->value => __('Sent'),
            AuditRequestStatus::FAILED->value => __('Failed'),
            AuditRequestStatus::NEEDS_FOLLOWUP->value => __('Needs follow-up'),
            AuditRequestStatus::HANDLED->value => __('Handled'),
            default => $status,
        };
    }

    public function mapColor(string $status): string
    {
        return match ($status) {
            AuditRequestStatus::SENT->value, AuditRequestStatus::HANDLED->value => 'success',
            AuditRequestStatus::FAILED->value => 'danger',
            AuditRequestStatus::NEEDS_FOLLOWUP->value => 'warning',
            AuditRequestStatus::REPORT_READY->value, AuditRequestStatus::ANALYZING->value, AuditRequestStatus::QUEUED->value => 'info',
            default => 'gray',
        };
    }
}
