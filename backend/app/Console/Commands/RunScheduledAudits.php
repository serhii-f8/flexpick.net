<?php

namespace App\Console\Commands;

use App\Constants\AuditFunding;
use App\Constants\AuditRequestStatus;
use App\Jobs\GenerateAuditReport;
use App\Models\AuditRequest;
use App\Models\AuditSchedule;
use App\Services\AuditReport\AuditEntitlementService;
use Illuminate\Console\Command;

class RunScheduledAudits extends Command
{
    protected $signature = 'app:run-scheduled-audits';

    protected $description = 'Dispatch dashboard audits for schedules that are due';

    public function handle(AuditEntitlementService $entitlements): int
    {
        $due = AuditSchedule::query()->with(['user', 'tenant'])->get()
            ->filter(fn (AuditSchedule $schedule) => $schedule->last_run_at === null
                || $schedule->last_run_at <= ($schedule->frequency === 'weekly' ? now()->subWeek() : now()->subMonth()));

        $started = 0;

        foreach ($due as $schedule) {
            $tier = $schedule->tier;

            if ($entitlements->remainingRuns($schedule->user, $schedule->tenant, $tier) < 1) {
                // Never downgrade to a cheaper tier and never auto-charge:
                // both deliver something the customer did not agree to at
                // schedule time. Logged, because a schedule that quietly
                // stops firing is otherwise invisible.
                $this->warn("Skipping {$schedule->repo_url}: no {$tier->value} runs left for {$schedule->user->email}");

                continue;
            }

            $auditRequest = AuditRequest::create([
                'name' => $schedule->user->name,
                'email' => $schedule->user->email,
                'repo_url' => $schedule->repo_url,
                'status' => AuditRequestStatus::QUEUED->value,
                'email_verified_at' => now(),
                'source' => 'dashboard',
                'tier' => $tier->value,
                'funding' => AuditFunding::ALLOWANCE->value,
                'user_id' => $schedule->user->id,
            ]);

            GenerateAuditReport::dispatch($auditRequest);
            $schedule->update(['last_run_at' => now()]);
            $started++;
        }

        $this->info("Started {$started} scheduled audits.");

        return self::SUCCESS;
    }
}
