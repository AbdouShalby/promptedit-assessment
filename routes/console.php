<?php

use Illuminate\Support\Facades\Schedule;

// Polling fallback for lost webhooks and lost jobs. Cron starts schedule:run
// once a minute; Laravel repeats this task every 15 seconds inside that minute.
Schedule::command('generations:reconcile')
    ->everyFifteenSeconds()
    ->withoutOverlapping();
