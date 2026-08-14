<?php

namespace App\Console\Commands;

use App\Constants\AuditRequestStatus;
use App\Models\AuditRequest;
use Illuminate\Console\Command;

class PurgeUnverifiedAuditRequests extends Command
{
    protected $signature = 'app:purge-unverified-audit-requests';

    protected $description = 'Delete audit requests that were never email-verified after the retention window';

    public function handle(): int
    {
        $deleted = AuditRequest::query()
            ->where('status', AuditRequestStatus::PENDING_VERIFICATION->value)
            ->whereNull('email_verified_at')
            ->where('created_at', '<', now()->subDays((int) config('audit.unverified_purge_days')))
            ->delete();

        // A dashboard checkout intent is email-verified, so it can never match
        // the sweep above. Separate condition, same retention window.
        $abandoned = AuditRequest::query()
            ->where('status', AuditRequestStatus::AWAITING_PAYMENT->value)
            ->where('created_at', '<', now()->subDays((int) config('audit.unverified_purge_days')))
            ->delete();

        $this->info("Purged {$deleted} unverified and {$abandoned} abandoned audit request(s).");

        return self::SUCCESS;
    }
}
