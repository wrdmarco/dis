<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('queue:prune-failed --hours=168')->dailyAt('03:30');
Schedule::command('dis:prune-operational-data')->dailyAt('03:45');
Schedule::command('dis:self-check')->everyFiveMinutes();
