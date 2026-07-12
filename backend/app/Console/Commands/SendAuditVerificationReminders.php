<?php

namespace App\Console\Commands;

use App\Constants\AuditRequestStatus;
use App\Mail\Audit\AuditVerifyReminderEmail;
use App\Models\AuditRequest;
use App\Services\AuditRequestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendAuditVerificationReminders extends Command
{
    protected $signature = 'app:send-audit-verification-reminders';

    protected $description = 'Remind unverified audit requesters before their verification window closes';

    public function handle(AuditRequestService $auditRequestService): int
    {
        $pending = AuditRequest::query()
            ->where('status', AuditRequestStatus::PENDING_VERIFICATION->value)
            ->whereNull('email_verified_at')
            ->where('created_at', '<=', now()->subHours(24))
            ->where('created_at', '>', now()->subHours(48))
            ->whereNull('meta->verification_reminder_sent_at')
            ->get();

        foreach ($pending as $auditRequest) {
            Mail::to($auditRequest->email)->send(
                new AuditVerifyReminderEmail($auditRequest, $auditRequestService->verificationUrl($auditRequest))
            );
            $auditRequest->update([
                'meta' => array_merge($auditRequest->meta ?? [], ['verification_reminder_sent_at' => now()->toIso8601String()]),
            ]);
        }

        $this->info("Sent {$pending->count()} verification reminders.");

        return self::SUCCESS;
    }
}
