<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('queue:prune-failed --hours=168')->dailyAt('03:30');
Schedule::command('dis:cleanup-wallboard-media')->dailyAt('03:20')->withoutOverlapping();
Schedule::command('dis:refresh-wallboard-content')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(10);
Schedule::command('dis:prune-operational-data')->dailyAt('03:45');
Schedule::command('dis:send-certification-expiry-mails')->dailyAt('08:00');
Schedule::command('dis:apply-vacation-statuses')->everyFiveMinutes();
Schedule::command('dis:apply-availability-schedule-statuses')->everyMinute();
Schedule::command('dis:send-device-presence-ping')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('dis:send-scheduled-test-alert')->everyMinute();
Schedule::command('dis:flush-dispatch-push-outbox')
    ->everyTenSeconds()
    ->withoutOverlapping(1);
Schedule::command('dis:run-scheduled-backup')
    ->everyMinute()
    ->withoutOverlapping(30)
    ->runInBackground();
Schedule::command('dis:self-check')->everyFiveMinutes();
