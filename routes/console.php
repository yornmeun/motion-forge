<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// housekeeping: stale temp render dirs, old previews, outdated scene segments (see CleanupCommand)
Schedule::command('motionforge:cleanup')->dailyAt('03:30')->withoutOverlapping();
