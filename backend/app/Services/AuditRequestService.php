<?php

namespace App\Services;

use App\Constants\AuditRequestStatus;
use App\Jobs\GenerateAuditReport;
use App\Mail\Audit\AuditRepoAccessNeeded;
use App\Mail\Audit\AuditRequestFailed;
use App\Mail\Audit\AuditRequestReceived;
use App\Mail\Audit\NewAuditRequestAdminNotification;
use App\Models\AuditRequest;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class AuditRequestService
{
    public function submit(array $data, array $meta = []): AuditRequest
    {
        $recentDuplicate = AuditRequest::query()
            ->where('email', $data['email'])
            ->where('created_at', '>=', now()->subMinutes(10))
            ->exists();

        if ($recentDuplicate) {
            throw new TooManyRequestsHttpException(600, __('We already received a request from this email. Give us a few minutes.'));
        }

        $auditRequest = AuditRequest::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'repo_url' => $data['repo_url'] ?? null,
            'message' => $data['message'] ?? null,
            'status' => AuditRequestStatus::NEW->value,
            'meta' => $meta,
        ]);

        Mail::to($auditRequest->email)->send(new AuditRequestReceived($auditRequest));
        $this->notifyAdmin($auditRequest);

        if ($auditRequest->repo_url !== null) {
            $auditRequest->update(['status' => AuditRequestStatus::QUEUED->value]);
            GenerateAuditReport::dispatch($auditRequest);
        } else {
            $this->markNeedsFollowup($auditRequest, 'No repository URL provided');
        }

        return $auditRequest;
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
