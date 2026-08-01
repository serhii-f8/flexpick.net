<?php

namespace App\Mail\Audit;

use Illuminate\Mail\Mailable;

class StoredAuditEmail extends Mailable
{
    public function __construct(
        private readonly string $storedSubject,
        private readonly string $storedBody,
    ) {}

    public function build(): self
    {
        return $this->subject($this->storedSubject)->html($this->storedBody);
    }
}
