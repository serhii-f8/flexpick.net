<?php

namespace Tests\Feature\Services\Fixtures;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class UnrenderableMailable extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'This mailable cannot render',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.audit.no-such-view-exists',
        );
    }
}
