<?php

namespace App\Providers;

use App\Health\Checks\AuditPipelineFailureRateCheck;
use App\Health\Checks\MailFailureRateCheck;
use App\Health\Checks\OldestPendingAuditCheck;
use App\Health\Checks\ScannerDegradationCheck;
use Illuminate\Support\ServiceProvider;
use Spatie\Health\Checks\Checks\CacheCheck;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\HorizonCheck;
use Spatie\Health\Checks\Checks\QueueCheck;
use Spatie\Health\Checks\Checks\RedisCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Checks\Checks\UsedDiskSpaceCheck;
use Spatie\Health\Facades\Health;

class HealthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Health::checks([
            DatabaseCheck::new(),
            RedisCheck::new(),
            CacheCheck::new(),
            HorizonCheck::new(),

            QueueCheck::new()
                ->onQueue((string) config('audit.queue'))
                ->failWhenHealthJobTakesLongerThanMinutes(
                    (int) config('health.flexpick.queue_heartbeat_minutes')
                ),

            ScheduleCheck::new()
                ->heartbeatMaxAgeInMinutes(
                    (int) config('health.flexpick.schedule_heartbeat_minutes')
                ),

            UsedDiskSpaceCheck::new()
                ->failWhenUsedSpaceIsAbovePercentage(
                    (int) config('health.flexpick.disk_fail_percent')
                ),

            OldestPendingAuditCheck::new(),
            AuditPipelineFailureRateCheck::new(),
            MailFailureRateCheck::new(),
            ScannerDegradationCheck::new(),
        ]);
    }
}
