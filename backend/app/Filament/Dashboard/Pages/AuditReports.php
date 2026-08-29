<?php

namespace App\Filament\Dashboard\Pages;

use App\Constants\AuditFunding;
use App\Constants\AuditRequestStatus;
use App\Constants\AuditTier;
use App\Jobs\GenerateAuditReport;
use App\Listeners\Order\HandleAuditTierOrder;
use App\Models\AuditReport;
use App\Models\AuditRequest;
use App\Models\AuditSchedule;
use App\Models\AuditScheduleRun;
use App\Models\UserParameter;
use App\Services\AuditReport\AuditEntitlementService;
use App\Services\AuditReport\ScheduleOccurrenceProjector;
use App\Services\AuditReport\ScoreChartBuilder;
use App\Services\AuditReport\TierQuota;
use App\Services\GitHub\GitHubApiClient;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;

class AuditReports extends Page
{
    protected string $view = 'filament.dashboard.pages.audit-reports';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static string|\UnitEnum|null $navigationGroup = 'Audits';

    protected static ?int $navigationSort = 1;

    public ?string $repoUrl = null;

    public string $tier = '';

    public ?string $branch = null;

    /** @var array<string, list<string>> */
    public array $branchesByRepo = [];

    public string $calendarMonth = '';

    public function mount(): void
    {
        $this->tier = $this->defaultTier()->value;
        $this->calendarMonth = now()->format('Y-m');
    }

    public function updatedRepoUrl(): void
    {
        if ($this->repoUrl !== null && str_starts_with($this->repoUrl, 'http')) {
            $this->loadBranches($this->repoUrl);
        }
    }

    public function loadBranches(string $repoUrl): void
    {
        $key = rtrim($repoUrl, '/');

        if (array_key_exists($key, $this->branchesByRepo)) {
            return;
        }

        if (! $this->userMayLookUpBranchesFor($key)) {
            return;
        }

        $this->branchesByRepo[$key] = app(GitHubApiClient::class)->listBranches($repoUrl);
    }

    /**
     * The branch lookup runs on the shared AUDIT_GITHUB_TOKEN PAT, which is a
     * read-only collaborator on every customer's private repos. Left open,
     * this public Livewire method is a free, instant, repeatable and unlogged
     * oracle for "does owner/repo exist and can the PAT see it" -- branches
     * back means yes, an empty array means no. That is strictly worse than
     * probing through launchAudit(), which at least costs a credit and leaves
     * an auditable AuditRequest row.
     *
     * So a user may only look up a repo they already have a claim to: the one
     * they are about to submit in their own launch form, or one carried by
     * their own audit history or schedules. A refusal leaves the key unset, so
     * the blade renders its "not yet fetched" branch exactly as before any
     * lookup ran.
     */
    private function userMayLookUpBranchesFor(string $repoUrl): bool
    {
        if ($this->repoUrl !== null && rtrim($this->repoUrl, '/') === $repoUrl) {
            return true;
        }

        $userId = auth()->id();
        // Schedules are stored trimmed; requests keep whatever was submitted.
        $variants = [$repoUrl, $repoUrl.'/'];

        return AuditRequest::query()->where('user_id', $userId)->whereIn('repo_url', $variants)->exists()
            || AuditSchedule::query()->where('user_id', $userId)->whereIn('repo_url', $variants)->exists();
    }

    public function prevCalendarMonth(): void
    {
        $this->calendarMonth = $this->calendarMonthStart()->subMonthNoOverflow()->format('Y-m');
    }

    public function nextCalendarMonth(): void
    {
        $this->calendarMonth = $this->calendarMonthStart()->addMonthNoOverflow()->format('Y-m');
    }

