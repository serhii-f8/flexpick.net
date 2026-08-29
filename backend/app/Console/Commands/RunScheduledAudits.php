<?php

namespace App\Console\Commands;

use App\Constants\AuditFunding;
use App\Constants\AuditRequestStatus;
use App\Jobs\GenerateAuditReport;
use App\Models\AuditRequest;
use App\Models\AuditSchedule;
use App\Models\AuditScheduleRun;
use App\Services\AuditReport\AuditEntitlementService;
use App\Services\AuditReport\ScheduledAuditChangeChecker;
use Illuminate\Console\Command;

class RunScheduledAudits extends Command
{
    protected $signature = 'app:run-scheduled-audits';

    protected $description = 'Dispatch dashboard audits for schedules that are due';

    public function handle(AuditEntitlementService $entitlements, ScheduledAuditChangeChecker $changeChecker): int
    {
        $due = AuditSchedule::query()->with(['user', 'tenant'])->get()->filter($this->isDue(...));

        $started = 0;

        foreach ($due as $schedule) {
            $tier = $schedule->tier;
            $quota = $entitlements->quotaFor($schedule->user, $schedule->tenant, $tier);

            if (! $quota->hasRuns()) {
                // Never downgrade to a cheaper tier and never auto-charge:
                // both deliver something the customer did not agree to at
                // schedule time. Logged, because a schedule that quietly
                // stops firing is otherwise invisible.
                $this->warn("Skipping {$schedule->repo_url}: no {$tier->value} runs left for {$schedule->user->email}");
                $this->recordRun($schedule, 'skipped', 'no_quota');

                continue;
            }

            $check = $changeChecker->check($schedule);

            if (! $check->shouldRun) {
                $this->info("Skipping {$schedule->repo_url}: no changes since the last audit");
                $this->recordRun($schedule, 'skipped', 'no_changes', commitSha: $check->sha);

                continue;
            }

            $auditRequest = AuditRequest::create([
                'name' => $schedule->user->name,
                'email' => $schedule->user->email,
                'repo_url' => $schedule->repo_url,
                'branch' => $schedule->branch,
                'status' => AuditRequestStatus::QUEUED->value,
                'email_verified_at' => now(),
                'source' => 'dashboard',
                'tier' => $tier->value,
                'funding' => $quota->isLifetime
                    ? AuditFunding::FREE->value
                    : AuditFunding::ALLOWANCE->value,
                'user_id' => $schedule->user->id,
            ]);

            // An allowance run is metered simply by existing at its tier. A
            // free run has to be flagged on the request to be deducted --
            // setSchedule() already refuses to create a lifetime-tier
            // schedule, but a pre-existing schedule row (created before that
            // guard existed) could still reach here at a lifetime tier.
            if ($quota->isLifetime) {
                $entitlements->consumeFreeRun($auditRequest);
            }

            GenerateAuditReport::dispatch($auditRequest);
            $schedule->update(['last_run_at' => now(), 'last_commit_sha' => $check->sha]);
            $this->recordRun($schedule, 'completed', auditRequestId: $auditRequest->id, commitSha: $check->sha);
            $started++;
        }

        $this->info("Started {$started} scheduled audits.");

        return self::SUCCESS;
    }

    /**
     * Weekly schedules with a chosen day_of_week are due only on that
     * weekday. A weekly schedule created before day_of_week existed (still
     * null) falls back to the original last_run_at + 1 week check, so no
     * pre-existing row silently stops firing.
     */
    private function isDue(AuditSchedule $schedule): bool
    {
        if ($schedule->last_run_at === null) {
            return true;
        }

        if ($schedule->frequency === 'weekly' && $schedule->day_of_week !== null) {
            return now()->dayOfWeek === $schedule->day_of_week && $schedule->last_run_at->isBefore(now()->startOfDay());
        }

        return $schedule->last_run_at <= ($schedule->frequency === 'weekly' ? now()->subWeek() : now()->subMonth());
    }

    private function recordRun(
        AuditSchedule $schedule,
        string $status,
        ?string $reason = null,
        ?int $auditRequestId = null,
        ?string $commitSha = null,
    ): void {
        // Upsert, not insert: this command fires daily, and a schedule that
        // keeps being skipped never advances last_run_at, so a monthly (or
        // legacy weekly) schedule stays due every day once its cadence has
        // elapsed. Inserting each time grew the table without bound and drew
        // a month of duplicate dots on the calendar this table feeds. One row
        // per calendar day, last outcome wins.
        AuditScheduleRun::updateOrCreate(
            [
                'audit_schedule_id' => $schedule->id,
                'scheduled_for' => now()->toDateString(),
            ],
            [
                'status' => $status,
                'reason' => $reason,
                'audit_request_id' => $auditRequestId,
                'commit_sha' => $commitSha,
            ],
        );
    }
}
