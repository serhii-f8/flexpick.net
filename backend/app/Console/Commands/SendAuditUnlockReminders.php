<?php

namespace App\Console\Commands;

use App\Listeners\Order\HandleAuditUnlockOrder;
use App\Mail\Audit\AuditUnlockReminder;
use App\Models\AuditReport;
use App\Models\UserParameter;
use App\Services\AuditMail\AuditMailer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\URL;

class SendAuditUnlockReminders extends Command
{
    public const REMINDER_PARAM = 'audit_unlock_reminder_sent';

    protected $signature = 'app:send-audit-unlock-reminders';

    protected $description = 'Remind users who started a $5 report unlock but abandoned checkout';

    public function handle(AuditMailer $auditMailer): int
    {
        $sent = 0;

        $intents = UserParameter::query()
            ->where('name', HandleAuditUnlockOrder::INTENT_PARAM)
            ->where('updated_at', '<=', now()->subDay())
            ->get();

        foreach ($intents as $intent) {
            try {
                $report = AuditReport::query()->where('uuid', $intent->value)->first();

                if ($report === null || $report->unlocked_at !== null) {
                    $intent->delete();

                    continue;
                }

                $reminderName = self::REMINDER_PARAM.':'.$report->uuid;

                $alreadyReminded = UserParameter::query()
                    ->where('user_id', $intent->user_id)
                    ->where('name', $reminderName)
                    ->exists();

                if ($alreadyReminded) {
                    continue;
                }

                $unlockUrl = URL::temporarySignedRoute('reports.unlock', now()->addDays(7), ['auditReport' => $report->uuid]);
                $auditMailer->send(new AuditUnlockReminder($report, $unlockUrl), $report->auditRequest->email, $report->auditRequest);

                UserParameter::create([
                    'user_id' => $intent->user_id,
                    'name' => $reminderName,
                    'value' => $report->uuid,
                ]);
                $sent++;
            } catch (\Throwable $e) {
                report($e);

                continue;
            }
        }

        $this->info("Sent {$sent} unlock reminders.");

        return self::SUCCESS;
    }
}
