<?php

namespace App\Filament\Dashboard\Pages;

use App\Constants\AuditFunding;
use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Jobs\GenerateAuditReport;
use App\Listeners\Order\HandleAuditTierOrder;
use App\Models\AuditRequest;
use App\Models\AuditSchedule;
use App\Models\UserParameter;
use App\Services\AuditReport\AuditEntitlementService;
use App\Services\AuditReport\TierQuota;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

class AuditReports extends Page
{
    protected string $view = 'filament.dashboard.pages.audit-reports';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static string|\UnitEnum|null $navigationGroup = 'Audits';

    protected static ?int $navigationSort = 1;

    public ?string $repoUrl = null;

    public string $tier = '';

    public function mount(): void
    {
        $this->tier = $this->defaultTier()->value;
    }

    /**
     * Preselect the best tier the user can actually run, so the common case is
     * one click. Diagnostic is the floor -- it is always offered, even at zero,
     * because its exhausted state is the free-quota upsell.
     */
    private function defaultTier(): AuditTier
    {
        $user = auth()->user();
        $tenant = Filament::getTenant();
        $entitlements = app(AuditEntitlementService::class);

        foreach ([AuditTier::DEEP_AI, AuditTier::EXPERT, AuditTier::DIAGNOSTIC] as $tier) {
            if ($entitlements->remainingRuns($user, $tenant, $tier) > 0) {
                return $tier;
            }
        }

        return AuditTier::DIAGNOSTIC;
    }

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
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        // Share the one gate with the audit widgets and the Audits resource.
        // This page previously demanded a finished report, which hid it from
        // users whose first audit was still running.
        return app(AuditEntitlementService::class)->hasAuditAccess($user, Filament::getTenant());
    }

    public function launchAudit(?string $repoUrl = null, ?string $tier = null): void
    {
        $repoUrl ??= $this->repoUrl;
        $user = auth()->user();
        $tenant = Filament::getTenant();
        $entitlements = app(AuditEntitlementService::class);

        // $tier arrives from a client-controlled Livewire property, so the
        // rendered UI is a hint and this method is the gate.
        $selected = AuditTier::tryFrom($tier ?? $this->tier);

        if ($selected === null) {
            Notification::make()->title(__('Choose an audit type'))->danger()->send();

            return;
        }

        if ($repoUrl === null || ! str_starts_with($repoUrl, 'http')) {
            Notification::make()->title(__('Enter a valid repository URL'))->danger()->send();

            return;
        }

        $quota = $entitlements->quotaFor($user, $tenant, $selected);

        if (! $quota->hasRuns()) {
            if ($quota->purchasable()) {
                $this->purchase($repoUrl, $selected);

                return;
            }

            Notification::make()
                ->title(__('No :tier runs left', ['tier' => $quota->label]))
                ->body(__('Upgrade your plan to run more audits.'))
                ->warning()
                ->send();

            return;
        }

        $auditRequest = AuditRequest::create([
            'name' => $user->name,
            'email' => $user->email,
            'repo_url' => $repoUrl,
            'status' => AuditRequestStatus::QUEUED->value,
            'email_verified_at' => now(),
            'source' => 'dashboard',
            'tier' => $selected->value,
            'funding' => $quota->isLifetime
                ? AuditFunding::FREE->value
                : AuditFunding::ALLOWANCE->value,
            'user_id' => $user->id,
        ]);

        // An allowance run is metered simply by existing at its tier. A free
        // run has to be flagged on the request to be deducted.
        if ($quota->isLifetime) {
            $entitlements->consumeFreeRun($auditRequest);
        }

        GenerateAuditReport::dispatch($auditRequest);
        $this->repoUrl = null;

        Notification::make()
            ->title(__('Audit started'))
            ->body(__('You\'ll get an email when the report is ready.'))
            ->success()
            ->send();
    }

    /**
     * Capture the repository and tier now, pay for them next.
     *
     * The request is created up front so the choice survives the round trip
     * through checkout; HandleAuditTierOrder finds it by intent and runs it.
     * It is funded as a purchase from the start, so a customer who abandons
     * checkout is never charged a plan credit for it.
     */
    private function purchase(string $repoUrl, AuditTier $tier): void
    {
        $user = auth()->user();
        $slug = collect((array) config('pricing.tiers'))
            ->search(fn (array $definition): bool => ($definition['tier'] ?? null) === $tier->value);

        if ($slug === false) {
            Notification::make()->title(__('No :tier runs left', ['tier' => $tier->label()]))->danger()->send();

            return;
        }

        $auditRequest = AuditRequest::create([
            'name' => $user->name,
            'email' => $user->email,
            'repo_url' => $repoUrl,
            'status' => AuditRequestStatus::AWAITING_PAYMENT->value,
            'email_verified_at' => now(),
            'source' => 'dashboard',
            'tier' => $tier->value,
            'funding' => AuditFunding::PURCHASE->value,
            'user_id' => $user->id,
        ]);

        UserParameter::updateOrCreate(
            ['user_id' => $user->id, 'name' => HandleAuditTierOrder::INTENT_PARAM],
            ['value' => $auditRequest->uuid],
        );

        $this->redirect(route('buy.product', ['productSlug' => $slug]));
    }

    public function setSchedule(string $repoUrl, string $frequency, ?string $tier = null): void
    {
        $user = auth()->user();
        $tenant = Filament::getTenant();
        $entitlements = app(AuditEntitlementService::class);
        $selected = AuditTier::tryFrom($tier ?? AuditTier::DIAGNOSTIC->value) ?? AuditTier::DIAGNOSTIC;

        if ($tenant === null || ! in_array($frequency, ['off', 'weekly', 'monthly'], true)) {
            return;
        }

        $repoUrl = rtrim($repoUrl, '/');

        if ($frequency === 'off') {
            AuditSchedule::query()->where('user_id', $user->id)->where('repo_url', $repoUrl)->delete();
            Notification::make()->title(__('Scheduled audits turned off'))->success()->send();

            return;
        }

        // $tier arrives from a client-controlled Livewire method argument, so
        // the blade's tier <select> (which never offers a lifetime tier) is
        // a hint and this method is the gate -- the same rule already
        // applied to launchAudit(). A schedule is a subscriber feature; the
        // one-off free-run quota must never back a recurring run.
        if ($entitlements->quotaFor($user, $tenant, $selected)->isLifetime) {
            Notification::make()
                ->title(__('Choose an audit type'))
                ->body(__('Scheduled audits cannot run on the free-run tier.'))
                ->danger()
                ->send();

            return;
        }

        AuditSchedule::updateOrCreate(
            ['user_id' => $user->id, 'repo_url' => $repoUrl],
            ['tenant_id' => $tenant->id, 'frequency' => $frequency, 'tier' => $selected->value],
        );

        Notification::make()->title(__('Audits scheduled :frequency', ['frequency' => __($frequency)]))->success()->send();
    }

    public function getViewData(): array
    {
        $user = auth()->user();
        $tenant = Filament::getTenant();
        $entitlements = app(AuditEntitlementService::class);

        $reports = $user->auditReports()
            ->with('auditRequest')
            ->latest()
            ->get();

        $quotas = $entitlements->quotas($user, $tenant);

        return [
            'reports' => $reports,
            'quotas' => $quotas,
            // Any tier can start a run: one from quota, the rest by purchase.
            'canRun' => collect($quotas)->contains(
                fn (TierQuota $quota): bool => $quota->hasRuns() || $quota->purchasable(),
            ),
            'schedules' => AuditSchedule::query()->where('user_id', $user->id)
                ->get()
                ->keyBy(fn (AuditSchedule $s): string => rtrim($s->repo_url, '/')),
            'repoGroups' => $reports
                ->groupBy(fn ($report) => rtrim((string) $report->auditRequest->repo_url, '/'))
                ->map(fn ($group) => [
                    'reports' => $group,
                    'scores' => $group->reverse()->values()->map(fn ($r) => (int) data_get($r->payload, 'scores.overall', 0)),
                ]),
            'deltas' => $reports
                ->groupBy(fn ($report) => rtrim((string) $report->auditRequest->repo_url, '/'))
                ->map(function ($group): ?int {
                    // $reports is ordered latest-first, so index 0 is current.
                    $scores = $group
                        ->map(fn ($r): ?int => data_get($r->payload, 'scores.overall'))
                        ->filter(fn (?int $s): bool => $s !== null)
                        ->values();

                    if ($scores->count() < 2) {
                        return null;
                    }

                    return $scores->get(0) - $scores->get(1);
                })
                ->all(),
        ];
    }
}
