<?php

namespace App\Mail\Audit;

use App\Filament\Dashboard\Pages\AuditReports;
use App\Filament\Dashboard\Pages\GitConnections;
use App\Models\AuditRequest;
use App\Services\GitProviders\GitProviderResolver;
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
     * @param  bool  $reconnect  the workspace has (or had) a connection for the
     *                           repo's provider that no longer reads it: ask
     *                           them to reconnect it, not to connect one
     */
    public function __construct(
        public AuditRequest $auditRequest,
        public bool $accessProblem = true,
        public bool $reconnect = false,
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
            with: [
                'rerunUrl' => $this->rerunUrl(),
                'gitConnectionsUrl' => $this->gitConnectionsUrl(),
                'providerLabel' => $this->providerLabel(),
            ],
        );
    }

    private function providerLabel(): string
    {
        if ($this->auditRequest->repo_url === null) {
            return 'Git';
        }

        return app(GitProviderResolver::class)->forUrl($this->auditRequest->repo_url)?->label() ?? 'Git';
    }

    /**
     * Where the reconnect happens, or sign-in for a request with no workspace.
     */
    private function gitConnectionsUrl(): string
    {
        $tenant = $this->auditRequest->tenant;

        if ($tenant === null) {
            return route('login');
        }

        return GitConnections::getUrl(panel: 'dashboard', tenant: $tenant);
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