    private function calendarMonthStart(): Carbon
    {
        return Carbon::createFromFormat('Y-m', $this->calendarMonth)->startOfMonth();
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

    public function launchAudit(?string $repoUrl = null, ?string $tier = null, ?string $branch = null): void
    {
        // The launch form's branch belongs to the launch form's repo, so only
        // the form's own submission (no explicit repo) may inherit it. A
        // caller that names a repo -- the per-repo Re-run button -- names its
        // own branch or gets none: auditing repo B against repo A's selected
        // branch is the wrong revision at best, and a clone failure after the
        // credit is already spent at worst.
        if ($repoUrl === null) {
            $repoUrl = $this->repoUrl;
            $branch ??= $this->branch;
        }

        $branch = ($branch !== null && $branch !== '') ? $branch : null;
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
                $this->purchase($repoUrl, $selected, $branch);

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
            'branch' => $branch,
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
        $this->branch = null;

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
    private function purchase(string $repoUrl, AuditTier $tier, ?string $branch = null): void
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
            'branch' => $branch,
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

        $existing = AuditSchedule::query()->where('user_id', $user->id)->where('repo_url', $repoUrl)->first();

        AuditSchedule::updateOrCreate(
            ['user_id' => $user->id, 'repo_url' => $repoUrl],
            [
                'tenant_id' => $tenant->id,
                'frequency' => $frequency,
                'tier' => $selected->value,
                'day_of_week' => $frequency === 'weekly' ? ($existing->day_of_week ?? now()->dayOfWeek) : null,
                'day_of_month' => $frequency === 'monthly' ? ($existing->day_of_month ?? now()->day) : null,
            ],
        );

        Notification::make()->title(__('Audits scheduled :frequency', ['frequency' => __($frequency)]))->success()->send();
    }

    public function setScheduleDay(string $repoUrl, int $dayOfWeek): void
    {
        $user = auth()->user();
        $repoUrl = rtrim($repoUrl, '/');

        AuditSchedule::query()
            ->where('user_id', $user->id)
            ->where('repo_url', $repoUrl)
            ->where('frequency', 'weekly')
            ->update(['day_of_week' => max(0, min(6, $dayOfWeek))]);
    }

    public function setScheduleMonthDay(string $repoUrl, int $dayOfMonth): void
    {
        $user = auth()->user();
        $repoUrl = rtrim($repoUrl, '/');

        AuditSchedule::query()
            ->where('user_id', $user->id)
            ->where('repo_url', $repoUrl)
            ->where('frequency', 'monthly')
            ->update(['day_of_month' => max(1, min(31, $dayOfMonth))]);
    }

    public function setScheduleBranch(string $repoUrl, ?string $branch): void
    {
        $user = auth()->user();
        $repoUrl = rtrim($repoUrl, '/');

        AuditSchedule::query()
            ->where('user_id', $user->id)
            ->where('repo_url', $repoUrl)
            ->update(['branch' => ($branch !== null && $branch !== '') ? $branch : null]);
    }

    public function getViewData(): array
    {
        $user = auth()->user();
        $tenant = Filament::getTenant();
        $entitlements = app(AuditEntitlementService::class);
        $chartBuilder = app(ScoreChartBuilder::class);
        $projector = app(ScheduleOccurrenceProjector::class);

        $reports = $user->auditReports()
            ->with('auditRequest')
            ->latest()
            ->get();

        $quotas = $entitlements->quotas($user, $tenant);

        $schedules = AuditSchedule::query()->where('user_id', $user->id)
            ->get()
            ->keyBy(fn (AuditSchedule $s): string => rtrim($s->repo_url, '/'));

        $repoGroups = $reports
            ->groupBy(fn ($report) => rtrim((string) $report->auditRequest->repo_url, '/'))
            ->map(function ($group) use ($chartBuilder) {
                $ordered = $group->reverse()->values();
                $scores = $ordered->map(fn ($r) => (int) data_get($r->payload, 'scores.overall', 0));
                $dates = $ordered->map(fn (AuditReport $r) => $r->created_at);

                return [
                    'reports' => $group,
                    'scores' => $scores,
                    'chartPoints' => $chartBuilder->build($scores, $dates),
                ];
            });

        $calendarMonthStart = $this->calendarMonthStart();
        $calendarMonthEnd = $calendarMonthStart->copy()->endOfMonth();

        $calendarByRepo = $schedules->mapWithKeys(function (AuditSchedule $schedule) use ($projector, $calendarMonthStart, $calendarMonthEnd) {
            $past = AuditScheduleRun::query()
                ->where('audit_schedule_id', $schedule->id)
                ->whereBetween('scheduled_for', [$calendarMonthStart->toDateString(), $calendarMonthEnd->toDateString()])
                ->get()
                ->keyBy(fn (AuditScheduleRun $run) => $run->scheduled_for->toDateString());

            $upcoming = $projector->upcomingDatesInMonth($schedule, $calendarMonthStart);

            return [rtrim($schedule->repo_url, '/') => ['past' => $past, 'upcoming' => $upcoming]];
        });

        return [
            'reports' => $reports,
            'quotas' => $quotas,
            // Any tier can start a run: one from quota, the rest by purchase.
            'canRun' => collect($quotas)->contains(
                fn (TierQuota $quota): bool => $quota->hasRuns() || $quota->purchasable(),
            ),
            'schedules' => $schedules,
            'repoGroups' => $repoGroups,
            // Named calendarMonthStart, not calendarMonth: Livewire injects
            // this component's public properties into the view AFTER
            // getViewData() runs, so a 'calendarMonth' view-data key would be
            // silently overwritten by the public string $calendarMonth
            // property (format 'Y-m') that backs prev/nextCalendarMonth().
            'calendarMonthStart' => $calendarMonthStart,
            'calendarByRepo' => $calendarByRepo,
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
