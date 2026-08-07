<?php

namespace App\Services\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class RenderSafeMailer
{
    /**
     * Send a mailable with structured logging on render or send failure.
     *
     * Unlike a bare Mail::to()->send() call, this wrapper catches both render
     * and transport exceptions, emits a structured Log::error with enough
     * context to debug (mailable class name, recipient, exception message),
     * and then rethrows so the caller/queue-worker still sees the failure.
     */
    public function send(Mailable $mailable, string $recipient): void
    {
        $mailableName = class_basename($mailable);

        try {
            $mailable->render();
        } catch (Throwable $e) {
            Log::error('Mail render failed', [
                'mailable' => $mailableName,
                'recipient' => $recipient,
                'exception' => $e->getMessage(),
            ]);

            throw $e;
        }

        try {
            Mail::to($recipient)->send($mailable);
        } catch (Throwable $e) {
            Log::error('Mail send failed', [
                'mailable' => $mailableName,
                'recipient' => $recipient,
                'exception' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
