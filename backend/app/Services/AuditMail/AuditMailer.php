<?php

namespace App\Services\AuditMail;

use App\Exceptions\MailcoachUnavailableException;
use App\Models\AuditEmailLog;
use App\Models\AuditRequest;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Throwable;

class AuditMailer
{
    public function __construct(
        private MailcoachClient $mailcoach,
    ) {}

    public function send(Mailable $mailable, string $recipient, ?AuditRequest $auditRequest = null): AuditEmailLog
    {
        $mailableName = class_basename($mailable);

        try {
            // Illuminate\Mail\Mailable doesn't declare envelope() itself — it's a convention every
            // class-based mailable in this app follows, but Larastan can't verify it structurally.
            // @phpstan-ignore-next-line method.notFound
            $subject = (string) $mailable->envelope()->subject;
            $body = $mailable->render();
        } catch (Throwable $e) {
            AuditEmailLog::create([
                'audit_request_id' => $auditRequest?->id,
                'mailable' => $mailableName,
                'recipient' => $recipient,
                'subject' => '',
                'body' => '',
                'status' => AuditEmailLog::STATUS_FAILED,
                'attempts' => 1,
                'last_error' => 'Render failed: '.$e->getMessage(),
                'sent_at' => now(),
            ]);

            throw $e;
        }

        $log = AuditEmailLog::create([
            'audit_request_id' => $auditRequest?->id,
            'mailable' => $mailableName,
            'recipient' => $recipient,
            'subject' => $subject,
            'body' => $body,
            'status' => AuditEmailLog::STATUS_PENDING,
            'attempts' => 1,
            'sent_at' => now(),
        ]);

        if ($this->mailcoach->isConfigured()) {
            try {
                $uuid = $this->mailcoach->sendTransactional($recipient, $log->subject, $log->body);
                $log->update(['status' => AuditEmailLog::STATUS_SENT, 'mailcoach_uuid' => $uuid]);

                return $log;
            } catch (MailcoachUnavailableException $e) {
                $log->update(['last_error' => 'Mailcoach unavailable, fell back to direct send: '.$e->getMessage()]);
            }
        }

        try {
            Mail::to($recipient)->send($mailable);
            $log->update(['status' => AuditEmailLog::STATUS_SENT]);
        } catch (Throwable $e) {
            $log->update(['status' => AuditEmailLog::STATUS_FAILED, 'last_error' => $e->getMessage()]);

            throw $e;
        }

        return $log;
    }
}
