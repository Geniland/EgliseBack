<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('attendance:process-expired --max=50')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground();

Schedule::command('church:send-weekend-readiness-reminders')
    ->weekends()
    ->at('08:00')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(30);
