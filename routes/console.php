<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('reports:weekly')
    ->weeklyOn(1, '09:00')
    ->timezone('Europe/Moscow');

Schedule::command('recurring:run')
    ->dailyAt('09:00')
    ->timezone('Europe/Moscow');
