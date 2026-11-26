<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('dimensimatic:check-update', function (\App\Services\Automation\UpdateReleaseFeed $feed) {
    try { $feed->checkNow(); } catch (\Throwable $e) { $this->error($e->getMessage()); return 1; }
    return 0;
})->purpose('Check GitHub updates outside web requests.');

Schedule::command('soundon:cleanup')->hourly()->withoutOverlapping();
Schedule::command('soundon:session:health')->everyThirtyMinutes()->withoutOverlapping();
Schedule::command('dimensimatic:auto-run')->everyMinute()->withoutOverlapping();
if (config('automation.schedule_enabled')) {
    Schedule::command('soundon:process-pending --limit=10')->everyTenMinutes()->withoutOverlapping()->onOneServer();
}

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
