<?php

namespace App\Filament\Dashboard\Pages;

use App\Constants\AuditRequestStatus;
use App\Jobs\GenerateAuditReport;
use App\Models\AuditRequest;
use App\Services\AuditReport\AuditEntitlementService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

class AuditReports extends Page
{
    protected string $view = 'filament.dashboard.pages.audit-reports';

    public ?string $repoUrl = null;

    public function getHeading(): string|Htmlable
    {
        return __('Audit Reports');
    }

    public function getTitle(): string|Htmlable
    {
        return __('Audit Reports');
    }

    public static function shouldRegisterNavigation(): bool
    {
        if (! auth()->check()) {
            return false;
        }

        if (auth()->user()->auditReports()->exists()) {
            return true;
        }

        $tenant = Filament::getTenant();

        return $tenant !== null && app(AuditEntitlementService::class)->subscriptionAllowance($tenant) > 0;
    }

    public function launchAudit(?string $repoUrl = null): void
    {
        $repoUrl ??= $this->repoUrl;
        $user = auth()->user();
        $entitlements = app(AuditEntitlementService::class);

        if ($repoUrl === null || ! str_starts_with($repoUrl, 'http')) {
            Notification::make()->title(__('Enter a valid repository URL'))->danger()->send();

            return;
        }

        $tenant = Filament::getTenant();
        if ($tenant === null || $entitlements->remainingDashboardRuns($user, $tenant) < 1) {
            Notification::make()->title(__('No analyses left this month'))->body(__('Upgrade your plan to run more audits.'))->warning()->send();

            return;
        }

        $auditRequest = AuditRequest::create([
            'name' => $user->name,
            'email' => $user->email,
            'repo_url' => $repoUrl,
            'status' => AuditRequestStatus::QUEUED->value,
            'email_verified_at' => now(),
            'source' => 'dashboard',
            'user_id' => $user->id,
        ]);

        GenerateAuditReport::dispatch($auditRequest);
        $this->repoUrl = null;

        Notification::make()->title(__('Audit started'))->body(__('You\'ll get an email when the report is ready.'))->success()->send();
    }

    public function getViewData(): array
    {
        $user = auth()->user();
        $tenant = Filament::getTenant();
        $entitlements = app(AuditEntitlementService::class);

        $reports = $user->auditReports()->with('auditRequest')->latest()->get();

        return [
            'reports' => $reports,
            'allowance' => $tenant ? $entitlements->subscriptionAllowance($tenant) : 0,
            'remainingRuns' => $tenant ? $entitlements->remainingDashboardRuns($user, $tenant) : 0,
            'repoGroups' => $reports
                ->groupBy(fn ($report) => rtrim((string) $report->auditRequest->repo_url, '/'))
                ->map(fn ($group) => [
                    'reports' => $group,
                    'scores' => $group->reverse()->values()->map(fn ($r) => (int) data_get($r->payload, 'scores.overall', 0)),
                ]),
        ];
    }
}
