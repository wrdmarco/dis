<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('queue:prune-failed --hours=168')->dailyAt('03:30');
Schedule::command('dis:cleanup-wallboard-media')->dailyAt('03:20')->withoutOverlapping();
Schedule::command('dis:backfill-wallboard-media-thumbnails')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(10);
Schedule::command('dis:refresh-wallboard-content')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(10);
Schedule::command('dis:refresh-knmi-forecast')
    ->cron('17 */3 * * *')
    ->onOneServer()
    ->withoutOverlapping(180);
Schedule::command('dis:refresh-knmi-precipitation-outlook')
    // The four-minute offset lets the current five-minute radar release arrive.
    // The separate seamless probability feed is refreshed when available, but
    // can no longer block activation of a valid radar release.
    ->cron('4-59/5 * * * *')
    ->onOneServer()
    ->withoutOverlapping(10);
Schedule::command('dis:refresh-eumetsat-lightning')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(10);
Schedule::command('dis:backfill-incident-locations')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(10)
    ->when(static fn (): bool => (bool) config('dis.incident_location.enabled', true));
Schedule::command('dis:backfill-pilot-report-drone-snapshots')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(10);
Schedule::command('dis:prune-operational-data')->dailyAt('03:45');
Schedule::command('dis:send-certification-expiry-mails')->dailyAt('08:00');
Schedule::command('dis:apply-vacation-statuses')->everyFiveMinutes();
Schedule::command('dis:apply-availability-schedule-statuses')->everyMinute();
Schedule::command('dis:send-device-presence-ping')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('dis:reconcile-push-queue-work-items')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(2);
Schedule::command('dis:send-scheduled-test-alert')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(10);
Schedule::command('dis:flush-dispatch-push-outbox')
    ->everySecond()
    ->withoutOverlapping(1);
Schedule::command('dis:run-scheduled-backup')
    ->everyMinute()
    ->withoutOverlapping(30)
    ->runInBackground();
Schedule::command('dis:self-check')->everyFiveMinutes();
