<?php

namespace App\Mail\Audit;

use App\Filament\Dashboard\Pages\AuditReports;
use App\Mail\Concerns\TracksAuditEmailLog;
use App\Models\AuditRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AuditCreditNeeded extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels, TracksAuditEmailLog;

    public function __construct(
        public AuditRequest $auditRequest,
        public bool $tooLarge = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->tooLarge
                ? __('Your repository is larger than our self-serve audits cover')
                : __('Your audit needs more credit'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.audit.credit-needed',
            with: ['rerunUrl' => $this->rerunUrl()],
        );
    }

    /**
     * The workspace's Run-an-audit page with this repository prefilled; its
     * tier cards show the balance and the buy button. Sign-in for a
     * landing-page visitor whose request has no workspace yet.
     */
    private function rerunUrl(): string
    {
        $tenant = $this->auditRequest->tenant;

        if ($tenant === null) {
            return route('login');
        }

        return AuditReports::getUrl(['repo' => $this->auditRequest->repo_url], panel: 'dashboard', tenant: $tenant);
    }
}
