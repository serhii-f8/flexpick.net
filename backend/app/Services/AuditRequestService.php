<?php

namespace App\Services;

use App\Constants\AuditFunding;
use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Constants\AwaitingCreditReason;
use App\Exceptions\AuditNotAnalyzableException;
use App\Jobs\GenerateAuditReport;
use App\Mail\Audit\AuditAwaitingPartnerApproval;
use App\Mail\Audit\AuditCreditNeeded;
use App\Mail\Audit\AuditQuotaExhausted;
use App\Mail\Audit\AuditRepoAccessNeeded;
use App\Mail\Audit\AuditRequestFailed;
use App\Mail\Audit\AuditRequestReceived;
use App\Mail\Audit\AuditVerifyEmail;
use App\Mail\Audit\NewAuditRequestAdminNotification;
use App\Models\AuditEmailLog;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Models\User;
use App\Services\AuditMail\AuditMailer;
use App\Services\AuditReport\AuditEntitlementService;
use App\Services\AuditReport\AuditFunnelRecorder;
use App\Services\AuditReport\AuditPartnerOrderService;
use App\Services\AuditReport\RepositoryCloner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Sentry\Severity;
use Sentry\State\Scope;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

class AuditRequestService
{
    public function __construct(
        private AuditEntitlementService $entitlements,
        private RepositoryCloner $cloner,
        private AuditFunnelRecorder $funnel,
        private AuditMailer $auditMailer,
        private PrimaryTenantResolver $primaryTenants,
        private PartnerContactResolver $partnerContacts,
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

        // Kept only when it still names an active partner: it decides who is
        // CC'd on the operator email, so an arbitrary string must not stick.
        $referralCode = $data['referral_code'] ?? null;

        if ($this->partnerContacts->forCode($referralCode) !== null) {
            $meta['referral_code'] = $referralCode;
        }

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

        $this->auditMailer->send(
            new AuditVerifyEmail($auditRequest, $this->verificationUrl($auditRequest)),
            $auditRequest->email,
            $auditRequest,
        );

        // The admin and the referring partner hear about the lead now, not only
        // once the visitor gets round to confirming their email.
        $this->notifyAdmin($auditRequest);

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

    /**
     * Route a freshly verified landing request: close it, ask for payment, or
     * spend a free run and queue it.
     *
     * RouteVerifiedAuditRequest retries, so this must be safe to run again.
     * Only a request still pending verification is routed: an attempt that
     * threw after routing (a mail or funnel write) must not route it a second
     * time -- that would count its own free run against it, or dispatch the
     * pipeline twice. The decision and the status flip are taken together
     * under the row lock; the network probe before it is not held under it.
     */
    public function routeVerified(AuditRequest $auditRequest): void
    {
        if (! $this->isAwaitingRouting($auditRequest)) {
            return;
        }

        if ($auditRequest->repo_url === null) {
            $this->markNeedsFollowup($auditRequest, 'No repository URL provided');
            $this->notifyAdmin($auditRequest);

            return;
        }

        try {
            $this->cloner->preflight($auditRequest->repo_url, tenant: $auditRequest->tenant);
        } catch (AuditNotAnalyzableException $e) {
            // A tenantless landing request probes anonymously; a tenant-stamped
            // one (returning customer) uses that tenant's own connection if it
            // has one. No proactive connection gate here -- a public repo
            // succeeds either way; a private one without a connection fails
            // through the same not-reachable path as any other unreachable
            // repo. The dashboard is where we gate before charging
            // (AuditReports::launchAudit()). Nothing was spent yet, so closing
            // refunds nothing; the email sends them to the dashboard to run
            // it again once they have connected their account.
            $this->closeNotAnalyzable($auditRequest, $e->getMessage(), $e->accessDenied, $e->reconnect);
            $this->notifyAdmin($auditRequest);

            return;
        }

        $routed = DB::transaction(function () use ($auditRequest): ?AuditRequestStatus {
            if (! $this->isAwaitingRouting($auditRequest, lock: true)) {
                return null;
            }

            if (! $this->entitlements->hasFreeRunForEmail($auditRequest->email)) {
                $auditRequest->update(['status' => AuditRequestStatus::AWAITING_PAYMENT->value]);

                return AuditRequestStatus::AWAITING_PAYMENT;
            }

            $this->entitlements->consumeFreeRun($auditRequest);
            $auditRequest->update(['status' => AuditRequestStatus::QUEUED->value]);

            return AuditRequestStatus::QUEUED;
        });

        if ($routed === null) {
            return;
        }

        if ($routed === AuditRequestStatus::AWAITING_PAYMENT) {
            $this->funnel->record(AuditFunnelRecorder::STAGE_AWAITING_PAYMENT, $auditRequest);

            // A referred customer pays their partner: the cash order is put in
            // front of the partner to approve now, and the customer is told so
            // instead of being sent to a checkout. Resolved lazily -- the order
            // side reaches back into pricing and subscription services.
            $order = app(AuditPartnerOrderService::class)->openFor($auditRequest);

            if ($order !== null) {
                $this->auditMailer->send(new AuditAwaitingPartnerApproval($auditRequest, $order), $auditRequest->email, $auditRequest);
                $this->notifyAdmin($auditRequest);

                return;
            }

            $this->auditMailer->send(new AuditQuotaExhausted($auditRequest, $this->purchaseRunUrl($auditRequest)), $auditRequest->email, $auditRequest);
            $this->notifyAdmin($auditRequest);

            return;
        }

        GenerateAuditReport::dispatch($auditRequest);
        $this->funnel->record(AuditFunnelRecorder::STAGE_QUEUED, $auditRequest);
        $this->auditMailer->send(new AuditRequestReceived($auditRequest, $this->statusUrl($auditRequest)), $auditRequest->email, $auditRequest);
        $this->notifyAdmin($auditRequest);
    }

    /** Read fresh (a retried job carries the row as it was first queued). */
    public function isAwaitingRouting(AuditRequest $auditRequest, bool $lock = false): bool
    {
        $status = AuditRequest::query()
            ->whereKey($auditRequest->getKey())
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->value('status');

        return $status === AuditRequestStatus::PENDING_VERIFICATION->value;
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
     *
     * $reconnect (from AuditNotAnalyzableException::$reconnect) is kept on the
     * row as well as passed to the email: the dashboard hint reads it later.
     */
    public function closeNotAnalyzable(AuditRequest $auditRequest, string $reason, bool $accessDenied = true, bool $reconnect = false): void
    {
        $kept = null;
        $closed = DB::transaction(function () use ($auditRequest, $reason, $reconnect, &$kept): bool {
            $kept = $this->keepDeliveredReport($auditRequest, $reason);

            if ($kept !== null) {
                return false;
            }

            $auditRequest->update([
                'status' => AuditRequestStatus::NOT_ANALYZABLE->value,
                'failure_reason' => $reason,
                'git_reconnect_required' => $reconnect,
            ]);

            if ($this->entitlements->refund($auditRequest)) {
                $auditRequest->appendPipelineLog('refunded', 'Run refunded: the repository could not be analyzed');
            }

            return true;
        });

        if ($kept !== null) {
            $this->alertIfUndelivered($auditRequest, $kept, $reason);
        }

        if (! $closed) {
            return;
        }

        // Same hazard as closeAwaitingCredit(): the close and refund are
        // committed, so a transport failure must not escape and have the
        // queue retry a request run() will not reprocess. The AuditMailer
        // log carries the failure for a manual resend.
        try {
            $this->auditMailer->send(new AuditRepoAccessNeeded($auditRequest, $accessDenied, $reconnect), $auditRequest->email, $auditRequest);
        } catch (Throwable $e) {
            $auditRequest->appendPipelineLog('mail_failed', "Customer email could not be sent: {$e->getMessage()}");
        }
    }

    /**
     * Close a request whose repository costs more runs than the workspace
     * could cover, or more than any self-serve band allows. Same shape as
     * closeNotAnalyzable(): terminal, refunded, never resumed. Sizing is
     * clone + scc only, so nothing chargeable was consumed.
     */
    public function closeAwaitingCredit(AuditRequest $auditRequest, string $reason, bool $tooLarge): void
    {
        // Atomic check-and-set: only the caller that flips a live request to
        // awaiting_credit may refund it, so two callers racing on the same
        // request can never refund or notify it twice.
        $kept = null;
        $closed = DB::transaction(function () use ($auditRequest, $reason, $tooLarge, &$kept): bool {
            $kept = $this->keepDeliveredReport($auditRequest, $reason);

            if ($kept !== null) {
                return false;
            }

            $affected = AuditRequest::query()
                ->whereKey($auditRequest->getKey())
                ->where('status', '!=', AuditRequestStatus::AWAITING_CREDIT->value)
                ->update([
                    'status' => AuditRequestStatus::AWAITING_CREDIT->value,
                    'failure_reason' => $reason,
                    // Kept for the dashboard hint, which must not tell a
                    // too-large repo to buy credit.
                    'awaiting_credit_reason' => ($tooLarge ? AwaitingCreditReason::TOO_LARGE : AwaitingCreditReason::INSUFFICIENT)->value,
                ]);

            if ($affected === 0) {
                return false;
            }

            $auditRequest->refresh();

            if ($this->entitlements->refund($auditRequest)) {
                $auditRequest->appendPipelineLog('refunded', 'Run refunded: the repository needs more runs than were available');
            }

            return true;
        });

        if ($kept !== null) {
            $this->alertIfUndelivered($auditRequest, $kept, $reason);
        }

        if (! $closed) {
            return;
        }

        // The close and the refund are committed; a transport failure must
        // not escape and have the queue retry the job, because run() will
        // not reprocess a closed request and the customer would never hear.
        // The AuditMailer log carries the failure for a manual resend.
        try {
            $this->auditMailer->send(new AuditCreditNeeded($auditRequest, $tooLarge), $auditRequest->email, $auditRequest);
        } catch (Throwable $e) {
            $auditRequest->appendPipelineLog('mail_failed', "Customer email could not be sent: {$e->getMessage()}");
        }

        // An oversized repo is a sales conversation, not a self-serve one.
        if ($tooLarge) {
            try {
                $this->notifyAdmin($auditRequest);
            } catch (Throwable $e) {
                $auditRequest->appendPipelineLog('mail_failed', "Admin email could not be sent: {$e->getMessage()}");
            }
        }
    }

    /**
     * A request with a persisted report is delivered: a failed re-run (the
     * admin "Retry pipeline") must not demote it, refund it or email the
     * customer. The retry already moved it to queued/analyzing, so restore
     * the delivered status and leave a trace in the pipeline log. Call inside
     * the caller's transaction: the row lock makes the report check hold
     * against a concurrent delivery (the report insert waits on the parent row).
     *
     * Returns the restored status, or null when there is no report to keep.
     * The caller hands a restored status to alertIfUndelivered() once its
     * transaction has committed.
     */
    private function keepDeliveredReport(AuditRequest $auditRequest, string $reason): ?AuditRequestStatus
    {
        AuditRequest::query()->whereKey($auditRequest->getKey())->lockForUpdate()->first();

        $report = AuditReport::query()->where('audit_request_id', $auditRequest->getKey())->first();

        if ($report === null) {
            return null;
        }

        $auditRequest->refresh();

        // The pre-retry status is overwritten, so derive it the way delivery
        // sets it: an expert report is held until publish() stamps
        // reviewed_at; a report the customer was mailed is sent.
        $sent = $auditRequest->emailLogs()
            ->where('mailable', 'AuditReportReady')
            ->where('status', AuditEmailLog::STATUS_SENT)
            ->exists();
        $heldForExpert = $auditRequest->tier === AuditTier::EXPERT
            && empty($report->payload['expert_review']['reviewed_at']);

        $restored = match (true) {
            $heldForExpert => AuditRequestStatus::EXPERT_REVIEW,
            $sent => AuditRequestStatus::SENT,
            default => AuditRequestStatus::REPORT_READY,
        };

        $auditRequest->update(['status' => $restored->value]);
        $auditRequest->appendPipelineLog('retry_failed', "Retry failed, delivered report kept: {$reason}");

        return $restored;
    }

    /**
     * A kept report restored to report_ready was saved but never mailed: a
     * first run whose PDF render or delivery kept failing lands here, and so
     * does its queue retry failing preflight or sizing. The customer still is
     * not told the run failed (the report exists and is theirs), but nobody
     * would ever notice otherwise -- report_ready is terminal, so no stuck-run
     * check sees it -- so the operator is alerted to deliver it by hand.
     * A sent report, or one held for expert review (the operator's queue by
     * design), stays silent.
     */
    private function alertIfUndelivered(AuditRequest $auditRequest, AuditRequestStatus $restored, string $reason): void
    {
        if ($restored !== AuditRequestStatus::REPORT_READY) {
            return;
        }

        $message = 'Audit report was saved but never delivered to the customer';

        Log::error($message, ['audit_request' => $auditRequest->uuid, 'reason' => $reason]);

        \Sentry\withScope(function (Scope $scope) use ($auditRequest, $reason, $message): void {
            $scope->setTag('audit_request', (string) $auditRequest->uuid);
            $scope->setExtra('reason', $reason);
            \Sentry\captureMessage($message, Severity::error());
        });

        $auditRequest->appendPipelineLog('undelivered', 'Report kept but never delivered; operator alerted');

        // This runs from a job's failed() hook or after a committed close: a
        // transport failure must not escape either.
        try {
            $this->notifyAdmin($auditRequest);
        } catch (Throwable $e) {
            $auditRequest->appendPipelineLog('mail_failed', "Admin email could not be sent: {$e->getMessage()}");
        }
    }

    public function markFailed(AuditRequest $auditRequest, string $reason): void
    {
        $kept = DB::transaction(fn (): ?AuditRequestStatus => $this->keepDeliveredReport($auditRequest, $reason));

        if ($kept !== null) {
            $this->alertIfUndelivered($auditRequest, $kept, $reason);

            return;
        }

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

        // The partner who referred this visitor is their contact, so they see
        // every operator update about the request -- re-resolved each time,
        // so a partner whose plan lapsed stops receiving them.
        $referrerEmail = $this->partnerContacts->forCode($auditRequest->meta['referral_code'] ?? null)?->email;

        if ($adminEmail) {
            $this->auditMailer->send(
                new NewAuditRequestAdminNotification($auditRequest),
                $adminEmail,
                $auditRequest,
                cc: $referrerEmail === null ? [] : [$referrerEmail],
            );
        } elseif ($referrerEmail !== null) {
            $this->auditMailer->send(new NewAuditRequestAdminNotification($auditRequest), $referrerEmail, $auditRequest);
        }
    }
}
