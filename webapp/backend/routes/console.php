<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('queue:prune-failed --hours=168')->dailyAt('03:30');
Schedule::command('dis:prune-operational-data')->dailyAt('03:45');
Schedule::command('dis:send-certification-expiry-mails')->dailyAt('08:00');
Schedule::command('dis:send-scheduled-test-alert')->everyMinute();
Schedule::command('dis:self-check')->everyFiveMinutes();
