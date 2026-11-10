<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('soundon:cleanup')->hourly()->withoutOverlapping();
Schedule::command('soundon:session:health')->everyThirtyMinutes()->withoutOverlapping();
Schedule::command('dimensimatic:auto-run')->everyMinute()->withoutOverlapping();
if (config('automation.schedule_enabled')) {
    Schedule::command('soundon:process-pending --limit=10')->everyTenMinutes()->withoutOverlapping()->onOneServer();
}

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
