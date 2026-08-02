<?php

namespace App\Notifications\Channels;

use App\Notifications\OperationsAlert;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class MailAlertChannel
{
    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! $notification instanceof OperationsAlert) {
            return;
        }

        $to = (string) config('health.flexpick.mail.to');

        if ($to === '') {
            Log::warning('Health alert channel Mail is enabled but not configured; skipping.');

            return;
        }

        try {
            Mail::raw($notification->toAlertText(), fn ($message) => $message->to($to)->subject($notification->subject()));
        } catch (Throwable $e) {
            // Never rethrow: one dead channel must not suppress the others,
            // nor crash the health run.
            Log::warning('Health alert channel Mail failed: '.$e->getMessage());
        }
    }
}
