<?php

namespace App\Services;

use App\Constants\AuditFunding;
use App\Constants\AuditRequestStatus;
use App\Exceptions\AuditNotAnalyzableException;
use App\Jobs\GenerateAuditReport;
use App\Mail\Audit\AuditQuotaExhausted;
use App\Mail\Audit\AuditRepoAccessNeeded;
use App\Mail\Audit\AuditRequestFailed;
use App\Mail\Audit\AuditRequestReceived;
use App\Mail\Audit\AuditVerifyEmail;
use App\Mail\Audit\NewAuditRequestAdminNotification;
use App\Models\AuditRequest;
use App\Models\User;
use App\Services\AuditMail\AuditMailer;
use App\Services\AuditReport\AuditEntitlementService;
use App\Services\AuditReport\AuditFunnelRecorder;
use App\Services\AuditReport\RepositoryCloner;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class AuditRequestService
{
    public function __construct(
        private AuditEntitlementService $entitlements,
        private RepositoryCloner $cloner,
        private AuditFunnelRecorder $funnel,
        private AuditMailer $auditMailer,
        private PrimaryTenantResolver $primaryTenants,
    ) {}

    public function submit(array $data, array $meta = []): AuditRequest
    {
        $recentDuplicate = AuditRequest::query()
            ->where('email', $data['email'])
            ->where('created_at', '>=', now()->subMinutes(10))
            ->exists();

        if ($recentDuplicate) {
            throw new TooManyRequestsHttpException(600, __('We already received a request from this email. Give us a few minutes.'));
        }

        $consented = (bool) ($data['marketing_consent'] ?? false);

        $auditRequest = AuditRequest::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'repo_url' => $data['repo_url'] ?? null,
            'message' => $data['message'] ?? null,
            'status' => AuditRequestStatus::PENDING_VERIFICATION->value,
            'funding' => AuditFunding::FREE->value,
            'marketing_consent' => $consented,
            'consented_at' => $consented ? now() : null,
            'meta' => $meta,
            'tenant_id' => $this->primaryTenantIdForEmail($data['email']),
        ]);

        $this->funnel->record(AuditFunnelRecorder::STAGE_SUBMITTED, $auditRequest);

        $this->auditMailer->send(new AuditVerifyEmail($auditRequest, $this->verificationUrl($auditRequest)), $auditRequest->email, $auditRequest);

        return $auditRequest;
    }

    /**
     * A landing-page submission is normally tenantless until the visitor's
     * first workspace claims it (ClaimAuditRequestsForTenant). Someone who
     * already has a workspace never triggers that claim again, so their row
     * is stamped up front -- with the same primary-workspace rule the claim
     * would have used. Unknown email, or a user with no workspace yet: null,
     * and the claim listener takes it from there.
     */
    private function primaryTenantIdForEmail(string $email): ?int
    {
        $user = User::query()->whereRaw('LOWER(email) = ?', [strtolower($email)])->first();

        if ($user === null) {
            return null;
        }

        return $this->primaryTenants->resolve($user)?->id;
    }

    public function verificationUrl(AuditRequest $auditRequest): string
    {
        return URL::temporarySignedRoute(
            'audit-requests.verify',
            now()->addHours((int) config('audit.verification_link_hours')),
            ['auditRequest' => $auditRequest->uuid],
        );
    }

    public function purchaseRunUrl(AuditRequest $auditRequest): string
    {
        return URL::temporarySignedRoute(
            'audit-requests.purchase-run',
            now()->addDays(7),
            ['auditRequest' => $auditRequest->uuid],
        );
    }

    public function statusUrl(AuditRequest $auditRequest): string
    {
        return URL::signedRoute('audit-requests.status', ['auditRequest' => $auditRequest->uuid]);
    }

    public function routeVerified(AuditRequest $auditRequest): void
    {
        if ($auditRequest->repo_url === null) {
            $this->markNeedsFollowup($auditRequest, 'No repository URL provided');
            $this->notifyAdmin($auditRequest);

            return;
        }

        try {
            $this->cloner->preflight($auditRequest->repo_url, useToken: false);
        } catch (AuditNotAnalyzableException $e) {
            // Anonymous on purpose: our token reads every customer's private
            // repos, so a landing visitor must not be able to aim it at one.
            // Nothing was spent yet, so closing refunds nothing; the email
            // sends them to the dashboard to run it again once we have access.
            $this->closeNotAnalyzable($auditRequest, $e->getMessage(), $e->accessDenied);
            $this->notifyAdmin($auditRequest);

            return;
        }

        if (! $this->entitlements->hasFreeRunForEmail($auditRequest->email)) {
            $auditRequest->update(['status' => AuditRequestStatus::AWAITING_PAYMENT->value]);
            $this->funnel->record(AuditFunnelRecorder::STAGE_AWAITING_PAYMENT, $auditRequest);
            $this->auditMailer->send(new AuditQuotaExhausted($auditRequest, $this->purchaseRunUrl($auditRequest)), $auditRequest->email, $auditRequest);
            $this->notifyAdmin($auditRequest);

            return;
        }

        $this->entitlements->consumeFreeRun($auditRequest);
        $auditRequest->update(['status' => AuditRequestStatus::QUEUED->value]);
        GenerateAuditReport::dispatch($auditRequest);
        $this->funnel->record(AuditFunnelRecorder::STAGE_QUEUED, $auditRequest);
        $this->auditMailer->send(new AuditRequestReceived($auditRequest, $this->statusUrl($auditRequest)), $auditRequest->email, $auditRequest);
        $this->notifyAdmin($auditRequest);
    }

    /**
     * Delete an audit and everything it owns.
     *
     * The child rows take care of themselves: audit_reports,
     * audit_finding_groups and audit_ai_calls cascade, while audit_email_logs
     * and audit_funnel_events null out so the delivery and funnel history
     * survives the record it described.
     *
     * The PDF does not. A DB-level cascade never fires Eloquent events, so no
     * hook on AuditReport can ever see this coming — the file has to be
     * removed here, before the row that names it disappears.
     */
    public function delete(AuditRequest $auditRequest): void
    {
        $pdfPath = $auditRequest->report?->pdf_path;

        if ($pdfPath !== null) {
            Storage::disk('local')->delete($pdfPath);
        }

        $auditRequest->delete();
    }

    public function markNeedsFollowup(AuditRequest $auditRequest, string $reason): void
    {
        $auditRequest->update([
            'status' => AuditRequestStatus::NEEDS_FOLLOWUP->value,
            'failure_reason' => $reason,
        ]);

        $this->auditMailer->send(new AuditRepoAccessNeeded($auditRequest), $auditRequest->email, $auditRequest);
    }

    /**
     * Close a request whose repository we could not reach or process, and
     * hand back whatever it spent. It never restarts: the customer fixes
     * access and starts a new audit, which the refund keeps free of a double
     * charge.
     */
    public function closeNotAnalyzable(AuditRequest $auditRequest, string $reason, bool $accessDenied = true): void
    {
        $auditRequest->update([
            'status' => AuditRequestStatus::NOT_ANALYZABLE->value,
            'failure_reason' => $reason,
        ]);

        if ($this->entitlements->refund($auditRequest)) {
            $auditRequest->appendPipelineLog('refunded', 'Run refunded: the repository could not be analyzed');
        }

        $this->auditMailer->send(new AuditRepoAccessNeeded($auditRequest, $accessDenied), $auditRequest->email, $auditRequest);
    }

    public function markFailed(AuditRequest $auditRequest, string $reason): void
    {
        $auditRequest->appendPipelineLog('failed', $reason);

        $auditRequest->update([
            'status' => AuditRequestStatus::FAILED->value,
            'failure_reason' => $reason,
        ]);

        $this->funnel->record(AuditFunnelRecorder::STAGE_FAILED, $auditRequest, ['reason' => $reason]);

        $this->auditMailer->send(new AuditRequestFailed($auditRequest), $auditRequest->email, $auditRequest);
        $this->notifyAdmin($auditRequest);
    }

    private function notifyAdmin(AuditRequest $auditRequest): void
    {
        $adminEmail = config('audit.admin_email');

        if ($adminEmail) {
            $this->auditMailer->send(new NewAuditRequestAdminNotification($auditRequest), $adminEmail, $auditRequest);
        }
    }
}
