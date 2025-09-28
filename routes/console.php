<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule automatic payment processing every 2 minutes
Schedule::command('payments:process-pending')
    ->everyTwoMinutes()
    ->withoutOverlapping()
    ->runInBackground();
