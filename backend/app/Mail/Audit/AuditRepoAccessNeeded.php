<?php

namespace App\Mail\Audit;

use App\Filament\Dashboard\Pages\AuditReports;
use App\Models\AuditRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AuditRepoAccessNeeded extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  bool  $accessProblem  we could not reach the repository at all,
     *                               as opposed to reaching it and failing to
     *                               process it (too large, bad branch)
     */
    public function __construct(
        public AuditRequest $auditRequest,
        public bool $accessProblem = true,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->auditRequest->repo_url
                ? __("We couldn't reach your repository")
                : __('One more step for your codebase audit'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.audit.access-needed',
            with: ['rerunUrl' => $this->rerunUrl()],
        );
    }

    /**
     * Where "run it again" lands: the workspace's Run-an-audit page with this
     * repository prefilled, or sign-in for a landing-page visitor whose
     * request has no workspace yet.
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
