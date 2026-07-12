<?php

namespace App\Services;

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
use App\Services\AuditReport\AuditEntitlementService;
use App\Services\AuditReport\AuditFunnelRecorder;
use App\Services\AuditReport\RepositoryCloner;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class AuditRequestService
{
    public function __construct(
        private AuditEntitlementService $entitlements,
        private RepositoryCloner $cloner,
        private AuditFunnelRecorder $funnel,
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
            'marketing_consent' => $consented,
            'consented_at' => $consented ? now() : null,
            'meta' => $meta,
        ]);

        $this->funnel->record(AuditFunnelRecorder::STAGE_SUBMITTED, $auditRequest);

        Mail::to($auditRequest->email)->send(new AuditVerifyEmail($auditRequest, $this->verificationUrl($auditRequest)));

        return $auditRequest;
    }

    public function verificationUrl(AuditRequest $auditRequest): string
    {
        return URL::temporarySignedRoute(
            'audit-requests.verify',
            now()->addHours((int) config('audit.verification_link_hours')),
            ['auditRequest' => $auditRequest->uuid],
        );
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
        } catch (AuditNotAnalyzableException) {
            $auditRequest->update(['status' => AuditRequestStatus::AWAITING_ACCESS->value]);
            Mail::to($auditRequest->email)->send(new AuditRepoAccessNeeded($auditRequest));
            $this->notifyAdmin($auditRequest);

            return;
        }

        if (! $this->entitlements->hasFreeRun($auditRequest->email)) {
            $auditRequest->update(['status' => AuditRequestStatus::AWAITING_PAYMENT->value]);
            $this->funnel->record(AuditFunnelRecorder::STAGE_AWAITING_PAYMENT, $auditRequest);
            Mail::to($auditRequest->email)->send(new AuditQuotaExhausted($auditRequest));
            $this->notifyAdmin($auditRequest);

            return;
        }

        $this->entitlements->consumeFreeRun($auditRequest);
        $auditRequest->update(['status' => AuditRequestStatus::QUEUED->value]);
        GenerateAuditReport::dispatch($auditRequest);
        $this->funnel->record(AuditFunnelRecorder::STAGE_QUEUED, $auditRequest);
        Mail::to($auditRequest->email)->send(new AuditRequestReceived($auditRequest));
        $this->notifyAdmin($auditRequest);
    }

    public function markNeedsFollowup(AuditRequest $auditRequest, string $reason): void
    {
        $auditRequest->update([
            'status' => AuditRequestStatus::NEEDS_FOLLOWUP->value,
            'failure_reason' => $reason,
        ]);

        Mail::to($auditRequest->email)->send(new AuditRepoAccessNeeded($auditRequest));
    }

    public function markFailed(AuditRequest $auditRequest, string $reason): void
    {
        $auditRequest->update([
            'status' => AuditRequestStatus::FAILED->value,
            'failure_reason' => $reason,
        ]);

        $this->funnel->record(AuditFunnelRecorder::STAGE_FAILED, $auditRequest, ['reason' => $reason]);

        Mail::to($auditRequest->email)->send(new AuditRequestFailed($auditRequest));
        $this->notifyAdmin($auditRequest);
    }

    private function notifyAdmin(AuditRequest $auditRequest): void
    {
        $adminEmail = config('audit.admin_email');

        if ($adminEmail) {
            Mail::to($adminEmail)->send(new NewAuditRequestAdminNotification($auditRequest));
        }
    }
}
