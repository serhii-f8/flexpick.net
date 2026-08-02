<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Schedule::command('app:generate-sitemap')->everyOddHour();

Schedule::command('app:metrics-beat')->dailyAt('00:01');

Schedule::command('app:local-subscription-expiring-soon-reminder')->dailyAt('00:01');

Schedule::command('app:cleanup-local-subscription-statuses')->hourly();

Schedule::command('app:sync-seat-based-subscription-quantities')->hourly();

Schedule::command('app:purge-unverified-audit-requests')->dailyAt('02:10');

Schedule::command('app:send-audit-verification-reminders')->dailyAt('09:00');

Schedule::command('app:send-audit-unlock-reminders')->dailyAt('09:05');

Schedule::command('app:run-scheduled-audits')->dailyAt('06:00')->withoutOverlapping()->onOneServer();

// withoutOverlapping: the result store writes a batch row-by-row without a
// transaction, so an overrunning check run would let the next tick read a
// partial batch — a failing check could silently vanish from /health and from
// alerting. onOneServer is deliberately omitted: this deploys to a single
// server, so it would only add a lock dependency for no benefit.
Schedule::command(\Spatie\Health\Commands\RunHealthChecksCommand::class)->everyFiveMinutes()->withoutOverlapping();

Schedule::command('app:health-alerts')->everyFiveMinutes()->withoutOverlapping();

// Must be last: it records that the scheduler itself ran (spec §18.3 O2).
Schedule::command(\Spatie\Health\Commands\ScheduleCheckHeartbeatCommand::class)->everyMinute();
